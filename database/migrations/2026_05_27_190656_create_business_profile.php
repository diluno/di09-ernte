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
        Schema::create('business_profile', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->default('CH');
            $table->string('uid')->nullable();           // CHE-XXX.XXX.XXX
            $table->string('vat_id')->nullable();        // CHE-XXX.XXX.XXX MWST
            $table->string('iban')->nullable();
            $table->string('qr_iban')->nullable();
            $table->string('email')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('default_currency', 3)->default('CHF');
            $table->decimal('default_vat_rate', 5, 2)->default(8.10);
            $table->string('invoice_number_prefix')->default('');
            $table->unsignedSmallInteger('reminder_days_after_due')->default(7);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_profile');
    }
};
