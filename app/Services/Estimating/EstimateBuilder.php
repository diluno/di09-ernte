<?php

namespace App\Services\Estimating;

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateEvent;
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
     * Pass either flat `$lines` (ungrouped, as before) or `$sections`, each with
     * its own `lines`. Sections win when both are given.
     *
     * @param  array<int, array{title?:?string, description?:?string, hours:float|string, rate_rappen:int}>  $lines
     * @param  array<int, array{label?:?string, title?:?string, lines:array}>|null  $sections
     * @param  string[]|null  $assumptions
     */
    public function createDraft(
        Client $client,
        ?Project $project,
        array $lines = [],
        ?string $notes = null,
        ?string $title = null,
        Carbon|string|null $taxDate = null,
        ?array $recipients = null,
        ?array $sections = null,
        ?array $assumptions = null,
    ): Estimate {
        return DB::transaction(function () use ($client, $project, $lines, $notes, $title, $taxDate, $recipients, $sections, $assumptions) {
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
                'assumptions' => self::cleanAssumptions($assumptions),
                'title' => $title,
                'recipients' => $recipients ?? $client->defaultRecipients(),
            ]);

            $count = $this->writeLines($estimate, $sections ?? [['lines' => $lines, 'implicit' => true]]);
            $estimate->save();

            EstimateEvent::create([
                'estimate_id' => $estimate->id,
                'kind' => 'created',
                'occurred_at' => now(),
                'payload' => ['lines_count' => $count],
            ]);

            return $estimate->fresh(['lines', 'sections', 'events']);
        });
    }

    /**
     * Apply a partial update to a draft estimate. Only the keys present in $data
     * are touched; passing `lines` or `sections` replaces the whole line set
     * (and any sections) and recomputes totals.
     *
     * @param  array{client_id?:int, project_id?:?int, title?:?string, notes?:?string, assumptions?:?array, recipients?:?array, lines?:array, sections?:array}  $data
     */
    public function updateDraft(Estimate $estimate, array $data): Estimate
    {
        return DB::transaction(function () use ($estimate, $data) {
            foreach (['client_id', 'project_id', 'title', 'notes', 'recipients'] as $field) {
                if (array_key_exists($field, $data)) {
                    $estimate->{$field} = $data[$field];
                }
            }

            if (array_key_exists('assumptions', $data)) {
                $estimate->assumptions = self::cleanAssumptions($data['assumptions']);
            }

            if (! empty($data['sections'])) {
                $this->writeLines($estimate, $data['sections']);
            } elseif (! empty($data['lines'])) {
                $this->writeLines($estimate, [['lines' => $data['lines'], 'implicit' => true]]);
            }

            $estimate->save();

            return $estimate->fresh(['lines', 'sections', 'events']);
        });
    }

    /**
     * Replace every section and line on the estimate and restamp its totals
     * (the caller saves). An `implicit` group writes its lines without a
     * section row, which is how ungrouped estimates are stored.
     *
     * @param  array<int, array{label?:?string, title?:?string, lines:array, implicit?:bool}>  $groups
     * @return int  number of lines written
     */
    private function writeLines(Estimate $estimate, array $groups): int
    {
        $estimate->lines()->delete();
        $estimate->sections()->delete();

        $lineAmounts = [];
        $lineSort = 0;
        $sectionSort = 0;

        foreach ($groups as $group) {
            $sectionId = null;
            if (empty($group['implicit'])) {
                $sectionId = $estimate->sections()->create([
                    'label' => self::nullIfBlank($group['label'] ?? null),
                    'title' => self::nullIfBlank($group['title'] ?? null),
                    'sort_order' => $sectionSort++,
                ])->id;
            }

            foreach ($group['lines'] ?? [] as $line) {
                $hours = round((float) $line['hours'], 2);
                $rate = (int) $line['rate_rappen'];
                $amount = (int) round($hours * $rate);   // recompute — ignore any submitted amount

                $estimate->lines()->create([
                    'estimate_section_id' => $sectionId,
                    'title' => self::nullIfBlank($line['title'] ?? null),
                    // The column is NOT NULL; a titled line may have no description.
                    'description' => trim((string) ($line['description'] ?? '')),
                    'hours' => $hours,
                    'rate_rappen' => $rate,
                    'amount_rappen' => $amount,
                    'sort_order' => $lineSort++,
                ]);

                $lineAmounts[] = $amount;
            }
        }

        $totals = LineTotals::compute($lineAmounts, (float) $estimate->vat_rate);
        $estimate->subtotal_rappen = $totals['subtotal_rappen'];
        $estimate->vat_rappen = $totals['vat_rappen'];
        $estimate->rounding_rappen = $totals['rounding_rappen'];
        $estimate->total_rappen = $totals['total_rappen'];

        return count($lineAmounts);
    }

    /** @return string[]|null  trimmed, blank entries dropped; null when empty */
    private static function cleanAssumptions(?array $assumptions): ?array
    {
        $clean = array_values(array_filter(
            array_map(fn ($a) => trim((string) $a), $assumptions ?? []),
            fn ($a) => $a !== '',
        ));

        return $clean === [] ? null : $clean;
    }

    private static function nullIfBlank(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
