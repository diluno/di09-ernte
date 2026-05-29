<?php

namespace App\Services\Estimating;

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateEvent;
use App\Models\EstimateLine;
use App\Models\Project;
use App\Models\VatRate;
use App\Support\LineTotals;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EstimateBuilder
{
    public function __construct(private EstimateNumberer $numberer) {}

    /**
     * Persist a draft estimate from the user's manually-entered lines.
     * Recomputes every line's amount and the estimate totals server-side
     * (never trusts client math).
     *
     * @param  array<int, array{description:string, hours:float|string, rate_rappen:int, vat_exempt?:bool}>  $lines
     */
    public function createDraft(
        Client $client,
        ?Project $project,
        array $lines,
        ?string $notes = null,
        ?string $title = null,
        Carbon|string|null $taxDate = null,
    ): Estimate {
        return DB::transaction(function () use ($client, $project, $lines, $notes, $title, $taxDate) {
            $profile = BusinessProfile::current();
            $taxDate = $taxDate ?: now()->toDateString();
            $documentRate = VatRate::rateForDate($taxDate);

            $number = $this->numberer->nextFor((int) date('Y'));

            $estimate = Estimate::create([
                'number' => $number,
                'client_id' => $client->id,
                'project_id' => $project?->id,
                'status' => 'draft',
                'currency' => $profile->default_currency ?? 'CHF',
                'vat_rate' => $documentRate,
                'subtotal_rappen' => 0,
                'vat_rappen' => 0,
                'total_rappen' => 0,
                'notes' => $notes,
                'title' => $title,
            ]);

            $lineAmounts = [];
            $sort = 0;
            foreach ($lines as $line) {
                $hours = round((float) $line['hours'], 2);
                $rate = (int) $line['rate_rappen'];
                $amount = (int) round($hours * $rate);           // recompute — ignore any submitted amount

                EstimateLine::create([
                    'estimate_id' => $estimate->id,
                    'description' => (string) $line['description'],
                    'hours' => $hours,
                    'rate_rappen' => $rate,
                    'amount_rappen' => $amount,
                    'sort_order' => $sort++,
                ]);

                $lineAmounts[] = $amount;
            }

            $totals = LineTotals::compute($lineAmounts, (float) $estimate->vat_rate);
            $estimate->subtotal_rappen = $totals['subtotal_rappen'];
            $estimate->vat_rappen = $totals['vat_rappen'];
            $estimate->total_rappen = $totals['total_rappen'];
            $estimate->save();

            EstimateEvent::create([
                'estimate_id' => $estimate->id,
                'kind' => 'created',
                'occurred_at' => now(),
                'payload' => ['lines_count' => count($lines)],
            ]);

            return $estimate->fresh(['lines', 'events']);
        });
    }
}
