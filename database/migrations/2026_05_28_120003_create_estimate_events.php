<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained('estimates')->cascadeOnDelete();
            $table->enum('kind', ['created', 'sent', 'accepted', 'declined', 'converted', 'pdf_generated']);
            $table->dateTime('occurred_at');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['estimate_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_events');
    }
};
