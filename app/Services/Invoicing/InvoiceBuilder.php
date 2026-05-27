<?php

namespace App\Services\Invoicing;

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\InvoiceLine;
use App\Models\Project;
use App\Models\TimeEntry;
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
        $taxable = 0;
        $exempt = 0;
        foreach ($lineAmounts as $i => $amt) {
            if (!empty($vatExempts[$i])) {
                $exempt += $amt;
            } else {
                $taxable += $amt;
            }
        }
        $subtotal = $taxable + $exempt;
        $vat = (int) round($taxable * $vatRate / 100);
        return [
            'subtotal_rappen' => $subtotal,
            'vat_rappen'      => $vat,
            'total_rappen'    => $subtotal + $vat,
        ];
    }

    /**
     * Build a draft invoice from selected time entries.
     */
    public function buildDraftFromEntries(
        Client $client,
        ?Project $project,
        Collection $entries,
        string $periodStart,
        string $periodEnd,
    ): Invoice {
        return DB::transaction(function () use ($client, $project, $entries, $periodStart, $periodEnd) {
            $profile = BusinessProfile::current();

            // Filter to billable, unbilled entries; restrict to the optional project.
            $eligible = $entries
                ->filter(fn (TimeEntry $e) => $e->billable && $e->invoice_id === null)
                ->when($project, fn ($c) => $c->filter(fn (TimeEntry $e) => $e->project_id === $project->id))
                ->values();

            // Group by description (or task fallback if description empty).
            $groups = $eligible->groupBy(fn (TimeEntry $e) => $e->description !== ''
                ? $e->description
                : ('Task #' . $e->task_id));

            // Allocate number and create the header.
            $year = (int) date('Y');
            $number = $this->numberer->nextFor($year);

            $invoice = Invoice::create([
                'number' => $number,
                'client_id' => $client->id,
                'project_id' => $project?->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => 'draft',
                'currency' => $profile->default_currency ?? 'CHF',
                'vat_rate' => $profile->default_vat_rate,
                'subtotal_rappen' => 0,
                'vat_rappen' => 0,
                'total_rappen' => 0,
            ]);

            // Now that we have an id, fill the QR reference.
            $invoice->qr_reference = $this->qr->generate($invoice->id);

            // Build lines from groups, collecting amounts and exempt flags for totals.
            $lineAmounts = [];
            $vatExempts  = [];
            $sort = 0;
            foreach ($groups as $description => $bucket) {
                /** @var Collection<int, TimeEntry> $bucket */
                $hours = round($bucket->sum(fn (TimeEntry $e) => $e->duration_seconds / 3600), 2);
                $rate = (int) ($bucket->first()->project->rate_rappen ?? 0);
                $amount = (int) round($hours * $rate);
                $exempt = false; // time entries do not carry vat_exempt yet

                InvoiceLine::create([
                    'invoice_id'  => $invoice->id,
                    'description' => $description,
                    'hours'       => $hours,
                    'rate_rappen' => $rate,
                    'amount_rappen' => $amount,
                    'vat_exempt'  => $exempt,
                    'sort_order'  => $sort++,
                ]);

                $lineAmounts[] = $amount;
                $vatExempts[]  = $exempt;
            }

            $totals = self::computeTotals($lineAmounts, $vatExempts, (float) $invoice->vat_rate);

            $invoice->subtotal_rappen = $totals['subtotal_rappen'];
            $invoice->vat_rappen      = $totals['vat_rappen'];
            $invoice->total_rappen    = $totals['total_rappen'];
            $invoice->save();

            // Attach entries.
            TimeEntry::whereIn('id', $eligible->pluck('id'))->update(['invoice_id' => $invoice->id]);

            // Audit event.
            InvoiceEvent::create([
                'invoice_id' => $invoice->id,
                'kind' => 'created',
                'occurred_at' => now(),
                'payload' => [
                    'period' => ['start' => $periodStart, 'end' => $periodEnd],
                    'entries_count' => $eligible->count(),
                ],
            ]);

            return $invoice->fresh(['lines', 'events']);
        });
    }
}
