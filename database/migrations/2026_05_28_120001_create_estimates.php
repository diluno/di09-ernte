<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimates', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->date('issued_on')->nullable();
            $table->date('valid_until')->nullable();
            $table->enum('status', ['draft', 'sent', 'accepted', 'declined'])->default('draft');
            $table->string('currency', 3)->default('CHF');
            $table->decimal('vat_rate', 5, 2)->default(8.10);
            $table->unsignedBigInteger('subtotal_rappen')->default(0);
            $table->unsignedBigInteger('vat_rappen')->default(0);
            $table->unsignedBigInteger('total_rappen')->default(0);
            $table->text('notes')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->foreignId('converted_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('pdf_path')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('client_id');
            $table->index('valid_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimates');
    }
};
