<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceLine;

/**
 * Read models for recurring invoice schedules. summary()/detail() feed the MCP
 * tools (amounts in francs); indexRow()/indexStats() feed the web list (integer rappen).
 */
class RecurringInvoiceProjections
{
    /** Invoices one schedule generates per year. */
    private const RUNS_PER_YEAR = ['monthly' => 12, 'quarterly' => 4, 'half-yearly' => 2, 'yearly' => 1];

    /**
     * The title the next generated invoice will carry: "{period}" resolved for the
     * next occurrence, exactly as RecurringInvoiceGenerator does it.
     */
    public static function nextTitle(RecurringInvoice $s, ?array $period = null): ?string
    {
        if ($s->title === null) {
            return null;
        }
        $period ??= $s->nextPeriod();

        return $period ? str_replace('{period}', $period['label'], $s->title) : $s->title;
    }

    /** One row of the web list. Expects client (with locale) and lines loaded, and invoices_count. */
    public static function indexRow(RecurringInvoice $s): array
    {
        $paused = $s->isPaused();

        return [
            'id' => $s->id,
            'client' => $s->client->only('id', 'name'),
            'title' => $s->title,
            'resolved_title' => self::nextTitle($s),
            'cadence' => $s->cadence,
            'next_run_on' => $s->next_run_on?->toDateString(),
            // An active schedule whose run date has passed without the daily job generating it.
            'run_late' => ! $paused && $s->next_run_on !== null && $s->next_run_on->lt(today()),
            'auto_send' => (bool) $s->auto_send,
            'paused' => $paused,
            'invoices_count' => (int) $s->invoices_count,
            'subtotal_per_run' => self::subtotalRappen($s), // rappen, excl. VAT
        ];
    }

    /**
     * Stats strip of the web list. Volume is the net (excl. VAT) yearly amount of
     * the active schedules, in integer rappen.
     *
     * @param  iterable<RecurringInvoice>  $schedules  with client and lines loaded
     */
    public static function indexStats(iterable $schedules): array
    {
        $active = collect($schedules)->reject(fn (RecurringInvoice $s) => $s->isPaused());
        $paused = collect($schedules)->count() - $active->count();
        $next = $active->filter(fn (RecurringInvoice $s) => $s->next_run_on !== null)
            ->sortBy(fn (RecurringInvoice $s) => $s->next_run_on->toDateString())
            ->first();

        return [
            'active' => $active->count(),
            'active_auto_send' => $active->filter(fn (RecurringInvoice $s) => $s->auto_send)->count(),
            'paused' => $paused,
            'next_run' => $next ? [
                'on' => $next->next_run_on->toDateString(),
                'client' => $next->client->name,
                'late' => $next->next_run_on->lt(today()),
            ] : null,
            'yearly_rappen' => (int) $active->sum(
                fn (RecurringInvoice $s) => self::subtotalRappen($s) * (self::RUNS_PER_YEAR[$s->cadence] ?? 0)
            ),
        ];
    }

    public static function summary(RecurringInvoice $s): array
    {
        return [
            'id' => $s->id,
            'client' => $s->client?->name,
            'title' => $s->title,
            'cadence' => $s->cadence,
            'next_run_on' => $s->next_run_on?->toDateString(),
            'auto_send' => (bool) $s->auto_send,
            'paused' => $s->isPaused(),
            'subtotal_per_run' => round(self::subtotalRappen($s) / 100, 2),
        ];
    }

    public static function detail(RecurringInvoice $s): array
    {
        $s->loadMissing(['client', 'project', 'lines' => fn ($q) => $q->orderBy('sort_order')]);
        $period = $s->nextPeriod();

        return [
            'id' => $s->id,
            'client' => $s->client?->only('id', 'name'),
            'project' => $s->project?->only('id', 'name'),
            'title' => $s->title,
            'cadence' => $s->cadence,
            'anchor_day' => $s->anchor_day,
            'next_run_on' => $s->next_run_on?->toDateString(),
            // What the next generated invoice will cover and be called.
            'next_invoice' => $period ? [
                'period_start' => $period['start']->toDateString(),
                'period_end' => $period['end']->toDateString(),
                'title' => self::nextTitle($s, $period),
            ] : null,
            'last_generated_on' => $s->last_generated_on?->toDateString(),
            'auto_send' => (bool) $s->auto_send,
            'paused' => $s->isPaused(),
            'currency' => $s->currency,
            // The rate the next invoice will carry (in force at its period end).
            'vat_rate' => $s->nextVatRate(),
            'notes' => $s->notes,
            'recipients' => $s->recipients ?? [],
            'lines' => $s->lines->map(fn (RecurringInvoiceLine $l) => [
                'description' => $l->description,
                'hours' => (float) $l->hours,
                'rate' => round($l->rate_rappen / 100, 2),
                'amount' => round(self::lineRappen($l) / 100, 2),
            ])->values()->all(),
            'subtotal_per_run' => round(self::subtotalRappen($s) / 100, 2),
            'recent_invoices' => $s->invoices()->latest('id')->limit(5)->get()->map(fn (Invoice $i) => [
                'number' => $i->number,
                'status' => $i->status,
                'issued_on' => $i->issued_on?->toDateString(),
                'total' => round($i->total_rappen / 100, 2),
            ])->values()->all(),
        ];
    }

    private static function lineRappen(RecurringInvoiceLine $l): int
    {
        return (int) round(round((float) $l->hours, 2) * (int) $l->rate_rappen);
    }

    private static function subtotalRappen(RecurringInvoice $s): int
    {
        return (int) $s->lines->sum(fn (RecurringInvoiceLine $l) => self::lineRappen($l));
    }
}
