<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * One Dropbox file is one receipt. Two inbox scans running at once could register the
 * same file twice; the scan now takes a lock, and the index makes a second row impossible.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('receipts')->whereNotNull('dropbox_file_id')
            ->select('dropbox_file_id')->groupBy('dropbox_file_id')->havingRaw('COUNT(*) > 1')
            ->pluck('dropbox_file_id');

        // Which of two rows is the right one is for the operator to decide, not a migration.
        if ($duplicates->isNotEmpty()) {
            Log::warning('receipts.dropbox_file_id was not made unique: '.$duplicates->count()
                .' Dropbox file(s) are registered more than once ('.$duplicates->take(10)->implode(', ').'). '
                .'Remove the extra receipts in ernte, then run: php artisan migrate:refresh --path=database/migrations/2026_11_11_000001_make_receipt_dropbox_file_ids_unique.php');

            return;
        }

        Schema::table('receipts', function (Blueprint $table) {
            $table->unique('dropbox_file_id');
        });
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropIndex(['dropbox_file_id']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('receipts', 'receipts_dropbox_file_id_unique')) {
            return;
        }

        Schema::table('receipts', function (Blueprint $table) {
            $table->index('dropbox_file_id');
        });
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropUnique(['dropbox_file_id']);
        });
    }
};
