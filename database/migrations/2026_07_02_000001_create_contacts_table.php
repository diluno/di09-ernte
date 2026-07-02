<?php
// database/migrations/2026_07_02_000001_create_contacts_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('role')->nullable();
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Backfill: one default contact per client that had an email.
        DB::table('clients')->whereNotNull('email')->where('email', '!=', '')->orderBy('id')
            ->each(function ($client) {
                DB::table('contacts')->insert([
                    'client_id' => $client->id,
                    'name' => $client->contact_name ?: $client->name,
                    'email' => $client->email,
                    'role' => null,
                    'is_default' => true,
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['contact_name', 'email']);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
        });
        Schema::dropIfExists('contacts');
    }
};
