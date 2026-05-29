<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->string('vat_code', 32)->default('standard')->after('vat_exempt');
            $table->string('vat_label')->nullable()->after('vat_code');
            $table->decimal('vat_rate', 5, 2)->default(8.10)->after('vat_label');
            $table->index(['vat_code', 'vat_rate']);
        });

        Schema::table('estimate_lines', function (Blueprint $table) {
            $table->string('vat_code', 32)->default('standard')->after('vat_exempt');
            $table->string('vat_label')->nullable()->after('vat_code');
            $table->decimal('vat_rate', 5, 2)->default(8.10)->after('vat_label');
            $table->index(['vat_code', 'vat_rate']);
        });

        Schema::table('recurring_invoice_lines', function (Blueprint $table) {
            $table->string('vat_code', 32)->default('standard')->after('vat_exempt');
            $table->string('vat_label')->nullable()->after('vat_code');
            $table->decimal('vat_rate', 5, 2)->default(8.10)->after('vat_label');
            $table->index(['vat_code', 'vat_rate']);
        });

        DB::statement("
            UPDATE invoice_lines il
            JOIN invoices i ON i.id = il.invoice_id
            SET
                il.vat_code = CASE WHEN il.vat_exempt = 1 THEN 'exempt' ELSE 'standard' END,
                il.vat_label = CASE WHEN il.vat_exempt = 1 THEN 'MwSt-befreit' ELSE 'Normalsatz' END,
                il.vat_rate = CASE WHEN il.vat_exempt = 1 THEN 0 ELSE i.vat_rate END
        ");

        DB::statement("
            UPDATE estimate_lines el
            JOIN estimates e ON e.id = el.estimate_id
            SET
                el.vat_code = CASE WHEN el.vat_exempt = 1 THEN 'exempt' ELSE 'standard' END,
                el.vat_label = CASE WHEN el.vat_exempt = 1 THEN 'MwSt-befreit' ELSE 'Normalsatz' END,
                el.vat_rate = CASE WHEN el.vat_exempt = 1 THEN 0 ELSE e.vat_rate END
        ");

        DB::statement("
            UPDATE recurring_invoice_lines ril
            JOIN recurring_invoices ri ON ri.id = ril.recurring_invoice_id
            SET
                ril.vat_code = CASE WHEN ril.vat_exempt = 1 THEN 'exempt' ELSE 'standard' END,
                ril.vat_label = CASE WHEN ril.vat_exempt = 1 THEN 'MwSt-befreit' ELSE 'Normalsatz' END,
                ril.vat_rate = CASE WHEN ril.vat_exempt = 1 THEN 0 ELSE ri.vat_rate END
        ");
    }

    public function down(): void
    {
        Schema::table('recurring_invoice_lines', function (Blueprint $table) {
            $table->dropIndex(['vat_code', 'vat_rate']);
            $table->dropColumn(['vat_code', 'vat_label', 'vat_rate']);
        });

        Schema::table('estimate_lines', function (Blueprint $table) {
            $table->dropIndex(['vat_code', 'vat_rate']);
            $table->dropColumn(['vat_code', 'vat_label', 'vat_rate']);
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropIndex(['vat_code', 'vat_rate']);
            $table->dropColumn(['vat_code', 'vat_label', 'vat_rate']);
        });
    }
};
