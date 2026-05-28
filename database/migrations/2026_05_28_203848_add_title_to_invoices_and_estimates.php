<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('title')->nullable()->after('notes');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->string('title')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('title');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
