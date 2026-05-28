<?php

namespace App\Services\Harvest;

use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateCounter;
use App\Models\Invoice;
use App\Models\InvoiceCounter;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class ImportRunner
{
    public function __construct(
        private ClientImporter $clients,
        private ProjectImporter $projects,
        private InvoiceImporter $invoices,
        private EstimateImporter $estimates,
    ) {}

    /** Network phase — pull everything into memory before touching the DB. */
    public function fetch(HarvestApi $api): HarvestData
    {
        return new HarvestData(
            clients: $api->clients()->all(),
            contacts: $api->contacts()->all(),
            projects: $api->projects()->all(),
            invoices: $api->invoices()->all(),
            estimates: $api->estimates()->all(),
        );
    }

    /** Persistence phase — wipe + insert + counter bump, atomically. */
    public function import(HarvestData $data): ImportSummary
    {
        return DB::transaction(function () use ($data) {
            $this->wipe();

            $clientMap = $this->clients->import($data->clients, $data->contacts);
            $projectMap = $this->projects->import($data->projects, $clientMap);
            $invoiceResult = $this->invoices->import($data->invoices, $clientMap);
            $estimateResult = $this->estimates->import($data->estimates, $clientMap);

            CounterReconciler::reconcileInvoices();
            CounterReconciler::reconcileEstimates();

            return new ImportSummary(
                clients: count($clientMap),
                projects: count($projectMap),
                invoices: $invoiceResult['imported'],
                estimates: $estimateResult['imported'],
                warnings: array_merge($invoiceResult['warnings'], $estimateResult['warnings']),
            );
        });
    }

    /** FK-safe wipe: estimates → invoices → projects → clients, then reset counters. */
    private function wipe(): void
    {
        Estimate::query()->delete();
        Invoice::query()->delete();
        Project::query()->delete();
        Client::query()->delete();
        InvoiceCounter::query()->delete();
        EstimateCounter::query()->delete();
    }
}
