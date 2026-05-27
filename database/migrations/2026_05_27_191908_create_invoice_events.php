<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->enum('kind', ['created', 'sent', 'reminded', 'paid', 'pdf_generated', 'voided', 'overdue_stamped']);
            $table->dateTime('occurred_at');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_events');
    }
};
