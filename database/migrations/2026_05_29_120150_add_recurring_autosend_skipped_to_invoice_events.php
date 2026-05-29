<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE invoice_events MODIFY COLUMN kind ENUM('created','sent','reminded','paid','pdf_generated','voided','overdue_stamped','recurring_autosend_skipped') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE invoice_events MODIFY COLUMN kind ENUM('created','sent','reminded','paid','pdf_generated','voided','overdue_stamped') NOT NULL");
    }
};
