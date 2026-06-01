<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\VatRate;
use App\Services\Invoicing\InvoiceBuilder;
use App\Services\Invoicing\InvoiceLifecycle;
use App\Services\Invoicing\InvoicePdfRenderer;
use App\Support\InvoiceProjections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $filter = $request->string('filter', 'all')->toString();
        $search = $request->string('q')->toString() ?: null;

        return Inertia::render('Invoices/Index', [
            'invoices' => InvoiceProjections::index($filter, $search),
            'stats' => InvoiceProjections::stats(),
            'counts' => [
                'all' => Invoice::count(),
                'draft' => Invoice::where('status', 'draft')->count(),
                'sent' => Invoice::where('status', 'sent')->count(),
                'overdue' => Invoice::where('status', 'sent')->whereDate('due_on', '<', now()->toDateString())->count(),
                'paid' => Invoice::where('status', 'paid')->count(),
                'void' => Invoice::where('status', 'void')->count(),
            ],
            'filters' => ['filter' => $filter, 'q' => $search],
        ]);
    }

    public function create(Request $request, InvoiceBuilder $builder): Response
    {
        // The top-level "New invoice" button has no client yet → render a client picker.
        if (! $request->filled('client')) {
            return Inertia::render('Invoices/Create', [
                'client' => null,
                'project' => null,
                'period' => null,
                'entries' => [],
                'suggested_lines' => [],
                'clients' => Client::active()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->name])->values(),
                'vat_rates' => VatRate::catalogForFrontend(),
            ]);
        }

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
                'description' => $e->description !== '' ? $e->description : ($e->task_id ? ('Task #'.$e->task_id) : ('Entry #'.$e->id)),
                'project' => ['id' => $e->project->id, 'name' => $e->project->name, 'code' => $e->project->code],
                'hours' => round($e->duration_seconds / 3600, 2),
                'started_at' => $e->started_at->toIso8601String(),
                'rate' => (int) round(($e->project->rate_rappen ?? 0) / 100),
            ]),
            'suggested_lines' => collect($builder->suggestLinesFromEntries($entries, $project, $end))
                ->map(fn ($l) => [
                    'description' => $l['description'],
                    'hours' => $l['hours'],
                    'rate' => (int) round($l['rate_rappen'] / 100),
                    'rate_rappen' => $l['rate_rappen'],
                    'entry_ids' => $l['entry_ids'],
                ])->values(),
            'vat_rates' => VatRate::catalogForFrontend(),
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
        $invoice->load(['client', 'project', 'recurringInvoice:id,title', 'lines' => fn ($q) => $q->orderBy('sort_order'), 'events' => fn ($q) => $q->orderByDesc('occurred_at')]);

        $linked = $invoice->timeEntries()
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, UTC_TIMESTAMP()))),0) AS secs')
            ->first();

        return Inertia::render('Invoices/Show', [
            'invoice' => InvoiceProjections::detail($invoice),
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
            if (array_key_exists('title', $data)) {
                $invoice->title = $data['title'];
            }
            if (array_key_exists('notes', $data)) {
                $invoice->notes = $data['notes'];
            }
            if (! empty($data['lines'])) {
                $invoice->lines()->delete();
                $lineAmounts = [];
                $sort = 0;
                foreach ($data['lines'] as $line) {
                    $hours = round((float) $line['hours'], 2);
                    $rate = (int) $line['rate_rappen'];
                    $amount = (int) round($hours * $rate);
                    $invoice->lines()->create([
                        'description' => $line['description'], 'hours' => $hours,
                        'rate_rappen' => $rate, 'amount_rappen' => $amount,
                        'sort_order' => $sort++,
                    ]);
                    $lineAmounts[] = $amount;
                }
                $totals = InvoiceBuilder::computeTotals($lineAmounts, (float) $invoice->vat_rate);
                $invoice->subtotal_rappen = $totals['subtotal_rappen'];
                $invoice->vat_rappen = $totals['vat_rappen'];
                $invoice->total_rappen = $totals['total_rappen'];
            }
            $invoice->save();
        });

        return redirect("/invoices/{$invoice->number}")->with('success', 'Draft updated.');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        $number = $invoice->number;

        if ($invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path)) {
            Storage::disk('local')->delete($invoice->pdf_path);
        }

        // Lines/events cascade; linked time entries are released (FK ON DELETE SET NULL).
        $invoice->delete();

        return redirect('/invoices')->with('success', "Invoice {$number} deleted.");
    }

    public function markSent(Invoice $invoice, InvoiceLifecycle $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->markSent($invoice);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Invoice {$invoice->number} marked as sent.");
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
        } catch (\Throwable $e) {
            Log::error('Invoice send failed.', ['invoice_id' => $invoice->id, 'exception' => $e]);

            return back()->with('error', "Could not email invoice {$invoice->number}. Please check mail settings and try again.");
        }

        return back()->with('success', "Invoice {$invoice->number} sent.");
    }

    public function pdf(Invoice $invoice, InvoicePdfRenderer $renderer): \Symfony\Component\HttpFoundation\Response
    {
        if ($invoice->status === 'draft') {
            return response()->streamDownload(
                function () use ($invoice, $renderer) {
                    echo $renderer->pdfBytes($invoice);
                },
                $invoice->pdfFilename(),
                ['Content-Type' => 'application/pdf'],
            );
        }

        $relative = $invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path)
            ? $invoice->pdf_path
            : $renderer->pdf($invoice);

        return response()->download(
            Storage::disk('local')->path($relative),
            "Rechnung-{$invoice->number}.pdf",
        );
    }
}
