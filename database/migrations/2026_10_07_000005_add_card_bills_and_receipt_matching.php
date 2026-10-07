<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statements', function (Blueprint $table) {
            $table->foreignId('bank_line_id')->nullable()->constrained('statement_lines')->nullOnDelete();
            $table->bigInteger('charges_rappen')->nullable();
        });

        Schema::table('statement_lines', function (Blueprint $table) {
            $table->unsignedInteger('position')->nullable();
            $table->dateTime('transacted_at')->nullable();
            $table->bigInteger('original_amount_minor')->nullable();
            $table->char('original_currency', 3)->nullable();
            $table->boolean('no_receipt')->default(false);
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->foreignId('statement_line_id')->nullable()->constrained('statement_lines')->nullOnDelete();
            $table->string('match_state', 16)->nullable();
            $table->string('match_method', 24)->nullable();
            $table->string('match_note', 32)->nullable();
            $table->boolean('match_confident')->default(false);
            $table->dateTime('numbered_at')->nullable();
            $table->boolean('auto_match_disabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('statement_line_id');
            $table->dropColumn(['match_state', 'match_method', 'match_note', 'match_confident', 'numbered_at', 'auto_match_disabled']);
        });
        Schema::table('statement_lines', function (Blueprint $table) {
            $table->dropColumn(['position', 'transacted_at', 'original_amount_minor', 'original_currency', 'no_receipt']);
        });
        Schema::table('statements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_line_id');
            $table->dropColumn('charges_rappen');
        });
    }
};
