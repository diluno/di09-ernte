<?php

namespace App\Console\Commands;

use App\Models\TimeEntry;
use App\Services\Harvest\HarvestApi;
use App\Services\Harvest\HarvestApiException;
use App\Services\Harvest\ImportRunner;
use Illuminate\Console\Command;

class HarvestImportCommand extends Command
{
    protected $signature = 'harvest:import
        {--token= : Harvest personal access token (defaults to HARVEST_ACCESS_TOKEN)}
        {--account= : Harvest account id (defaults to HARVEST_ACCOUNT_ID)}
        {--dry-run : Fetch and report counts without writing anything}
        {--force : Skip the destructive confirmation prompt}';

    protected $description = 'One-time import of clients, projects, invoices and estimates from Harvest.';

    public function handle(ImportRunner $runner): int
    {
        $token = $this->option('token') ?: config('services.harvest.access_token');
        $account = $this->option('account') ?: config('services.harvest.account_id');

        if (! $token || ! $account) {
            $this->error('Missing Harvest credentials. Pass --token and --account, or set HARVEST_ACCESS_TOKEN and HARVEST_ACCOUNT_ID.');
            return self::FAILURE;
        }

        $api = new HarvestApi(
            $token,
            $account,
            config('services.harvest.base_url'),
            config('services.harvest.user_agent'),
        );

        try {
            $this->info('Fetching from Harvest…');
            $data = $runner->fetch($api);
        } catch (HarvestApiException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $counts = sprintf(
            '%d clients, %d projects, %d invoices, %d estimates',
            count($data->clients), count($data->projects), count($data->invoices), count($data->estimates),
        );

        if ($this->option('dry-run')) {
            $this->info("Dry run — would import: {$counts}. Nothing written.");
            return self::SUCCESS;
        }

        if (! $this->confirmDestructive()) {
            $this->warn('Aborted. Nothing was changed.');
            return self::FAILURE;
        }

        $summary = $runner->import($data);

        $this->info(sprintf(
            'Imported %d clients, %d projects, %d invoices, %d estimates.',
            $summary->clients, $summary->projects, $summary->invoices, $summary->estimates,
        ));
        foreach ($summary->warnings as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }

    private function confirmDestructive(): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $timeEntries = TimeEntry::count();
        $message = $timeEntries > 0
            ? "This will DELETE all clients, projects, invoices and estimates (and {$timeEntries} time entr(y/ies) + their tasks). Continue?"
            : 'This will DELETE all clients, projects, invoices and estimates in ernte. Continue?';

        return $this->confirm($message);
    }
}
