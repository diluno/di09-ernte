<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            // Where the file was before ernte numbered it, so the numbering can be undone.
            $table->string('numbered_from_path', 1024)->nullable()->after('numbered_at');
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn('numbered_from_path');
        });
    }
};
