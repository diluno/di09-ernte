<?php

namespace App\Services\Invoicing;

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\RecurringInvoice;
use App\Models\VatRate;
use App\Support\BillingPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Create, edit, pause and resume recurring invoice schedules. Shared by the
 * web forms and the MCP tools so both apply the same date rules: the next run
 * never lands in the past (no backfill), and the anchor day only changes when
 * the operator moves the next run date.
 */
class RecurringInvoiceSchedules
{
    /**
     * @param  array{client_id:int, project_id?:?int, title?:?string, notes?:?string, cadence:string,
     *               next_run_on:string, vat_rate?:float|string|null, auto_send?:bool, recipients?:?array,
     *               lines:array<int, array{description:string, hours:float|string, rate_rappen:int}>}  $data
     */
    public function create(array $data): RecurringInvoice
    {
        $first = Carbon::parse($data['next_run_on']);
        $nextRun = BillingPeriod::nextRunOnOrAfter($data['cadence'], $first, Carbon::today());

        return DB::transaction(function () use ($data, $first, $nextRun) {
            $client = Client::findOrFail($data['client_id']);
            $schedule = RecurringInvoice::create([
                'client_id' => $client->id,
                'project_id' => $data['project_id'] ?? null,
                'title' => $data['title'] ?? null,
                'notes' => $data['notes'] ?? null,
                'currency' => BusinessProfile::current()->default_currency ?? 'CHF',
                'vat_rate' => $data['vat_rate'] ?? VatRate::rateForDate($nextRun),
                'cadence' => $data['cadence'],
                'anchor_day' => $first->day,
                'next_run_on' => $nextRun->toDateString(),
                'auto_send' => $data['auto_send'] ?? false,
                'recipients' => $data['recipients'] ?? $client->defaultRecipients(),
            ]);
            $this->writeLines($schedule, $data['lines']);

            return $schedule->fresh(['client', 'project', 'lines']);
        });
    }

    /** Replace the schedule with $data (same shape as create; recipients kept unless given). */
    public function update(RecurringInvoice $schedule, array $data): RecurringInvoice
    {
        $first = Carbon::parse($data['next_run_on']);
        $anchorDay = $first->isSameDay($schedule->next_run_on) ? $schedule->anchor_day : $first->day;
        $nextRun = BillingPeriod::nextRunOnOrAfter($data['cadence'], $first, Carbon::today(), $anchorDay);

        return DB::transaction(function () use ($schedule, $data, $anchorDay, $nextRun) {
            $schedule->fill([
                'client_id' => $data['client_id'],
                'project_id' => $data['project_id'] ?? null,
                'title' => $data['title'] ?? null,
                'notes' => $data['notes'] ?? null,
                'vat_rate' => $data['vat_rate'] ?? VatRate::rateForDate($nextRun),
                'cadence' => $data['cadence'],
                'anchor_day' => $anchorDay,
                'next_run_on' => $nextRun->toDateString(),
                'auto_send' => $data['auto_send'] ?? false,
            ]);
            if (array_key_exists('recipients', $data)) {
                $schedule->recipients = $data['recipients'];
            }
            $schedule->save();

            $schedule->lines()->delete();
            $this->writeLines($schedule, $data['lines']);

            return $schedule->fresh(['client', 'project', 'lines']);
        });
    }

    public function pause(RecurringInvoice $schedule): RecurringInvoice
    {
        $schedule->update(['paused_at' => now()]);

        return $schedule;
    }

    /** Snap the next run forward so resuming never backfills missed periods. */
    public function resume(RecurringInvoice $schedule): RecurringInvoice
    {
        $next = BillingPeriod::nextRunOnOrAfter(
            $schedule->cadence,
            Carbon::parse($schedule->next_run_on),
            Carbon::today(),
            $schedule->anchor_day,
        );
        $schedule->update(['paused_at' => null, 'next_run_on' => $next->toDateString()]);

        return $schedule;
    }

    /** @param array<int, array{description:string, hours:float|string, rate_rappen:int}> $lines */
    private function writeLines(RecurringInvoice $schedule, array $lines): void
    {
        $sort = 0;
        foreach ($lines as $line) {
            $schedule->lines()->create([
                'description' => (string) $line['description'],
                'hours' => round((float) $line['hours'], 2),
                'rate_rappen' => (int) $line['rate_rappen'],
                'sort_order' => $sort++,
            ]);
        }
    }
}
