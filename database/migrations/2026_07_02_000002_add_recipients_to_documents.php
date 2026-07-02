<?php

use App\Models\Client;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoices', 'estimates', 'recurring_invoices'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->json('recipients')->nullable()->after('client_id');
            });
        }

        // Backfill each document from its client's default contacts.
        foreach (['invoices', 'estimates', 'recurring_invoices'] as $table) {
            DB::table($table)->select('id', 'client_id')->orderBy('id')->each(function ($row) use ($table) {
                $client = Client::find($row->client_id);
                if (! $client) {
                    return;
                }
                DB::table($table)->where('id', $row->id)
                    ->update(['recipients' => json_encode($client->defaultRecipients())]);
            });
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'estimates', 'recurring_invoices'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('recipients'));
        }
    }
};
