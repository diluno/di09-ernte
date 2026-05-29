<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only the standard rate is used. Drop the other codes' rows so that,
        // once `code` is gone, no two rows share a validity window.
        DB::table('vat_rates')->where('code', '!=', 'standard')->delete();

        Schema::table('vat_rates', function (Blueprint $table) {
            $table->dropUnique(['code', 'valid_from']);
            $table->dropIndex(['is_default', 'valid_from']);
            $table->dropIndex(['code', 'valid_from', 'valid_until']);
            $table->dropColumn(['code', 'label', 'is_default']);
        });

        Schema::table('vat_rates', function (Blueprint $table) {
            $table->unique('valid_from');
        });
    }

    public function down(): void
    {
        Schema::table('vat_rates', function (Blueprint $table) {
            $table->dropUnique(['valid_from']);
            $table->string('code', 32)->default('standard')->after('id');
            $table->string('label')->default('Normalsatz')->after('code');
            $table->boolean('is_default')->default(false)->after('valid_until');
            $table->unique(['code', 'valid_from']);
            $table->index(['is_default', 'valid_from']);
            $table->index(['code', 'valid_from', 'valid_until']);
        });

        DB::table('vat_rates')->update(['is_default' => true]);
    }
};
