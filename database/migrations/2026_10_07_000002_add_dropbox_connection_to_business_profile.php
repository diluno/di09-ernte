<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_profile', function (Blueprint $table) {
            $table->text('dropbox_refresh_token')->nullable();
            $table->string('dropbox_account_label')->nullable();
            $table->dateTime('dropbox_connected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('business_profile', function (Blueprint $table) {
            $table->dropColumn(['dropbox_refresh_token', 'dropbox_account_label', 'dropbox_connected_at']);
        });
    }
};
