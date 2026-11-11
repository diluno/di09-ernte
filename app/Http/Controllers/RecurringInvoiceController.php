<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRecurringInvoiceRequest;
use App\Http\Requests\UpdateRecurringInvoiceRequest;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Project;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceLine;
use App\Models\VatRate;
use App\Services\Invoicing\RecurringInvoiceGenerator;
use App\Services\Invoicing\RecurringInvoiceSchedules;
use App\Support\RecurringInvoiceProjections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class RecurringInvoiceController extends Controller
{
    public function index(): Response
    {
        $schedules = RecurringInvoice::query()
            ->with(['client:id,name,locale', 'lines'])
            ->withCount('invoices')
            ->orderByRaw('paused_at IS NOT NULL')   // active first
            ->orderBy('next_run_on')
            ->get();

        return Inertia::render('RecurringInvoices/Index', [
            'schedules' => $schedules->map(fn (RecurringInvoice $s) => RecurringInvoiceProjections::indexRow($s))->values(),
            'stats' => RecurringInvoiceProjections::indexStats($schedules),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('RecurringInvoices/Create', $this->formData() + [
            'vat_rates' => VatRate::catalogForFrontend(),
        ]);
    }

    public function store(StoreRecurringInvoiceRequest $request, RecurringInvoiceSchedules $schedules): RedirectResponse
    {
        $schedules->create($request->validated());

        return redirect('/recurring-invoices')->with('success', 'Recurring schedule created.');
    }

    public function edit(RecurringInvoice $recurringInvoice): Response
    {
        $recurringInvoice->load(['lines' => fn ($q) => $q->orderBy('sort_order')]);

        return Inertia::render('RecurringInvoices/Edit', $this->formData() + [
            'schedule' => [
                'id' => $recurringInvoice->id,
                'client_id' => $recurringInvoice->client_id,
                'project_id' => $recurringInvoice->project_id,
                'title' => $recurringInvoice->title,
                'notes' => $recurringInvoice->notes,
                'cadence' => $recurringInvoice->cadence,
                'next_run_on' => $recurringInvoice->next_run_on?->toDateString(),
                // Rate the next invoice will carry: the one in force at the end of the period it bills.
                'vat_rate' => $recurringInvoice->nextVatRate(),
                'next_period_end' => $recurringInvoice->nextPeriod()['end']->toDateString(),
                // Title of the next generated invoice ("{period}" resolved), for the form hint.
                'next_title' => RecurringInvoiceProjections::nextTitle($recurringInvoice),
                // Lets the form re-derive the period end when only the cadence changes.
                'anchor_day' => $recurringInvoice->anchor_day,
                'auto_send' => $recurringInvoice->auto_send,
                'paused' => $recurringInvoice->isPaused(),
                'recipients' => $recurringInvoice->recipients ?? [],
                'lines' => $recurringInvoice->lines->map(fn (RecurringInvoiceLine $l) => [
                    'description' => $l->description,
                    'hours' => (float) $l->hours,
                    'rate' => round($l->rate_rappen / 100, 2),
                ])->values(),
            ],
            'vat_rates' => VatRate::catalogForFrontend(),
        ]);
    }

    public function update(UpdateRecurringInvoiceRequest $request, RecurringInvoice $recurringInvoice, RecurringInvoiceSchedules $schedules): RedirectResponse
    {
        $schedules->update($recurringInvoice, $request->validated());

        return redirect('/recurring-invoices')->with('success', 'Recurring schedule updated.');
    }

    public function pause(RecurringInvoice $recurringInvoice, RecurringInvoiceSchedules $schedules): RedirectResponse
    {
        $schedules->pause($recurringInvoice);

        return back()->with('success', 'Schedule paused.');
    }

    public function resume(RecurringInvoice $recurringInvoice, RecurringInvoiceSchedules $schedules): RedirectResponse
    {
        $schedules->resume($recurringInvoice);

        return back()->with('success', 'Schedule resumed.');
    }

    public function run(RecurringInvoice $recurringInvoice, RecurringInvoiceGenerator $generator): RedirectResponse
    {
        if ($recurringInvoice->isPaused()) {
            return back()->with('error', 'Cannot generate from a paused schedule. Resume it first.');
        }

        try {
            $invoice = $generator->generate($recurringInvoice, Carbon::parse($recurringInvoice->next_run_on));
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect("/invoices/{$invoice->number}")
            ->with('success', "Generated invoice {$invoice->number} from the recurring schedule.");
    }

    public function destroy(RecurringInvoice $recurringInvoice): RedirectResponse
    {
        // Lines cascade; generated invoices are kept (recurring_invoice_id nulls via FK).
        $recurringInvoice->delete();

        return redirect('/recurring-invoices')->with('success', 'Recurring schedule deleted.');
    }

    /** Shared client/project option lists for the Create/Edit forms. */
    private function formData(): array
    {
        return [
            'clients' => Client::active()->with('contacts')->orderBy('name')->get(['id', 'name'])
                ->map(fn (Client $c) => [
                    'id' => $c->id, 'name' => $c->name,
                    'contacts' => $c->contacts->map(fn (Contact $ct) => [
                        'id' => $ct->id, 'name' => $ct->name, 'email' => $ct->email,
                        'role' => $ct->role, 'is_default' => $ct->is_default,
                    ])->values(),
                ])->values(),
            'projects' => Project::active()->orderBy('name')->get(['id', 'name', 'client_id', 'rate_rappen'])
                ->map(fn (Project $p) => [
                    'id' => $p->id, 'name' => $p->name, 'client_id' => $p->client_id,
                    'rate' => round(($p->rate_rappen ?? 0) / 100, 2),
                ])->values(),
        ];
    }
}
