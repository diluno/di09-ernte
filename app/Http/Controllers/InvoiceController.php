<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\Invoicing\InvoiceBuilder;
use App\Services\Invoicing\InvoiceLifecycle;
use App\Services\Invoicing\InvoicePdfRenderer;
use App\Support\InvoiceProjections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    public function show(Invoice $invoice): Response
    {
        $invoice->load(['client', 'project', 'lines' => fn ($q) => $q->orderBy('sort_order'), 'events' => fn ($q) => $q->orderByDesc('occurred_at')]);

        $linked = $invoice->timeEntries()
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))),0) AS secs')
            ->first();

        return Inertia::render('Invoices/Show', [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status,
                'overdue' => $invoice->overdue,
                'client' => $invoice->client->only('id', 'name'),
                'project_name' => $invoice->project?->name,
                'issued_on' => $invoice->issued_on?->toDateString(),
                'due_on' => $invoice->due_on?->toDateString(),
                'subtotal' => round($invoice->subtotal_rappen / 100, 2),
                'vat' => round($invoice->vat_rappen / 100, 2),
                'total' => round($invoice->total_rappen / 100, 2),
                'vat_rate' => (float) $invoice->vat_rate,
                'notes' => $invoice->notes,
                'lines' => $invoice->lines->map(fn (InvoiceLine $l) => [
                    'id' => $l->id, 'description' => $l->description,
                    'hours' => (float) $l->hours, 'rate' => (int) round($l->rate_rappen / 100),
                    'amount' => round($l->amount_rappen / 100, 2), 'vat_exempt' => (bool) $l->vat_exempt,
                ]),
            ],
            'events' => $invoice->events->map(fn ($e) => [
                'kind' => $e->kind,
                'occurred_at' => $e->occurred_at->toIso8601String(),
                'payload' => $e->payload,
            ]),
            'linked_entries' => ['count' => (int) $linked->n, 'hours' => round(((int) $linked->secs) / 3600, 1)],
            'preview_url' => "/invoices/{$invoice->number}/preview",
            'pdf_url' => "/invoices/{$invoice->number}/pdf",
        ]);
    }

    public function preview(Invoice $invoice, InvoicePdfRenderer $renderer): HttpResponse
    {
        return response($renderer->html($invoice))->header('Content-Type', 'text/html');
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $invoice) {
            if (array_key_exists('notes', $data)) {
                $invoice->notes = $data['notes'];
            }
            if (! empty($data['lines'])) {
                $invoice->lines()->delete();
                $lineAmounts = []; $vatExempts = []; $sort = 0;
                foreach ($data['lines'] as $line) {
                    $hours = round((float) $line['hours'], 2);
                    $rate = (int) $line['rate_rappen'];
                    $amount = (int) round($hours * $rate);
                    $exempt = (bool) ($line['vat_exempt'] ?? false);
                    $invoice->lines()->create([
                        'description' => $line['description'], 'hours' => $hours,
                        'rate_rappen' => $rate, 'amount_rappen' => $amount,
                        'vat_exempt' => $exempt, 'sort_order' => $sort++,
                    ]);
                    $lineAmounts[] = $amount; $vatExempts[] = $exempt;
                }
                $totals = InvoiceBuilder::computeTotals($lineAmounts, $vatExempts, (float) $invoice->vat_rate);
                $invoice->subtotal_rappen = $totals['subtotal_rappen'];
                $invoice->vat_rappen = $totals['vat_rappen'];
                $invoice->total_rappen = $totals['total_rappen'];
            }
            $invoice->save();
        });

        return redirect("/invoices/{$invoice->number}")->with('success', 'Draft updated.');
    }

    public function markPaid(Invoice $invoice, InvoiceLifecycle $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->markPaid($invoice);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', "Invoice {$invoice->number} marked paid.");
    }

    public function void(Invoice $invoice, InvoiceLifecycle $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->void($invoice);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', "Invoice {$invoice->number} voided.");
    }

    public function send(Invoice $invoice, InvoiceLifecycle $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->issue($invoice);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            // PDF/QR render failures (e.g. an invalid QR bill) should not 500.
            return back()->with('error', "Could not issue invoice {$invoice->number}: {$e->getMessage()}");
        }
        return back()->with('success', "Invoice {$invoice->number} issued.");
    }

    public function pdf(Invoice $invoice, InvoicePdfRenderer $renderer): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $relative = $invoice->pdf_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($invoice->pdf_path)
            ? $invoice->pdf_path
            : $renderer->pdf($invoice);

        return response()->download(
            \Illuminate\Support\Facades\Storage::disk('local')->path($relative),
            "Rechnung-{$invoice->number}.pdf",
        );
    }
}
