<?php

namespace App\Services\Invoicing;

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\InvoiceLine;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\VatRate;
use App\Support\LineTotals;
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
     * @return array{subtotal_rappen: int, vat_rappen: int, rounding_rappen: int, total_rappen: int}
     */
    public static function computeTotals(array $lineAmounts, float $vatRate): array
    {
        return LineTotals::compute($lineAmounts, $vatRate);
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

        $groups = $eligible->groupBy(fn (TimeEntry $e) => $e->description !== ''
            ? $e->description
            : ($e->task_id ? ('Task #'.$e->task_id) : ('Entry #'.$e->id)));

        $lines = [];
        foreach ($groups as $description => $bucket) {
            /** @var Collection<int, TimeEntry> $bucket */
            $hours = round($bucket->sum(fn (TimeEntry $e) => $e->duration_seconds / 3600), 2);
            $rate = (int) ($bucket->first()->project->rate_rappen ?? 0);
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
        string $periodStart,
        string $periodEnd,
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
            $taxDate = $taxDate ?: $periodEnd;
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

            $lineAmounts = [];
            $sort = 0;
            foreach ($lines as $line) {
                $hours = round((float) $line['hours'], 2);
                $rate = (int) $line['rate_rappen'];
                $amount = (int) round($hours * $rate);           // recompute — ignore any submitted amount

                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'description' => (string) $line['description'],
                    'hours' => $hours,
                    'rate_rappen' => $rate,
                    'amount_rappen' => $amount,
                    'sort_order' => $sort++,
                ]);

                $lineAmounts[] = $amount;
            }

            $totals = self::computeTotals($lineAmounts, (float) $invoice->vat_rate);
            $invoice->subtotal_rappen = $totals['subtotal_rappen'];
            $invoice->vat_rappen = $totals['vat_rappen'];
            $invoice->rounding_rappen = $totals['rounding_rappen'];
            $invoice->total_rappen = $totals['total_rappen'];
            $invoice->save();

            if (! empty($entryIds)) {
                TimeEntry::whereIn('id', $entryIds)
                    ->whereNull('invoice_id')
                    ->where('billable', true)
                    ->update(['invoice_id' => $invoice->id]);
            }

            InvoiceEvent::create([
                'invoice_id' => $invoice->id,
                'kind' => 'created',
                'occurred_at' => now(),
                'payload' => [
                    'period' => ['start' => $periodStart, 'end' => $periodEnd],
                    'entries_count' => count($entryIds),
                ],
            ]);

            return $invoice->fresh(['lines', 'events']);
        });
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
