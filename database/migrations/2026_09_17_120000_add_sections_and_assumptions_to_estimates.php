<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured estimates: lines can be grouped into named sections ("Bündel 1 —
 * Regionale Webapp") and carry a short title separate from the description;
 * the estimate gains a list of assumptions ("Grundlagen der Schätzung").
 *
 * Purely additive. Existing lines keep estimate_section_id = null and
 * title = null and render exactly as before, so no data is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained('estimates')->cascadeOnDelete();
            $table->string('label', 120)->nullable();
            $table->string('title', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['estimate_id', 'sort_order']);
        });

        Schema::table('estimate_lines', function (Blueprint $table) {
            $table->foreignId('estimate_section_id')->nullable()->after('estimate_id')
                ->constrained('estimate_sections')->nullOnDelete();
            $table->string('title', 255)->nullable()->after('estimate_section_id');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->json('assumptions')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn('assumptions');
        });

        Schema::table('estimate_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('estimate_section_id');
            $table->dropColumn('title');
        });

        Schema::dropIfExists('estimate_sections');
    }
};
