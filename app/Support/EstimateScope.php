<?php

namespace App\Support;

use App\Models\Estimate;
use App\Models\EstimateLine;
use App\Models\EstimateSection;
use Illuminate\Support\Collection;

/**
 * Reads an estimate's lines as ordered groups for display: one group per
 * section, in line order. Lines without a section (every estimate written
 * before sections existed) form an unnamed group, so legacy estimates come
 * out as a single group with no heading. Totals are summed from the stored
 * line amounts; nothing here changes the estimate's own totals.
 */
final class EstimateScope
{
    /**
     * @return list<array{section: ?EstimateSection, heading: ?string, lines: Collection<int, EstimateLine>, hours: float, amount_rappen: int}>
     */
    public static function groups(Estimate $estimate): array
    {
        $estimate->loadMissing(['sections', 'lines']);
        $sections = $estimate->sections->keyBy('id');

        $groups = [];
        $current = null;
        foreach ($estimate->lines->sortBy('sort_order')->values() as $line) {
            $sectionId = $line->estimate_section_id;
            if ($current === null || $current['section_id'] !== $sectionId) {
                if ($current !== null) {
                    $groups[] = $current;
                }
                $current = ['section_id' => $sectionId, 'lines' => []];
            }
            $current['lines'][] = $line;
        }
        if ($current !== null) {
            $groups[] = $current;
        }

        return array_map(function (array $g) use ($sections) {
            /** @var EstimateSection|null $section */
            $section = $g['section_id'] !== null ? $sections->get($g['section_id']) : null;
            $lines = collect($g['lines']);
            $heading = $section?->heading();

            return [
                'section' => $section,
                'heading' => $heading === '' ? null : $heading,
                'lines' => $lines,
                'hours' => round((float) $lines->sum(fn ($l) => (float) $l->hours), 2),
                'amount_rappen' => (int) $lines->sum('amount_rappen'),
            ];
        }, $groups);
    }

    /** True when the estimate has at least one section with a label or title. */
    public static function hasNamedSections(array $groups): bool
    {
        return collect($groups)->contains(fn ($g) => $g['heading'] !== null);
    }

    /** The hourly rate shared by every line, or null for mixed rates / no lines. */
    public static function uniformRateRappen(Estimate $estimate): ?int
    {
        $rates = $estimate->lines->pluck('rate_rappen')->map(fn ($r) => (int) $r)->unique();

        return $rates->count() === 1 ? $rates->first() : null;
    }

    public static function totalHours(Estimate $estimate): float
    {
        return round((float) $estimate->lines->sum(fn ($l) => (float) $l->hours), 2);
    }
}
