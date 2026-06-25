<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            // Signed: the adjustment ranges -2..+2 rappen.
            $table->integer('rounding_rappen')->default(0)->after('vat_rappen');
        });
    }

    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn('rounding_rappen');
        });
    }
};
