<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The "Belegliste" ernte writes into a month folder for the accountant. Its file id
        // is kept so the list can be replaced when notes or receipts change.
        Schema::create('month_lists', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('dropbox_file_id')->collation('utf8mb4_bin');
            $table->string('dropbox_path', 1024);
            $table->dateTime('written_at');
            $table->timestamps();

            $table->unique(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('month_lists');
    }
};
