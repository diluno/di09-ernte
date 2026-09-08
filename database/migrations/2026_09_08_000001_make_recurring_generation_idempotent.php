<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->date('recurring_occurrence_on')->nullable()->after('recurring_invoice_id');
        });

        DB::table('invoices')
            ->whereNotNull('recurring_invoice_id')
            ->whereNotNull('period_start')
            ->orderBy('id')
            ->get(['id', 'recurring_invoice_id', 'period_start'])
            ->groupBy(fn ($invoice) => $invoice->recurring_invoice_id.'|'.$invoice->period_start)
            ->each(function ($duplicates) {
                DB::table('invoices')
                    ->where('id', $duplicates->first()->id)
                    ->update(['recurring_occurrence_on' => $duplicates->first()->period_start]);
            });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unique(
                ['recurring_invoice_id', 'recurring_occurrence_on'],
                'invoices_recurring_occurrence_unique',
            );
        });

        DB::statement("ALTER TABLE invoice_events MODIFY COLUMN kind ENUM('created','sent','reminded','paid','pdf_generated','voided','overdue_stamped','recurring_autosend_skipped','recurring_autosend_failed','reminders_paused','reminders_resumed') NOT NULL");
    }

    public function down(): void
    {
        DB::table('invoice_events')
            ->where('kind', 'recurring_autosend_failed')
            ->update(['kind' => 'recurring_autosend_skipped']);

        DB::statement("ALTER TABLE invoice_events MODIFY COLUMN kind ENUM('created','sent','reminded','paid','pdf_generated','voided','overdue_stamped','recurring_autosend_skipped','reminders_paused','reminders_resumed') NOT NULL");

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_recurring_occurrence_unique');
            $table->dropColumn('recurring_occurrence_on');
        });
    }
};
