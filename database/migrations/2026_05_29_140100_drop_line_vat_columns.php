<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = ['invoice_lines', 'estimate_lines', 'recurring_invoice_lines'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['vat_code', 'vat_rate']);
                $table->dropColumn(['vat_code', 'vat_label', 'vat_rate', 'vat_exempt']);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->boolean('vat_exempt')->default(false);
                $table->string('vat_code', 32)->default('standard');
                $table->string('vat_label')->nullable();
                $table->decimal('vat_rate', 5, 2)->default(8.10);
                $table->index(['vat_code', 'vat_rate']);
            });
        }
    }
};
