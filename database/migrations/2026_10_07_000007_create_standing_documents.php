<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standing_documents', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('row_keyword');
            // Path of the document to copy, relative to the Dropbox receipts root.
            $table->string('source_path', 1024);
            $table->string('filename');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table) {
            // The numbered PDF copy in the bookkeeping folder, so it is never filed twice.
            $table->string('dropbox_file_id')->nullable();
            $table->string('dropbox_path', 1024)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['dropbox_file_id', 'dropbox_path']);
        });
        Schema::dropIfExists('standing_documents');
    }
};
