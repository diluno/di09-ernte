<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Language for client-facing output (mails, PDFs, QR bill). Set per client,
 * copied onto each invoice/estimate when it is created so a document keeps
 * the language it was sent in.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['clients', 'invoices', 'estimates'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('locale', 2)->default('de');
            });
        }
    }

    public function down(): void
    {
        foreach (['clients', 'invoices', 'estimates'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('locale');
            });
        }
    }
};
