<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->string('glyph')->default('alt-0');
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->boolean('billable')->default(true);
            $table->boolean('retainer')->default(false);
            $table->unsignedInteger('retainer_hours')->nullable();
            $table->boolean('retainer_resets_monthly')->default(false);
            $table->unsignedInteger('budget_hours')->default(0);
            $table->unsignedBigInteger('budget_amount_rappen')->default(0);
            $table->unsignedBigInteger('rate_rappen')->default(0);
            $table->date('started_on')->nullable();
            $table->date('deadline_on')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
