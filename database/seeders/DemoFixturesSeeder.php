<?php

namespace Database\Seeders;

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Invoicing\InvoiceBuilder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DemoFixturesSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::first() ?? User::factory()->create();

        DB::transaction(function () use ($user) {
            $clients = collect([
                ['name' => 'Atlas Robotics',         'short_code' => 'AR', 'contact_name' => 'Marit Hesse',    'email' => 'marit@atlas.dev',   'default_rate_rappen' => 14500],
                ['name' => 'Körbel & Söhne GmbH',    'short_code' => 'KS', 'contact_name' => 'Stefan Körbel',  'email' => 's.korbel@korbel.de','default_rate_rappen' => 12000],
                ['name' => 'Northlit Press',         'short_code' => 'NP', 'contact_name' => 'Annie Park',     'email' => 'annie@northlit.co', 'default_rate_rappen' => 13000],
                ['name' => 'Halden Studio',          'short_code' => 'HS', 'contact_name' => 'Ola Halden',     'email' => 'ola@halden.no',     'default_rate_rappen' => 11000],
                ['name' => 'Kelp Forest Co.',        'short_code' => 'KF', 'contact_name' => 'Mira Okafor',    'email' => 'mira@kelp.co',      'default_rate_rappen' => 15000],
                ['name' => 'Private / Internal',     'short_code' => 'PR', 'contact_name' => null,             'email' => null,                'default_rate_rappen' => null],
            ])->map(fn ($c) => Client::firstOrCreate(['short_code' => $c['short_code']], $c));

            $projectDefs = [
                ['code' => 'ATLS-FLT', 'client' => 'AR', 'name' => 'Fleet Console v2',         'glyph' => 'alt-0', 'budget_hours' => 220, 'budget_amount_rappen' => 3190000, 'rate_rappen' => 14500],
                ['code' => 'KS-ERP',   'client' => 'KS', 'name' => 'ERP Migration',            'glyph' => 'alt-1', 'budget_hours' => 160, 'budget_amount_rappen' => 1920000, 'rate_rappen' => 12000],
                ['code' => 'ATLS-DOC', 'client' => 'AR', 'name' => 'Developer Docs Portal',    'glyph' => 'alt-2', 'budget_hours' => 80,  'budget_amount_rappen' => 1160000, 'rate_rappen' => 14500],
                ['code' => 'NL-WEB',   'client' => 'NP', 'name' => 'Marketing Site Refresh',   'glyph' => 'alt-3', 'budget_hours' => 120, 'budget_amount_rappen' => 1560000, 'rate_rappen' => 13000],
                ['code' => 'KF-BRD',   'client' => 'KF', 'name' => 'Brand System',             'glyph' => 'alt-4', 'budget_hours' => 60,  'budget_amount_rappen' => 900000,  'rate_rappen' => 15000],
                ['code' => 'HS-IOS',   'client' => 'HS', 'name' => 'iOS App MVP',              'glyph' => 'alt-0', 'budget_hours' => 100, 'budget_amount_rappen' => 1100000, 'rate_rappen' => 11000],
                ['code' => 'KS-SUP',   'client' => 'KS', 'name' => 'Retainer / Support',       'glyph' => 'alt-1', 'budget_hours' => 16,  'budget_amount_rappen' => 192000,  'rate_rappen' => 12000, 'retainer' => true],
                ['code' => 'ERNTE',    'client' => 'PR', 'name' => 'ernte (self)',              'glyph' => 'alt-2', 'budget_hours' => 0,   'budget_amount_rappen' => 0,       'rate_rappen' => 0,     'billable' => false],
            ];

            foreach ($projectDefs as $d) {
                $client = $clients->firstWhere('short_code', $d['client']);
                Project::firstOrCreate(
                    ['code' => $d['code']],
                    [
                        'client_id' => $client->id,
                        'name' => $d['name'],
                        'glyph' => $d['glyph'],
                        'status' => 'active',
                        'billable' => $d['billable'] ?? true,
                        'retainer' => $d['retainer'] ?? false,
                        'retainer_hours' => ($d['retainer'] ?? false) ? 16 : null,
                        'retainer_resets_monthly' => $d['retainer'] ?? false,
                        'budget_hours' => $d['budget_hours'],
                        'budget_amount_rappen' => $d['budget_amount_rappen'],
                        'rate_rappen' => $d['rate_rappen'],
                        'started_on' => Carbon::now()->subDays(60)->toDateString(),
                        'deadline_on' => Carbon::now()->addDays(60)->toDateString(),
                    ]
                );
            }

            $atlasFleet = Project::where('code', 'ATLS-FLT')->first();
            $taskDefs = [
                ['name' => 'Map mode: cluster rendering', 'budget_hours' => 16, 'done' => false],
                ['name' => 'Telemetry side-panel',         'budget_hours' => 20, 'done' => false],
                ['name' => 'Operator role permissions',    'budget_hours' => 12, 'done' => false],
                ['name' => 'PR review queue',              'budget_hours' => 30, 'done' => false],
                ['name' => 'Discovery / spec',             'budget_hours' => 24, 'done' => true],
                ['name' => 'Auth refactor',                'budget_hours' => 18, 'done' => true],
                ['name' => 'Storybook scaffold',           'budget_hours' => 8,  'done' => true],
            ];
            foreach ($taskDefs as $i => $d) {
                Task::firstOrCreate(
                    ['project_id' => $atlasFleet->id, 'name' => $d['name']],
                    ['budget_hours' => $d['budget_hours'], 'done' => $d['done'], 'sort_order' => $i]
                );
            }

            $today = Carbon::today();
            $entries = [
                ['project' => 'ATLS-FLT', 'desc' => 'Map mode: cluster rendering', 'start' => '09:12', 'end' => '10:48'],
                ['project' => 'KS-SUP',   'desc' => 'Sync bug — partner export',    'start' => '10:52', 'end' => '11:24'],
                ['project' => 'ATLS-FLT', 'desc' => 'Code review: PR #318',         'start' => '13:30', 'end' => '14:05'],
                ['project' => 'ATLS-DOC', 'desc' => 'Versioned routing prototype',  'start' => '14:18', 'end' => '15:42'],
                ['project' => 'ERNTE',    'desc' => 'Invoice PDF template',         'start' => '15:55', 'end' => '16:22'],
            ];
            foreach ($entries as $e) {
                $p = Project::where('code', $e['project'])->first();
                $exists = TimeEntry::where('user_id', $user->id)
                    ->where('project_id', $p->id)
                    ->where('description', $e['desc'])
                    ->where('started_at', $today->copy()->setTimeFromTimeString($e['start']))
                    ->exists();
                if (!$exists) {
                    TimeEntry::create([
                        'user_id' => $user->id,
                        'project_id' => $p->id,
                        'description' => $e['desc'],
                        'started_at' => $today->copy()->setTimeFromTimeString($e['start']),
                        'ended_at' => $today->copy()->setTimeFromTimeString($e['end']),
                        'billable' => (bool) $p->billable,
                    ]);
                }
            }

            // A business profile is required by the invoice builder.
            BusinessProfile::firstOrCreate(['id' => 1], [
                'name' => 'Ernte GmbH', 'address_line_1' => 'Bahnhofstrasse 1',
                'postal_code' => '8001', 'city' => 'Zürich', 'country' => 'CH',
                'qr_iban' => 'CH4431999123000889012',
                'default_currency' => 'CHF', 'default_vat_rate' => 8.10, 'reminder_days_after_due' => 7,
            ]);

            if (Invoice::count() === 0) {
                $atlas = Client::where('short_code', 'AR')->first();
                $fleet = Project::where('code', 'ATLS-FLT')->first();

                // Backdated billable entries to invoice from.
                for ($i = 0; $i < 6; $i++) {
                    TimeEntry::create([
                        'user_id' => $user->id, 'project_id' => $fleet->id,
                        'description' => ['Map mode', 'Telemetry side-panel', 'Operator permissions'][$i % 3],
                        'started_at' => Carbon::now()->subMonthNoOverflow()->startOfMonth()->addDays($i + 1)->setTime(9, 0),
                        'ended_at' => Carbon::now()->subMonthNoOverflow()->startOfMonth()->addDays($i + 1)->setTime(13, 0),
                        'billable' => true,
                    ]);
                }

                $builder = app(InvoiceBuilder::class);

                // One draft.
                $builder->buildDraftFromEntries(
                    $atlas, $fleet,
                    TimeEntry::where('project_id', $fleet->id)
                        ->whereNull('invoice_id')
                        ->where('billable', true)
                        ->whereDate('started_at', '<', Carbon::today())  // only backdated entries; leave today's unbilled
                        ->get(),
                    Carbon::now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                    Carbon::now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
                );

                // One issued (sent) invoice — skip PDF render in seeding to avoid Chromium dependency.
                $sent = $builder->createDraft(
                    $atlas, $fleet,
                    Carbon::now()->subMonths(2)->startOfMonth()->toDateString(),
                    Carbon::now()->subMonths(2)->endOfMonth()->toDateString(),
                    [['description' => 'Sprint work', 'hours' => 40, 'rate_rappen' => 14500, 'vat_exempt' => false]],
                    [],
                );
                $sent->update(['status' => 'sent', 'issued_on' => Carbon::now()->subDays(40)->toDateString(), 'due_on' => Carbon::now()->subDays(10)->toDateString(), 'sent_at' => Carbon::now()->subDays(40)]);
            }
        });
    }
}
