<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('title')->nullable();   // may contain the literal {period}
            $table->text('notes')->nullable();
            $table->string('currency', 3)->default('CHF');
            $table->decimal('vat_rate', 5, 2)->default(8.10);
            $table->enum('cadence', ['monthly', 'quarterly', 'half-yearly', 'yearly']);
            $table->unsignedTinyInteger('anchor_day');     // 1..31, clamped to month length at generation
            $table->date('next_run_on');
            $table->date('last_generated_on')->nullable();
            $table->boolean('auto_send')->default(false);
            $table->timestamp('paused_at')->nullable();
            $table->timestamps();

            $table->index('next_run_on');
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_invoices');
    }
};
