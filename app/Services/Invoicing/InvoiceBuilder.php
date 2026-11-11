<?php

namespace App\Services\Invoicing;

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\VatRate;
use App\Support\LineTotals;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InvoiceBuilder
{
    public function __construct(
        private InvoiceNumberer $numberer,
        private QrReferenceGenerator $qr,
    ) {}

    /**
     * Compute subtotal, VAT, and total from line amounts and the document rate.
     *
     * @param  int[]  $lineAmounts  Amount in rappen for each line.
     * @param  float  $vatRate  VAT rate as a percentage (e.g. 8.10).
     * @param  bool[]  $exemptFlags  Parallel to $lineAmounts; true = no VAT on that line.
     * @return array{subtotal_rappen: int, vat_rappen: int, rounding_rappen: int, total_rappen: int}
     */
    public static function computeTotals(array $lineAmounts, float $vatRate, array $exemptFlags = []): array
    {
        return LineTotals::compute($lineAmounts, $vatRate, $exemptFlags);
    }

    /**
     * The client's finished, billable, not-yet-invoiced time in a period —
     * what the "New invoice" editor and the MCP tool offer for billing.
     *
     * @return Collection<int, TimeEntry>
     */
    public function unbilledEntries(Client $client, ?Project $project, Carbon $start, Carbon $end): Collection
    {
        return $this->billableEntries($client)
            ->with(['project:id,name,code,rate_rappen'])
            ->whereBetween('started_at', [$start, $end])
            ->when($project, fn ($q) => $q->where('project_id', $project->id))
            ->orderBy('started_at')
            ->get();
    }

    /**
     * The same billable, unbilled time as unbilledEntries(), but outside the period —
     * so the editor can point at it when the chosen period comes up empty. Dates are
     * the entries' (Zurich wall-clock) start days.
     *
     * @return array{count: int, first_on: ?string, last_on: ?string}
     */
    public function unbilledOutsidePeriod(Client $client, ?Project $project, Carbon $start, Carbon $end): array
    {
        $row = $this->billableEntries($client)
            ->when($project, fn ($q) => $q->where('project_id', $project->id))
            ->where(fn ($q) => $q->where('started_at', '<', $start)->orWhere('started_at', '>', $end))
            ->selectRaw('COUNT(*) AS n, MIN(started_at) AS first_at, MAX(started_at) AS last_at')
            ->toBase()
            ->first();

        return [
            'count' => (int) ($row->n ?? 0),
            'first_on' => $row?->first_at ? Carbon::parse($row->first_at)->toDateString() : null,
            'last_on' => $row?->last_at ? Carbon::parse($row->last_at)->toDateString() : null,
        ];
    }

    /** Entries that may be linked to a new invoice for $client. */
    private function billableEntries(Client $client): Builder
    {
        return TimeEntry::query()
            ->whereIn('project_id', $client->projects()->select('id'))
            ->where('billable', true)
            ->whereNull('invoice_id')
            ->finished();
    }

    /**
     * Group eligible entries into suggested invoice lines for the Create editor.
     * Pure read — does not persist anything.
     *
     * @return array<int, array{description:string, hours:float, rate_rappen:int, amount_rappen:int, entry_ids:int[]}>
     */
    public function suggestLinesFromEntries(Collection $entries, ?Project $project, Carbon|string|null $taxDate = null): array
    {
        $eligible = $entries
            ->filter(fn (TimeEntry $e) => $e->billable && $e->invoice_id === null)
            ->when($project, fn ($c) => $c->filter(fn (TimeEntry $e) => $e->project_id === $project->id))
            ->values();

        $groups = $eligible->groupBy(function (TimeEntry $entry) {
            $description = $entry->description !== ''
                ? $entry->description
                : ($entry->task_id ? ('Task #'.$entry->task_id) : ('Entry #'.$entry->id));

            return implode('|', [
                $entry->project_id,
                (int) ($entry->project->rate_rappen ?? 0),
                $description,
            ]);
        });

        $lines = [];
        foreach ($groups as $bucket) {
            /** @var Collection<int, TimeEntry> $bucket */
            $first = $bucket->first();
            $description = $first->description !== ''
                ? $first->description
                : ($first->task_id ? ('Task #'.$first->task_id) : ('Entry #'.$first->id));
            $hours = round($bucket->sum(fn (TimeEntry $e) => $e->duration_seconds / 3600), 2);
            $rate = (int) ($first->project->rate_rappen ?? 0);
            $lines[] = [
                'description' => (string) $description,
                'hours' => $hours,
                'rate_rappen' => $rate,
                'amount_rappen' => (int) round($hours * $rate),
                'entry_ids' => $bucket->pluck('id')->all(),
            ];
        }

        return $lines;
    }

    /**
     * Persist a draft invoice from the user's edited lines and the selected entry ids.
     * Recomputes every line's amount and the invoice totals server-side (never trusts client math).
     *
     * @param  array<int, array{description:string, hours:float|string, rate_rappen:int}>  $lines
     * @param  int[]  $entryIds
     */
    public function createDraft(
        Client $client,
        ?Project $project,
        ?string $periodStart,
        ?string $periodEnd,
        array $lines,
        array $entryIds,
        ?string $title = null,
        ?string $notes = null,
        ?float $vatRate = null,
        Carbon|string|null $taxDate = null,
        ?array $recipients = null,
    ): Invoice {
        return DB::transaction(function () use ($client, $project, $periodStart, $periodEnd, $lines, $entryIds, $title, $notes, $vatRate, $taxDate, $recipients) {
            $profile = BusinessProfile::current();
            $taxDate = $taxDate ?: ($periodEnd ?? Carbon::now()->toDateString());
            $documentRate = $vatRate ?? VatRate::rateForDate($taxDate);

            $number = $this->numberer->nextFor((int) date('Y'));

            $invoice = Invoice::create([
                'number' => $number,
                'client_id' => $client->id,
                'project_id' => $project?->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => 'draft',
                'currency' => $profile->default_currency ?? 'CHF',
                'vat_rate' => $documentRate,
                'subtotal_rappen' => 0,
                'vat_rappen' => 0,
                'total_rappen' => 0,
                'title' => $title,
                'notes' => $notes,
                'recipients' => $recipients ?? $client->defaultRecipients(),
            ]);

            $invoice->qr_reference = $this->qr->generate($invoice->id);

            $this->writeLines($invoice, $lines);
            $invoice->save();

            // Callers validate the ids, but the builder is the last line of defence:
            // only this client's finished, billable, unbilled time is ever linked.
            $linked = empty($entryIds) ? 0 : $this->billableEntries($client)
                ->whereIn('id', $entryIds)
                ->update(['invoice_id' => $invoice->id]);

            InvoiceEvent::create([
                'invoice_id' => $invoice->id,
                'kind' => 'created',
                'occurred_at' => now(),
                'payload' => [
                    'period' => ['start' => $periodStart, 'end' => $periodEnd],
                    'entries_count' => $linked,
                ],
            ]);

            return $invoice->fresh(['lines', 'events']);
        });
    }

    /**
     * Apply a partial update to a draft invoice. Only the keys present in $data
     * are touched; passing `lines` replaces the whole line set and recomputes totals.
     *
     * @param  array{title?:?string, notes?:?string, period_start?:?string, period_end?:?string, locale?:string, recipients?:?array, lines?:array}  $data
     */
    public function updateDraft(Invoice $invoice, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data) {
            foreach (['title', 'notes', 'period_start', 'period_end', 'locale', 'recipients'] as $field) {
                if (array_key_exists($field, $data)) {
                    $invoice->{$field} = $data[$field];
                }
            }

            if (! empty($data['lines'])) {
                $this->writeLines($invoice, $data['lines']);
            }

            $invoice->save();

            return $invoice->fresh(['lines', 'events']);
        });
    }

    /**
     * Replace every line on the invoice and restamp its totals (the caller saves).
     * Amounts are always recomputed server-side — a submitted amount is ignored.
     *
     * @param  array<int, array{description:string, hours:float|string, rate_rappen:int, vat_exempt?:bool}>  $lines
     */
    private function writeLines(Invoice $invoice, array $lines): void
    {
        $invoice->lines()->delete();

        $lineAmounts = [];
        $exemptFlags = [];
        $sort = 0;
        foreach ($lines as $line) {
            $hours = round((float) $line['hours'], 2);
            $rate = (int) $line['rate_rappen'];
            $amount = (int) round($hours * $rate);
            $exempt = (bool) ($line['vat_exempt'] ?? false);

            $invoice->lines()->create([
                'description' => (string) $line['description'],
                'hours' => $hours,
                'rate_rappen' => $rate,
                'amount_rappen' => $amount,
                'vat_exempt' => $exempt,
                'sort_order' => $sort++,
            ]);

            $lineAmounts[] = $amount;
            $exemptFlags[] = $exempt;
        }

        $totals = self::computeTotals($lineAmounts, (float) $invoice->vat_rate, $exemptFlags);
        $invoice->subtotal_rappen = $totals['subtotal_rappen'];
        $invoice->vat_rappen = $totals['vat_rappen'];
        $invoice->rounding_rappen = $totals['rounding_rappen'];
        $invoice->total_rappen = $totals['total_rappen'];
    }

    /**
     * Back-compat convenience: auto-group entries and persist (used by existing tests/callers).
     */
    public function buildDraftFromEntries(
        Client $client,
        ?Project $project,
        Collection $entries,
        string $periodStart,
        string $periodEnd,
    ): Invoice {
        $suggested = $this->suggestLinesFromEntries($entries, $project);
        $entryIds = Arr::flatten(array_map(fn ($l) => $l['entry_ids'], $suggested));

        return $this->createDraft($client, $project, $periodStart, $periodEnd, $suggested, $entryIds);
    }
}
