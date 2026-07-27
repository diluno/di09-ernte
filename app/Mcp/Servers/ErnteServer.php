<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AcceptEstimate;
use App\Mcp\Tools\ConvertEstimateToInvoice;
use App\Mcp\Tools\CreateEstimate;
use App\Mcp\Tools\DeclineEstimate;
use App\Mcp\Tools\DraftEstimateLines;
use App\Mcp\Tools\GetEstimate;
use App\Mcp\Tools\ListClients;
use App\Mcp\Tools\ListEstimates;
use App\Mcp\Tools\SendEstimate;
use App\Mcp\Tools\UpdateEstimate;
use Laravel\Mcp\Server;

class ErnteServer extends Server
{
    protected string $name = 'ernte';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'TEXT'
    ernte is a single-operator studio admin app for a Swiss design and development
    studio. This server exposes its estimates ("Offerten").

    A typical flow: list_clients to find the client, draft_estimate_lines to turn a
    prose brief into proposed line items, then create_estimate to save it as a draft.
    Show the drafted lines and totals to the operator and get their agreement before
    calling create_estimate — drafting is free, a created estimate is a real record.

    Money is always in whole Swiss francs per hour for rates; the app computes
    subtotals, VAT and rounding itself, so never try to supply totals.

    send_estimate emails the client and stamps the validity date. It is not
    reversible. Only call it when the operator has asked for that specific estimate
    to be sent, by number.
    TEXT;

    protected function boot(): void
    {
        $this->tools[] = new ListClients;
        $this->tools[] = new ListEstimates;
        $this->tools[] = new GetEstimate;
        $this->tools[] = new DraftEstimateLines;
        $this->tools[] = new CreateEstimate;
        $this->tools[] = new UpdateEstimate;
        $this->tools[] = new SendEstimate;
        $this->tools[] = new AcceptEstimate;
        $this->tools[] = new DeclineEstimate;
        $this->tools[] = new ConvertEstimateToInvoice;
    }
}
