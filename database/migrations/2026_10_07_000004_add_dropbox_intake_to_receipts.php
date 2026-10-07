<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            // A file dropped into the Dropbox inbox can repeat a known receipt; it then gets
            // its own record pointing at the original, so uniqueness moves into the code.
            $table->dropUnique(['content_hash']);
            $table->index('content_hash');
            $table->string('source', 16)->default('upload')->after('id');
            $table->foreignId('duplicate_of_id')->nullable()->after('source')->constrained('receipts')->nullOnDelete();
            $table->index('dropbox_file_id');
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duplicate_of_id');
            $table->dropColumn('source');
            $table->dropIndex(['dropbox_file_id']);
            $table->dropIndex(['content_hash']);
            $table->unique('content_hash');
        });
    }
};
