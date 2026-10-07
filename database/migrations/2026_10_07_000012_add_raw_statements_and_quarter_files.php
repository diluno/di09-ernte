<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statements', function (Blueprint $table) {
            // The bank's original file, kept so a quarter can be handed on as one camt file.
            $table->string('raw_path')->nullable();
        });

        // Files ernte writes into a quarter folder for the accountant (the merged camt.053).
        Schema::create('quarter_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('quarter');
            $table->string('kind', 16);
            $table->string('dropbox_file_id')->collation('utf8mb4_bin');
            $table->string('dropbox_path', 1024);
            $table->dateTime('written_at');
            $table->timestamps();

            $table->unique(['year', 'quarter', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quarter_files');
        Schema::table('statements', function (Blueprint $table) {
            $table->dropColumn('raw_path');
        });
    }
};
