<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Jobs\SendInvoiceReminderMail;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\InvoiceLine;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\VatRate;
use App\Services\Invoicing\InvoiceBuilder;
use App\Services\Invoicing\InvoiceLifecycle;
use App\Services\Invoicing\InvoicePdfRenderer;
use App\Support\EntryDuration;
use App\Support\InvoiceProjections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
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
        $chartYear = $request->integer('chart_year', now()->year);

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
            'invoiceChart' => InvoiceProjections::monthlyIssued($chartYear),
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
                'clients' => Client::active()->with('contacts')->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Client $c) => [
                        'id' => $c->id, 'name' => $c->name,
                        'contacts' => $c->contacts->map(fn (Contact $ct) => [
                            'id' => $ct->id, 'name' => $ct->name, 'email' => $ct->email,
                            'role' => $ct->role, 'is_default' => $ct->is_default,
                        ])->values(),
                    ])->values(),
                'vat_rates' => VatRate::catalogForFrontend(),
            ]);
        }

        $client = Client::with('contacts')->findOrFail($request->integer('client'));
        $project = $request->filled('project') ? Project::find($request->integer('project')) : null;

        $start = $request->filled('from')
            ? Carbon::parse($request->string('from'))->startOfDay()
            : Carbon::now()->subMonthNoOverflow()->startOfMonth();
        $end = $request->filled('to')
            ? Carbon::parse($request->string('to'))->endOfDay()
            : Carbon::now()->subMonthNoOverflow()->endOfMonth();

        $entries = $builder->unbilledEntries($client, $project, $start, $end);

        return Inertia::render('Invoices/Create', [
            'client' => $client->only('id', 'name', 'short_code') + [
                'contacts' => $client->contacts->map(fn (Contact $ct) => [
                    'id' => $ct->id, 'name' => $ct->name, 'email' => $ct->email,
                    'role' => $ct->role, 'is_default' => $ct->is_default,
                ])->values(),
            ],
            'project' => $project?->only('id', 'name', 'code', 'rate_rappen'),
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'entries' => $entries->map(fn (TimeEntry $e) => [
                'id' => $e->id,
                'description' => $e->description !== '' ? $e->description : ($e->task_id ? ('Task #'.$e->task_id) : ('Entry #'.$e->id)),
                'project' => ['id' => $e->project->id, 'name' => $e->project->name, 'code' => $e->project->code],
                'hours' => round($e->duration_seconds / 3600, 2),
                'duration_seconds' => $e->duration_seconds,
                'started_at' => $e->started_at->toIso8601String(),
                'rate' => round(($e->project->rate_rappen ?? 0) / 100, 2),
            ]),
            'suggested_lines' => collect($builder->suggestLinesFromEntries($entries, $project, $end))
                ->map(fn ($l) => [
                    'description' => $l['description'],
                    'hours' => $l['hours'],
                    'rate' => round($l['rate_rappen'] / 100, 2),
                    'rate_rappen' => $l['rate_rappen'],
                    'entry_ids' => $l['entry_ids'],
                ])->values(),
            'unbilled_outside_period' => $builder->unbilledOutsidePeriod($client, $project, $start, $end),
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
            title: $data['title'] ?? null,
            notes: $data['notes'] ?? null,
            recipients: $data['recipients'] ?? null,
        );

        return redirect("/invoices/{$invoice->number}")->with('success', "Draft {$invoice->number} created.");
    }

    public function show(Invoice $invoice): Response
    {
        $invoice->load(['client', 'project', 'recurringInvoice:id,title', 'lines' => fn ($q) => $q->orderBy('sort_order'), 'events' => fn ($q) => $q->orderByDesc('occurred_at')]);

        $linked = $invoice->timeEntries()
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM('.EntryDuration::secondsSql().'),0) AS secs, MIN(started_at) AS first_at, MAX(started_at) AS last_at')
            ->first();

        return Inertia::render('Invoices/Show', [
            'invoice' => InvoiceProjections::detail($invoice),
            'events' => $invoice->events->map(fn ($e) => [
                'kind' => $e->kind,
                'occurred_at' => $e->occurred_at->toIso8601String(),
                'payload' => $e->payload,
            ]),
            'linked_entries' => [
                'count' => (int) $linked->n,
                'hours' => round(((int) $linked->secs) / 3600, 1),
                'from' => $linked->first_at ? Carbon::parse($linked->first_at)->toDateString() : null,
                'to' => $linked->last_at ? Carbon::parse($linked->last_at)->toDateString() : null,
                'project' => $this->linkedProject($invoice),
            ],
            'next_reminder_on' => $this->nextReminderOn($invoice),
            'preview_url' => "/invoices/{$invoice->number}/preview",
            'pdf_url' => "/invoices/{$invoice->number}/pdf",
        ]);
    }

    /**
     * The project the linked time belongs to: the invoice's own project, or — for a
     * client-level invoice — the entries' project when they all share one.
     */
    private function linkedProject(Invoice $invoice): ?array
    {
        $project = $invoice->project;
        if (! $project) {
            $ids = $invoice->timeEntries()->distinct()->limit(2)->pluck('project_id');
            $project = $ids->count() === 1 ? Project::find($ids->first()) : null;
        }

        return $project ? ['name' => $project->name, 'code' => $project->code] : null;
    }

    /**
     * Date the scheduler will next queue a reminder: reminder_days_after_due
     * past the due date, or past the last reminder if one went out already.
     * Null unless the invoice is sent, overdue and not paused.
     */
    private function nextReminderOn(Invoice $invoice): ?string
    {
        if ($invoice->status !== 'sent' || ! $invoice->overdue || $invoice->reminders_paused_at) {
            return null;
        }

        $days = max(1, BusinessProfile::current()->reminder_days_after_due ?? 7);
        $lastReminded = $invoice->events->firstWhere('kind', 'reminded')?->occurred_at;
        $anchor = $lastReminded ? $lastReminded->copy()->startOfDay() : $invoice->due_on->copy();

        // Never in the past: a missed slot is picked up by the next nightly run.
        return $anchor->addDays($days)->max(Carbon::today())->toDateString();
    }

    public function remind(Invoice $invoice): RedirectResponse
    {
        if ($invoice->status !== 'sent') {
            return back()->with('error', 'Reminders only apply to sent invoices.');
        }

        if (empty($invoice->effectiveRecipients())) {
            return back()->with('error', 'No recipient email on this invoice.');
        }

        if (SendInvoiceReminderMail::remindedJustNow($invoice)) {
            return back()->with('error', "A reminder for invoice {$invoice->number} was sent a moment ago.");
        }

        SendInvoiceReminderMail::dispatch($invoice->id, force: true);

        return back()->with('success', "Reminder queued for invoice {$invoice->number}.");
    }

    public function edit(Invoice $invoice): RedirectResponse|Response
    {
        if ($invoice->status !== 'draft') {
            return redirect("/invoices/{$invoice->number}")->with('error', 'Only draft invoices can be edited.');
        }

        $invoice->load(['client:id,name', 'client.contacts', 'project:id,name', 'lines' => fn ($q) => $q->orderBy('sort_order')]);

        return Inertia::render('Invoices/Edit', [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'client' => $invoice->client->only('id', 'name'),
                'project_name' => $invoice->project?->name,
                'period_start' => $invoice->period_start?->toDateString(),
                'period_end' => $invoice->period_end?->toDateString(),
                'title' => $invoice->title,
                'locale' => $invoice->locale,
                'notes' => $invoice->notes,
                'vat_rate' => (float) $invoice->vat_rate,
                'recipients' => $invoice->recipients ?? [],
                'lines' => $invoice->lines->map(fn (InvoiceLine $l) => [
                    'description' => $l->description,
                    'hours' => (float) $l->hours,
                    'rate' => round($l->rate_rappen / 100, 2),
                    'rate_rappen' => $l->rate_rappen,
                    'vat_exempt' => (bool) $l->vat_exempt,
                ])->values(),
            ],
            'client_contacts' => $invoice->client->contacts->map(fn (Contact $ct) => [
                'id' => $ct->id, 'name' => $ct->name, 'email' => $ct->email,
                'role' => $ct->role, 'is_default' => $ct->is_default,
            ])->values(),
        ]);
    }

    public function preview(Invoice $invoice, InvoicePdfRenderer $renderer): HttpResponse
    {
        try {
            $html = $renderer->html($invoice);
        } catch (\DomainException $e) {
            // e.g. no IBAN configured: say so in the preview frame instead of a 500.
            return response($e->getMessage(), 422)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response($html)->header('Content-Type', 'text/html');
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice, InvoiceBuilder $builder): RedirectResponse
    {
        $builder->updateDraft($invoice, $request->validated());

        return redirect("/invoices/{$invoice->number}")->with('success', 'Draft updated.');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        if (! $invoice->isDeletable()) {
            return back()->with('error', "Invoice {$invoice->number} has been issued and cannot be deleted. Void it instead.");
        }

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

    public function pauseReminders(Invoice $invoice): RedirectResponse
    {
        if ($invoice->status !== 'sent') {
            return back()->with('error', 'Reminders only apply to sent invoices.');
        }

        $invoice->update(['reminders_paused_at' => now()]);
        InvoiceEvent::create(['invoice_id' => $invoice->id, 'kind' => 'reminders_paused', 'occurred_at' => now()]);

        return back()->with('success', "Reminders paused for invoice {$invoice->number}.");
    }

    public function resumeReminders(Invoice $invoice): RedirectResponse
    {
        if (! $invoice->reminders_paused_at) {
            return back()->with('error', 'Reminders are not paused for this invoice.');
        }

        $invoice->update(['reminders_paused_at' => null]);
        InvoiceEvent::create(['invoice_id' => $invoice->id, 'kind' => 'reminders_resumed', 'occurred_at' => now()]);

        return back()->with('success', "Reminders resumed for invoice {$invoice->number}.");
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

    public function exportPaid(Request $request, InvoicePdfRenderer $renderer): \Symfony\Component\HttpFoundation\Response|RedirectResponse
    {
        $year = $request->integer('year', now()->year);
        $quarter = min(4, max(1, $request->integer('quarter', now()->quarter)));

        $start = Carbon::create($year, ($quarter - 1) * 3 + 1, 1)->startOfDay();
        $end = $start->copy()->addMonths(3);

        $invoices = Invoice::query()
            ->where('status', 'paid')
            ->where('paid_at', '>=', $start)
            ->where('paid_at', '<', $end)
            ->orderBy('number')
            ->get();

        if ($invoices->isEmpty()) {
            return back()->with('error', "No paid invoices in Q{$quarter} {$year}.");
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'invoices-zip-');
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::OVERWRITE);

        foreach ($invoices as $invoice) {
            // Prefer the PDF frozen at send-time; render fresh only when it's missing.
            try {
                $bytes = $invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path)
                    ? Storage::disk('local')->get($invoice->pdf_path)
                    : $renderer->pdfBytes($invoice);
            } catch (\DomainException $e) {
                // E.g. no IBAN configured: a live render cannot build the QR bill.
                $zip->close();
                @unlink($zipPath);

                return back()->with('error', "Could not export Q{$quarter} {$year}: invoice {$invoice->number} has no stored PDF and could not be rendered. {$e->getMessage()}");
            }
            $zip->addFromString($invoice->pdfFilename(), $bytes);
        }

        $zip->close();

        return response()
            ->download($zipPath, "Rechnungen-{$year}-Q{$quarter}.zip", ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }

    public function pdf(Invoice $invoice, InvoicePdfRenderer $renderer): \Symfony\Component\HttpFoundation\Response
    {
        $headers = ['Content-Type' => 'application/pdf'];

        // An issued invoice is the document the client received: serve the PDF
        // frozen at send time, not a re-render from today's profile and template.
        // Drafts (and invoices marked sent by hand, which have no stored file) render live.
        if ($invoice->status !== 'draft' && $invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path)) {
            return Storage::disk('local')->download($invoice->pdf_path, $invoice->pdfFilename(), $headers);
        }

        try {
            $bytes = $renderer->pdfBytes($invoice);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return response()->streamDownload(fn () => print ($bytes), $invoice->pdfFilename(), $headers);
    }
}
