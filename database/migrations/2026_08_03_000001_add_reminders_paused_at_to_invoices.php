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
            $table->timestamp('reminders_paused_at')->nullable()->after('sent_at');
        });

        DB::statement("ALTER TABLE invoice_events MODIFY COLUMN kind ENUM('created','sent','reminded','paid','pdf_generated','voided','overdue_stamped','recurring_autosend_skipped','reminders_paused','reminders_resumed') NOT NULL");
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('reminders_paused_at');
        });

        DB::statement("ALTER TABLE invoice_events MODIFY COLUMN kind ENUM('created','sent','reminded','paid','pdf_generated','voided','overdue_stamped','recurring_autosend_skipped') NOT NULL");
    }
};
