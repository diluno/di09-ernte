<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('short_code', 4);
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->default('CH');
            $table->string('vat_id')->nullable();
            $table->unsignedBigInteger('default_rate_rappen')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
