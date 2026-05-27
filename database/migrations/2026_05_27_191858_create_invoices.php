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
        DB::statement(
            "ALTER TABLE time_entries ADD CONSTRAINT time_entries_invoice_id_fk " .
            "FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL"
        );
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE time_entries DROP FOREIGN KEY time_entries_invoice_id_fk");
        Schema::dropIfExists('invoices');
    }
};
