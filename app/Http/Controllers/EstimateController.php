<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEstimateRequest;
use App\Http\Requests\UpdateEstimateRequest;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateLine;
use App\Models\Project;
use App\Models\VatRate;
use App\Services\Estimating\EstimateBuilder;
use App\Services\Estimating\EstimateLifecycle;
use App\Services\Estimating\EstimatePdfRenderer;
use App\Support\EstimateProjections;
use App\Support\LineTotals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class EstimateController extends Controller
{
    public function index(Request $request): Response
    {
        $filter = $request->string('filter', 'all')->toString();
        $search = $request->string('q')->toString() ?: null;

        return Inertia::render('Estimates/Index', [
            'estimates' => EstimateProjections::index($filter, $search),
            'stats' => EstimateProjections::stats(),
            'counts' => [
                'all' => Estimate::count(),
                'draft' => Estimate::where('status', 'draft')->count(),
                'sent' => Estimate::where('status', 'sent')->count(),
                'accepted' => Estimate::where('status', 'accepted')->count(),
                'declined' => Estimate::where('status', 'declined')->count(),
                'expired' => Estimate::where('status', 'sent')->whereDate('valid_until', '<', now()->toDateString())->count(),
            ],
            'filters' => ['filter' => $filter, 'q' => $search],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Estimates/Create', [
            'clients' => Client::active()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->name])->values(),
            'projects' => Project::active()->orderBy('name')->get(['id', 'name', 'client_id', 'rate_rappen'])
                ->map(fn (Project $p) => [
                    'id' => $p->id, 'name' => $p->name, 'client_id' => $p->client_id,
                    'rate' => (int) round(($p->rate_rappen ?? 0) / 100),
                ])->values(),
            'vat_rates' => VatRate::catalogForFrontend(),
        ]);
    }

    public function store(StoreEstimateRequest $request, EstimateBuilder $builder): RedirectResponse
    {
        $data = $request->validated();
        $client = Client::findOrFail($data['client_id']);
        $project = isset($data['project_id']) ? Project::find($data['project_id']) : null;

        $estimate = $builder->createDraft(
            client: $client,
            project: $project,
            lines: $data['lines'],
            notes: $data['notes'] ?? null,
            title: $data['title'] ?? null,
        );

        return redirect("/estimates/{$estimate->number}")->with('success', "Draft {$estimate->number} created.");
    }

    public function show(Estimate $estimate): Response
    {
        $estimate->load([
            'client', 'project',
            'lines' => fn ($q) => $q->orderBy('sort_order'),
            'events' => fn ($q) => $q->orderByDesc('occurred_at'),
            'convertedInvoice:id,number',
        ]);

        return Inertia::render('Estimates/Show', [
            'estimate' => EstimateProjections::detail($estimate),
            'events' => $estimate->events->map(fn ($e) => [
                'kind' => $e->kind,
                'occurred_at' => $e->occurred_at->toIso8601String(),
                'payload' => $e->payload,
            ]),
            'preview_url' => "/estimates/{$estimate->number}/preview",
            'pdf_url' => "/estimates/{$estimate->number}/pdf",
        ]);
    }

    public function edit(Estimate $estimate): RedirectResponse|Response
    {
        if ($estimate->status !== 'draft') {
            return redirect("/estimates/{$estimate->number}")->with('error', 'Only draft estimates can be edited.');
        }

        $estimate->load(['lines' => fn ($q) => $q->orderBy('sort_order')]);

        return Inertia::render('Estimates/Edit', [
            'estimate' => [
                'id' => $estimate->id,
                'number' => $estimate->number,
                'client_id' => $estimate->client_id,
                'project_id' => $estimate->project_id,
                'title' => $estimate->title,
                'notes' => $estimate->notes,
                'tax_date' => ($estimate->issued_on ?? $estimate->created_at)?->toDateString(),
                'lines' => $estimate->lines->map(fn (EstimateLine $l) => [
                    'description' => $l->description,
                    'hours' => (float) $l->hours,
                    'rate' => (int) round($l->rate_rappen / 100),
                ])->values(),
            ],
            'clients' => Client::active()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->name])->values(),
            'projects' => Project::active()->orderBy('name')->get(['id', 'name', 'client_id', 'rate_rappen'])
                ->map(fn (Project $p) => [
                    'id' => $p->id, 'name' => $p->name, 'client_id' => $p->client_id,
                    'rate' => (int) round(($p->rate_rappen ?? 0) / 100),
                ])->values(),
            'vat_rates' => VatRate::catalogForFrontend(),
        ]);
    }

    public function preview(Estimate $estimate, EstimatePdfRenderer $renderer): HttpResponse
    {
        return response($renderer->html($estimate))->header('Content-Type', 'text/html');
    }

    public function update(UpdateEstimateRequest $request, Estimate $estimate): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $estimate) {
            if (array_key_exists('client_id', $data)) {
                $estimate->client_id = $data['client_id'];
            }
            if (array_key_exists('project_id', $data)) {
                $estimate->project_id = $data['project_id'];
            }
            if (array_key_exists('title', $data)) {
                $estimate->title = $data['title'];
            }
            if (array_key_exists('notes', $data)) {
                $estimate->notes = $data['notes'];
            }
            if (! empty($data['lines'])) {
                $estimate->lines()->delete();
                $lineAmounts = [];
                $sort = 0;
                foreach ($data['lines'] as $line) {
                    $hours = round((float) $line['hours'], 2);
                    $rate = (int) $line['rate_rappen'];
                    $amount = (int) round($hours * $rate);
                    $estimate->lines()->create([
                        'description' => $line['description'], 'hours' => $hours,
                        'rate_rappen' => $rate, 'amount_rappen' => $amount,
                        'sort_order' => $sort++,
                    ]);
                    $lineAmounts[] = $amount;
                }
                $totals = LineTotals::compute($lineAmounts, (float) $estimate->vat_rate);
                $estimate->subtotal_rappen = $totals['subtotal_rappen'];
                $estimate->vat_rappen = $totals['vat_rappen'];
                $estimate->rounding_rappen = $totals['rounding_rappen'];
                $estimate->total_rappen = $totals['total_rappen'];
            }
            $estimate->save();
        });

        return redirect("/estimates/{$estimate->number}")->with('success', 'Draft updated.');
    }

    public function send(Estimate $estimate, EstimateLifecycle $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->send($estimate);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            return back()->with('error', "Could not send estimate {$estimate->number}: {$e->getMessage()}");
        } catch (\Throwable $e) {
            Log::error('Estimate send failed.', ['estimate_id' => $estimate->id, 'exception' => $e]);

            return back()->with('error', "Could not email estimate {$estimate->number}. Please check mail settings and try again.");
        }

        return back()->with('success', "Estimate {$estimate->number} sent.");
    }

    public function destroy(Estimate $estimate): RedirectResponse
    {
        $number = $estimate->number;

        if ($estimate->pdf_path && Storage::disk('local')->exists($estimate->pdf_path)) {
            Storage::disk('local')->delete($estimate->pdf_path);
        }

        // Lines/events cascade; a linked converted invoice is untouched (link clears via FK).
        $estimate->delete();

        return redirect('/estimates')->with('success', "Estimate {$number} deleted.");
    }

    public function markSent(Estimate $estimate, EstimateLifecycle $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->markSent($estimate);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Estimate {$estimate->number} marked as sent.");
    }

    public function accept(Estimate $estimate, EstimateLifecycle $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->accept($estimate);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Estimate {$estimate->number} accepted.");
    }

    public function decline(Estimate $estimate, EstimateLifecycle $lifecycle): RedirectResponse
    {
        try {
            $lifecycle->decline($estimate);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Estimate {$estimate->number} declined.");
    }

    public function convert(Estimate $estimate, EstimateLifecycle $lifecycle): RedirectResponse
    {
        try {
            $invoice = $lifecycle->convertToInvoice($estimate);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect("/invoices/{$invoice->number}")
            ->with('success', "Invoice {$invoice->number} created from estimate {$estimate->number}.");
    }

    public function pdf(Estimate $estimate, EstimatePdfRenderer $renderer): \Symfony\Component\HttpFoundation\Response
    {
        if ($estimate->status === 'draft') {
            return response()->streamDownload(
                function () use ($estimate, $renderer) {
                    echo $renderer->pdfBytes($estimate);
                },
                $estimate->pdfFilename(),
                ['Content-Type' => 'application/pdf'],
            );
        }

        $relative = $estimate->pdf_path && Storage::disk('local')->exists($estimate->pdf_path)
            ? $estimate->pdf_path
            : $renderer->pdf($estimate);

        return response()->download(
            Storage::disk('local')->path($relative),
            $estimate->pdfFilename(),
        );
    }
}
