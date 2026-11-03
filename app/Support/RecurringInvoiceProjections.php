<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceLine;
use Illuminate\Support\Carbon;

/** Read models for recurring invoice schedules (MCP tools). Amounts in francs. */
class RecurringInvoiceProjections
{
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
        $locale = $s->client?->locale ?? DocumentLocale::DEFAULT;
        $period = $s->next_run_on ? BillingPeriod::for($s->cadence, Carbon::parse($s->next_run_on), $s->anchor_day, $locale) : null;

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
                'title' => $s->title !== null ? str_replace('{period}', $period['label'], $s->title) : null,
            ] : null,
            'last_generated_on' => $s->last_generated_on?->toDateString(),
            'auto_send' => (bool) $s->auto_send,
            'paused' => $s->isPaused(),
            'currency' => $s->currency,
            'vat_rate' => (float) $s->vat_rate,
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
