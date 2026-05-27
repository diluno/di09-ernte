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

            // Build lines from groups.
            $subtotal = 0;
            $sort = 0;
            foreach ($groups as $description => $bucket) {
                /** @var Collection<int, TimeEntry> $bucket */
                $hours = round($bucket->sum(fn (TimeEntry $e) => $e->duration_seconds / 3600), 2);
                $rate = (int) ($bucket->first()->project->rate_rappen ?? 0);
                $amount = (int) round($hours * $rate);

                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'description' => $description,
                    'hours' => $hours,
                    'rate_rappen' => $rate,
                    'amount_rappen' => $amount,
                    'vat_exempt' => false,
                    'sort_order' => $sort++,
                ]);

                $subtotal += $amount;
            }

            $vat = (int) round($subtotal * ((float) $invoice->vat_rate) / 100);
            $total = $subtotal + $vat;

            $invoice->subtotal_rappen = $subtotal;
            $invoice->vat_rappen = $vat;
            $invoice->total_rappen = $total;
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
