<?php

namespace App\Services\Invoicing;

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\InvoiceLine;
use App\Models\Project;
use App\Models\TimeEntry;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InvoiceBuilder
{
    public function __construct(
        private InvoiceNumberer $numberer,
        private QrReferenceGenerator $qr,
    ) {}

    /**
     * Compute subtotal, VAT, and total from arrays of line amounts and exempt flags.
     *
     * Exempt lines are included in the subtotal but excluded from the VAT base.
     *
     * @param  int[]   $lineAmounts  Amount in rappen for each line.
     * @param  bool[]  $vatExempts   Parallel exempt flag for each line.
     * @param  float   $vatRate      VAT rate as a percentage (e.g. 8.10).
     * @return array{subtotal_rappen: int, vat_rappen: int, total_rappen: int}
     */
    public static function computeTotals(array $lineAmounts, array $vatExempts, float $vatRate): array
    {
        return \App\Support\LineTotals::compute($lineAmounts, $vatExempts, $vatRate);
    }

    /**
     * Group eligible entries into suggested invoice lines for the Create editor.
     * Pure read — does not persist anything.
     *
     * @return array<int, array{description:string, hours:float, rate_rappen:int, amount_rappen:int, vat_exempt:bool, entry_ids:int[]}>
     */
    public function suggestLinesFromEntries(Collection $entries, ?Project $project): array
    {
        $eligible = $entries
            ->filter(fn (TimeEntry $e) => $e->billable && $e->invoice_id === null)
            ->when($project, fn ($c) => $c->filter(fn (TimeEntry $e) => $e->project_id === $project->id))
            ->values();

        $groups = $eligible->groupBy(fn (TimeEntry $e) => $e->description !== ''
            ? $e->description
            : ($e->task_id ? ('Task #' . $e->task_id) : ('Entry #' . $e->id)));

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
                'vat_exempt' => false,
                'entry_ids' => $bucket->pluck('id')->all(),
            ];
        }

        return $lines;
    }

    /**
     * Persist a draft invoice from the user's edited lines and the selected entry ids.
     * Recomputes every line's amount and the invoice totals server-side (never trusts client math).
     *
     * @param  array<int, array{description:string, hours:float|string, rate_rappen:int, vat_exempt?:bool}>  $lines
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
    ): Invoice {
        return DB::transaction(function () use ($client, $project, $periodStart, $periodEnd, $lines, $entryIds, $title, $notes, $vatRate) {
            $profile = BusinessProfile::current();

            $number = $this->numberer->nextFor((int) date('Y'));

            $invoice = Invoice::create([
                'number' => $number,
                'client_id' => $client->id,
                'project_id' => $project?->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => 'draft',
                'currency' => $profile->default_currency ?? 'CHF',
                'vat_rate' => $vatRate ?? $profile->default_vat_rate,
                'subtotal_rappen' => 0,
                'vat_rappen' => 0,
                'total_rappen' => 0,
                'title' => $title,
                'notes' => $notes,
            ]);

            $invoice->qr_reference = $this->qr->generate($invoice->id);

            $lineAmounts = [];
            $vatExempts  = [];
            $sort = 0;
            foreach ($lines as $line) {
                $hours = round((float) $line['hours'], 2);
                $rate = (int) $line['rate_rappen'];
                $amount = (int) round($hours * $rate);           // recompute — ignore any submitted amount
                $exempt = (bool) ($line['vat_exempt'] ?? false);

                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'description' => (string) $line['description'],
                    'hours' => $hours,
                    'rate_rappen' => $rate,
                    'amount_rappen' => $amount,
                    'vat_exempt' => $exempt,
                    'sort_order' => $sort++,
                ]);

                $lineAmounts[] = $amount;
                $vatExempts[]  = $exempt;
            }

            $totals = self::computeTotals($lineAmounts, $vatExempts, (float) $invoice->vat_rate);
            $invoice->subtotal_rappen = $totals['subtotal_rappen'];
            $invoice->vat_rappen      = $totals['vat_rappen'];
            $invoice->total_rappen    = $totals['total_rappen'];
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
