<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_rates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32);
            $table->string('label');
            $table->decimal('rate', 5, 2);
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['code', 'valid_from']);
            $table->index(['is_default', 'valid_from']);
            $table->index(['code', 'valid_from', 'valid_until']);
        });

        $now = now();
        DB::table('vat_rates')->insert([
            [
                'code' => 'exempt',
                'label' => 'MwSt-befreit',
                'rate' => 0.00,
                'valid_from' => '1900-01-01',
                'valid_until' => null,
                'is_default' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'standard',
                'label' => 'Normalsatz',
                'rate' => 7.70,
                'valid_from' => '2018-01-01',
                'valid_until' => '2023-12-31',
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'reduced',
                'label' => 'Reduzierter Satz',
                'rate' => 2.50,
                'valid_from' => '2018-01-01',
                'valid_until' => '2023-12-31',
                'is_default' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'special',
                'label' => 'Sondersatz Beherbergung',
                'rate' => 3.70,
                'valid_from' => '2018-01-01',
                'valid_until' => '2023-12-31',
                'is_default' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'standard',
                'label' => 'Normalsatz',
                'rate' => 8.10,
                'valid_from' => '2024-01-01',
                'valid_until' => null,
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'reduced',
                'label' => 'Reduzierter Satz',
                'rate' => 2.60,
                'valid_from' => '2024-01-01',
                'valid_until' => null,
                'is_default' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'special',
                'label' => 'Sondersatz Beherbergung',
                'rate' => 3.80,
                'valid_from' => '2024-01-01',
                'valid_until' => null,
                'is_default' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_rates');
    }
};
