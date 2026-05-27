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
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('description')->default('');
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->boolean('billable')->default(true);
            $table->unsignedBigInteger('invoice_id')->nullable(); // FK added in Task 5
            $table->timestamps();

            $table->index('project_id');
            $table->index('task_id');
            $table->index('invoice_id');
            $table->index(['user_id', 'started_at']);
        });

        // Generated column + unique enforces the "one running entry per user" invariant.
        // NULLs are distinct in MariaDB composite UNIQUE indexes, so multiple finished
        // rows (is_running = NULL) coexist freely.
        DB::statement(
            "ALTER TABLE time_entries ADD COLUMN is_running TINYINT GENERATED ALWAYS AS " .
            "(CASE WHEN ended_at IS NULL THEN 1 ELSE NULL END) STORED"
        );
        DB::statement("ALTER TABLE time_entries ADD UNIQUE KEY user_running (user_id, is_running)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
