<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRecurringInvoiceRequest;
use App\Http\Requests\UpdateRecurringInvoiceRequest;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Project;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceLine;
use App\Models\VatRate;
use App\Services\Invoicing\RecurringInvoiceGenerator;
use App\Support\BillingPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RecurringInvoiceController extends Controller
{
    public function index(): Response
    {
        $schedules = RecurringInvoice::query()
            ->with('client:id,name')
            ->withCount('invoices')
            ->orderByRaw('paused_at IS NOT NULL')   // active first
            ->orderBy('next_run_on')
            ->get()
            ->map(fn (RecurringInvoice $s) => [
                'id' => $s->id,
                'client' => $s->client->only('id', 'name'),
                'title' => $s->title,
                'cadence' => $s->cadence,
                'next_run_on' => $s->next_run_on?->toDateString(),
                'auto_send' => $s->auto_send,
                'paused' => $s->isPaused(),
                'invoices_count' => $s->invoices_count,
            ]);

        return Inertia::render('RecurringInvoices/Index', ['schedules' => $schedules]);
    }

    public function create(): Response
    {
        return Inertia::render('RecurringInvoices/Create', $this->formData() + [
            'vat_rates' => VatRate::catalogForFrontend(),
        ]);
    }

    public function store(StoreRecurringInvoiceRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $first = Carbon::parse($data['next_run_on']);
        $nextRun = BillingPeriod::nextRunOnOrAfter($data['cadence'], $first, Carbon::today());

        DB::transaction(function () use ($data, $first, $nextRun) {
            $client = Client::findOrFail($data['client_id']);
            $documentRate = $data['vat_rate'] ?? VatRate::rateForDate($nextRun);
            $schedule = RecurringInvoice::create([
                'client_id' => $client->id,
                'project_id' => $data['project_id'] ?? null,
                'title' => $data['title'] ?? null,
                'notes' => $data['notes'] ?? null,
                'currency' => BusinessProfile::current()->default_currency ?? 'CHF',
                'vat_rate' => $documentRate,
                'cadence' => $data['cadence'],
                'anchor_day' => $first->day,
                'next_run_on' => $nextRun->toDateString(),
                'auto_send' => $data['auto_send'] ?? false,
                'recipients' => $data['recipients'] ?? $client->defaultRecipients(),
            ]);
            $this->syncLines($schedule, $data['lines']);
        });

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
                'vat_rate' => (float) $recurringInvoice->vat_rate,
                'auto_send' => $recurringInvoice->auto_send,
                'recipients' => $recurringInvoice->recipients ?? [],
                'lines' => $recurringInvoice->lines->map(fn (RecurringInvoiceLine $l) => [
                    'description' => $l->description,
                    'hours' => (float) $l->hours,
                    'rate' => (int) round($l->rate_rappen / 100),
                ])->values(),
            ],
            'vat_rates' => VatRate::catalogForFrontend(),
        ]);
    }

    public function update(UpdateRecurringInvoiceRequest $request, RecurringInvoice $recurringInvoice): RedirectResponse
    {
        $data = $request->validated();
        $first = Carbon::parse($data['next_run_on']);
        $nextRun = BillingPeriod::nextRunOnOrAfter($data['cadence'], $first, Carbon::today());

        DB::transaction(function () use ($data, $first, $nextRun, $recurringInvoice) {
            $documentRate = $data['vat_rate'] ?? VatRate::rateForDate($nextRun);
            $recurringInvoice->update([
                'client_id' => $data['client_id'],
                'project_id' => $data['project_id'] ?? null,
                'title' => $data['title'] ?? null,
                'notes' => $data['notes'] ?? null,
                'vat_rate' => $documentRate,
                'cadence' => $data['cadence'],
                'anchor_day' => $first->day,
                'next_run_on' => $nextRun->toDateString(),
                'auto_send' => $data['auto_send'] ?? false,
            ]);
            if (array_key_exists('recipients', $data)) {
                $recurringInvoice->recipients = $data['recipients'];
                $recurringInvoice->save();
            }
            $recurringInvoice->lines()->delete();
            $this->syncLines($recurringInvoice, $data['lines']);
        });

        return redirect('/recurring-invoices')->with('success', 'Recurring schedule updated.');
    }

    public function pause(RecurringInvoice $recurringInvoice): RedirectResponse
    {
        $recurringInvoice->update(['paused_at' => now()]);

        return back()->with('success', 'Schedule paused.');
    }

    public function resume(RecurringInvoice $recurringInvoice): RedirectResponse
    {
        // Snap the next run forward so resuming never backfills missed periods.
        $next = BillingPeriod::nextRunOnOrAfter(
            $recurringInvoice->cadence,
            Carbon::parse($recurringInvoice->next_run_on),
            Carbon::today(),
        );
        $recurringInvoice->update(['paused_at' => null, 'next_run_on' => $next->toDateString()]);

        return back()->with('success', 'Schedule resumed.');
    }

    public function run(RecurringInvoice $recurringInvoice, RecurringInvoiceGenerator $generator): RedirectResponse
    {
        if ($recurringInvoice->isPaused()) {
            return back()->with('error', 'Cannot generate from a paused schedule. Resume it first.');
        }

        $invoice = $generator->generate($recurringInvoice, Carbon::parse($recurringInvoice->next_run_on));

        return redirect("/invoices/{$invoice->number}")
            ->with('success', "Generated invoice {$invoice->number} from the recurring schedule.");
    }

    public function destroy(RecurringInvoice $recurringInvoice): RedirectResponse
    {
        // Lines cascade; generated invoices are kept (recurring_invoice_id nulls via FK).
        $recurringInvoice->delete();

        return redirect('/recurring-invoices')->with('success', 'Recurring schedule deleted.');
    }

    /** @param array<int, array{description:string, hours:float|string, rate_rappen:int}> $lines */
    private function syncLines(RecurringInvoice $schedule, array $lines): void
    {
        $sort = 0;
        foreach ($lines as $line) {
            $schedule->lines()->create([
                'description' => (string) $line['description'],
                'hours' => round((float) $line['hours'], 2),
                'rate_rappen' => (int) $line['rate_rappen'],
                'sort_order' => $sort++,
            ]);
        }
    }

    /** Shared client/project option lists for the Create/Edit forms. */
    private function formData(): array
    {
        return [
            'clients' => Client::active()->with('contacts')->orderBy('name')->get(['id', 'name'])
                ->map(fn (Client $c) => [
                    'id' => $c->id, 'name' => $c->name,
                    'contacts' => $c->contacts->map(fn (\App\Models\Contact $ct) => [
                        'id' => $ct->id, 'name' => $ct->name, 'email' => $ct->email,
                        'role' => $ct->role, 'is_default' => $ct->is_default,
                    ])->values(),
                ])->values(),
            'projects' => Project::active()->orderBy('name')->get(['id', 'name', 'client_id', 'rate_rappen'])
                ->map(fn (Project $p) => [
                    'id' => $p->id, 'name' => $p->name, 'client_id' => $p->client_id,
                    'rate' => (int) round(($p->rate_rappen ?? 0) / 100),
                ])->values(),
        ];
    }
}
