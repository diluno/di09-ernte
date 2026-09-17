<?php

namespace App\Mcp\Tools;

use App\Models\Invoice;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListInvoices extends Tool
{
    protected string $name = 'list_invoices';

    protected string $description = 'List invoices, newest first, optionally filtered by status or client. Returns a summary per invoice — use get_invoice for the line items.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('Filter by status: draft, sent, paid, or void.'),
            'client_id' => $schema->integer()->description('Filter to one client.'),
            'limit' => $schema->integer()->description('How many to return (default 20, max 100).'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $limit = min(100, max(1, (int) ($request->get('limit') ?: 20)));

        $invoices = Invoice::query()
            ->when($request->get('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->get('client_id'), fn ($q, $id) => $q->where('client_id', $id))
            ->with('client:id,name')
            ->latest('id')
            ->limit($limit)
            ->get();

        return Response::structured([
            'invoices' => $invoices->map(fn (Invoice $i) => [
                'number' => $i->number,
                'title' => $i->title,
                'client' => $i->client?->name,
                'status' => $i->status,
                'overdue' => $i->overdue,
                'total' => round($i->total_rappen / 100, 2),
                'issued_on' => $i->issued_on?->toDateString(),
                'due_on' => $i->due_on?->toDateString(),
            ])->values()->all(),
        ]);
    }
}
