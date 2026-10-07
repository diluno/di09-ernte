<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dropbox file IDs are case-sensitive ("…AAAAAAAJFWg" and "…AAAAAAAJFwg" are two files),
 * but the columns compared them without regard to case, so a new file could be taken for
 * one ernte already knew and was never picked up from the inbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->string('dropbox_file_id')->nullable()->collation('utf8mb4_bin')->change();
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('dropbox_file_id')->nullable()->collation('utf8mb4_bin')->change();
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->string('dropbox_file_id')->nullable()->collation('utf8mb4_unicode_ci')->change();
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('dropbox_file_id')->nullable()->collation('utf8mb4_unicode_ci')->change();
        });
    }
};
