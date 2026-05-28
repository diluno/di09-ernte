<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInvoiceRequest;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\Invoicing\InvoiceBuilder;
use App\Support\InvoiceProjections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $filter = $request->string('filter', 'all')->toString();
        $search = $request->string('q')->toString() ?: null;

        return Inertia::render('Invoices/Index', [
            'invoices' => InvoiceProjections::index($filter, $search)->values(),
            'stats'    => InvoiceProjections::stats(),
            'counts'   => [
                'all'     => Invoice::count(),
                'draft'   => Invoice::where('status', 'draft')->count(),
                'sent'    => Invoice::where('status', 'sent')->count(),
                'overdue' => Invoice::where('status', 'sent')->whereDate('due_on', '<', now()->toDateString())->count(),
                'paid'    => Invoice::where('status', 'paid')->count(),
                'void'    => Invoice::where('status', 'void')->count(),
            ],
            'filters'  => ['filter' => $filter, 'q' => $search],
        ]);
    }

    public function create(Request $request, InvoiceBuilder $builder): Response
    {
        $client = Client::findOrFail($request->integer('client'));
        $project = $request->filled('project') ? Project::find($request->integer('project')) : null;

        $start = $request->filled('from')
            ? Carbon::parse($request->string('from'))->startOfDay()
            : Carbon::now()->subMonthNoOverflow()->startOfMonth();
        $end = $request->filled('to')
            ? Carbon::parse($request->string('to'))->endOfDay()
            : Carbon::now()->subMonthNoOverflow()->endOfMonth();

        $entries = TimeEntry::query()
            ->with(['project:id,name,code,rate_rappen'])
            ->where('billable', true)
            ->whereNull('invoice_id')
            ->finished()
            ->whereBetween('started_at', [$start, $end])
            ->when($project, fn ($q) => $q->where('project_id', $project->id),
                fn ($q) => $q->whereIn('project_id', $client->projects()->pluck('id')))
            ->orderBy('started_at')
            ->get();

        return Inertia::render('Invoices/Create', [
            'client' => $client->only('id', 'name', 'short_code'),
            'project' => $project?->only('id', 'name', 'code', 'rate_rappen'),
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'entries' => $entries->map(fn (TimeEntry $e) => [
                'id' => $e->id,
                'description' => $e->description !== '' ? $e->description : ($e->task_id ? ('Task #' . $e->task_id) : ('Entry #' . $e->id)),
                'project' => ['id' => $e->project->id, 'name' => $e->project->name, 'code' => $e->project->code],
                'hours' => round($e->duration_seconds / 3600, 2),
                'started_at' => $e->started_at->toIso8601String(),
                'rate' => (int) round(($e->project->rate_rappen ?? 0) / 100),
            ]),
            'suggested_lines' => collect($builder->suggestLinesFromEntries($entries, $project))
                ->map(fn ($l) => [
                    'description' => $l['description'],
                    'hours' => $l['hours'],
                    'rate' => (int) round($l['rate_rappen'] / 100),
                    'rate_rappen' => $l['rate_rappen'],
                    'vat_exempt' => $l['vat_exempt'],
                    'entry_ids' => $l['entry_ids'],
                ])->values(),
        ]);
    }

    public function store(StoreInvoiceRequest $request, InvoiceBuilder $builder): RedirectResponse
    {
        $data = $request->validated();
        $client = Client::findOrFail($data['client_id']);
        $project = isset($data['project_id']) ? Project::find($data['project_id']) : null;

        $invoice = $builder->createDraft(
            client: $client,
            project: $project,
            periodStart: $data['period_start'],
            periodEnd: $data['period_end'],
            lines: $data['lines'],
            entryIds: $data['entry_ids'] ?? [],
        );

        return redirect("/invoices/{$invoice->number}")->with('success', "Draft {$invoice->number} created.");
    }
}
