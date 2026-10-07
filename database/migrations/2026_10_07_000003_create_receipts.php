<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->string('original_name');
            $table->string('filename')->nullable();
            $table->char('content_hash', 64)->unique();
            $table->string('original_mime', 64);
            $table->unsignedInteger('size_bytes');
            $table->string('local_path')->nullable();

            $table->string('extraction_status', 16)->default('pending');
            $table->string('extraction_error')->nullable();
            $table->string('vendor')->nullable();
            $table->date('document_date')->nullable();
            $table->bigInteger('total_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->json('amounts')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('payment_method', 16)->nullable();
            $table->string('confidence', 16)->nullable();
            $table->json('extraction')->nullable();
            $table->longText('text_layer')->nullable();
            $table->boolean('fields_edited')->default(false);

            $table->unsignedSmallInteger('target_year')->nullable();
            $table->unsignedTinyInteger('target_month')->nullable();
            $table->boolean('target_edited')->default(false);

            $table->string('filing_status', 16)->default('pending');
            $table->string('filing_error')->nullable();
            $table->string('dropbox_file_id')->nullable();
            $table->string('dropbox_path', 1024)->nullable();
            $table->dateTime('filed_at')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['target_year', 'target_month']);
            $table->index('filing_status');
            $table->index('extraction_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
