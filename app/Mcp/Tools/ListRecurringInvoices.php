<?php

namespace App\Mcp\Tools;

use App\Models\RecurringInvoice;
use App\Support\RecurringInvoiceProjections;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListRecurringInvoices extends Tool
{
    protected string $name = 'list_recurring_invoices';

    protected string $description = 'List recurring invoice schedules (active first, then by next run date), optionally filtered by state or client. Returns a summary per schedule — use get_recurring_invoice for lines and recent invoices.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'state' => $schema->string()->enum(['active', 'paused'])->description('Only active or only paused schedules.'),
            'client_id' => $schema->integer()->description('Filter to one client.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $schedules = RecurringInvoice::query()
            ->with(['client:id,name', 'lines'])
            ->when($request->get('state') === 'active', fn ($q) => $q->whereNull('paused_at'))
            ->when($request->get('state') === 'paused', fn ($q) => $q->whereNotNull('paused_at'))
            ->when($request->get('client_id'), fn ($q, $id) => $q->where('client_id', $id))
            ->orderByRaw('paused_at IS NOT NULL')
            ->orderBy('next_run_on')
            ->get();

        return Response::structured([
            'recurring_invoices' => $schedules->map(fn ($s) => RecurringInvoiceProjections::summary($s))->values()->all(),
        ]);
    }
}
