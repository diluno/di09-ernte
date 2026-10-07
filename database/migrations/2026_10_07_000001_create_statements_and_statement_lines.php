<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statements', function (Blueprint $table) {
            $table->id();
            $table->string('source', 16);
            $table->string('account_iban', 34);
            $table->string('message_id');
            $table->string('statement_ref');
            $table->unsignedInteger('sequence_number')->nullable();
            $table->date('from_date');
            $table->date('to_date');
            $table->bigInteger('opening_balance_rappen')->nullable();
            $table->bigInteger('closing_balance_rappen')->nullable();
            $table->string('original_filename');
            $table->timestamps();

            $table->unique(['source', 'account_iban', 'statement_ref']);
            $table->index('to_date');
        });

        Schema::create('statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('statement_id')->constrained('statements')->cascadeOnDelete();
            $table->string('source', 16);
            $table->string('bank_ref');
            $table->unsignedInteger('entry_index');
            $table->date('booked_on');
            $table->date('value_on')->nullable();
            $table->boolean('is_credit');
            $table->unsignedBigInteger('amount_rappen');
            $table->char('currency', 3);
            $table->boolean('is_reversal')->default(false);
            $table->boolean('is_fee')->default(false);
            $table->string('bank_tx_code', 8)->nullable();
            $table->text('description')->nullable();
            $table->text('remittance_text')->nullable();
            $table->string('creditor_reference', 35)->nullable();
            $table->string('counterparty_name')->nullable();
            $table->json('transactions')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('match_state', 16)->nullable();
            $table->string('match_method', 24)->nullable();
            $table->string('match_note', 32)->nullable();
            $table->boolean('marked_invoice_paid')->default(false);
            $table->boolean('auto_match_disabled')->default(false);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['source', 'bank_ref']);
            $table->index('booked_on');
            $table->index('match_state');
        });

        DB::statement("ALTER TABLE invoice_events MODIFY COLUMN kind ENUM('created','sent','reminded','paid','pdf_generated','voided','overdue_stamped','recurring_autosend_skipped','recurring_autosend_failed','reminders_paused','reminders_resumed','payment_matched','reopened') NOT NULL");
    }

    public function down(): void
    {
        DB::table('invoice_events')->whereIn('kind', ['payment_matched', 'reopened'])->delete();
        DB::statement("ALTER TABLE invoice_events MODIFY COLUMN kind ENUM('created','sent','reminded','paid','pdf_generated','voided','overdue_stamped','recurring_autosend_skipped','recurring_autosend_failed','reminders_paused','reminders_resumed') NOT NULL");

        Schema::dropIfExists('statement_lines');
        Schema::dropIfExists('statements');
    }
};
