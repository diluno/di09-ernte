<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();
            $table->enum('status', ['draft', 'sent', 'paid', 'void'])->default('draft');
            $table->string('currency', 3)->default('CHF');
            $table->decimal('vat_rate', 5, 2)->default(8.10);
            $table->unsignedBigInteger('subtotal_rappen')->default(0);
            $table->unsignedBigInteger('vat_rappen')->default(0);
            $table->unsignedBigInteger('total_rappen')->default(0);
            $table->text('notes')->nullable();
            $table->string('qr_reference', 27)->nullable()->unique();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('client_id');
            $table->index('due_on');
        });

        // Wire the deferred FK from time_entries.invoice_id (added unconstrained in Task 4).
        $driver = DB::getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // MySQL/MariaDB supports ADD CONSTRAINT on an existing table.
            DB::statement(
                "ALTER TABLE time_entries ADD CONSTRAINT time_entries_invoice_id_fk " .
                "FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL"
            );
        } else {
            // SQLite cannot add FK constraints via ALTER TABLE; rebuild the table.
            // Disable FK checks during the rebuild to avoid spurious constraint errors.
            DB::statement('PRAGMA foreign_keys = OFF');

            Schema::create('time_entries_new', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
                $table->string('description')->default('');
                $table->dateTime('started_at');
                $table->dateTime('ended_at')->nullable();
                $table->boolean('billable')->default(true);
                $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
                $table->timestamps();

                $table->index('project_id');
                $table->index('task_id');
                $table->index('invoice_id');
                $table->index(['user_id', 'started_at']);
            });

            // Recreate the generated column and unique index for "one running entry per user".
            DB::statement(
                "ALTER TABLE time_entries_new ADD COLUMN is_running INTEGER GENERATED ALWAYS AS " .
                "(CASE WHEN ended_at IS NULL THEN 1 ELSE NULL END) STORED"
            );
            // Drop the old index (global namespace in SQLite) before recreating on the new table.
            DB::statement("DROP INDEX IF EXISTS user_running");
            DB::statement("CREATE UNIQUE INDEX user_running ON time_entries_new (user_id, is_running)");

            // Copy existing data.
            DB::statement(
                "INSERT INTO time_entries_new " .
                "(id, user_id, project_id, task_id, description, started_at, ended_at, billable, invoice_id, created_at, updated_at) " .
                "SELECT id, user_id, project_id, task_id, description, started_at, ended_at, billable, invoice_id, created_at, updated_at " .
                "FROM time_entries"
            );

            Schema::drop('time_entries');
            DB::statement('ALTER TABLE time_entries_new RENAME TO time_entries');

            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE time_entries DROP FOREIGN KEY time_entries_invoice_id_fk");
        }
        Schema::dropIfExists('invoices');
    }
};
