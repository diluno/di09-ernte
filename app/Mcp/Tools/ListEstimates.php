<?php

namespace App\Mcp\Tools;

use App\Models\Estimate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListEstimates extends Tool
{
    protected string $name = 'list_estimates';

    protected string $description = 'List estimates, newest first, optionally filtered by status or client. Returns a summary per estimate — use get_estimate for the line items.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('Filter by status: draft, sent, accepted, or declined.'),
            'client_id' => $schema->integer()->description('Filter to one client.'),
            'limit' => $schema->integer()->description('How many to return (default 20, max 100).'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $limit = min(100, max(1, (int) ($request->get('limit') ?: 20)));

        $estimates = Estimate::query()
            ->when($request->get('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->get('client_id'), fn ($q, $id) => $q->where('client_id', $id))
            ->with('client:id,name')
            ->latest('id')
            ->limit($limit)
            ->get();

        return Response::structured([
            'estimates' => $estimates->map(fn (Estimate $e) => [
                'number' => $e->number,
                'title' => $e->title,
                'client' => $e->client?->name,
                'status' => $e->status,
                'total' => round($e->total_rappen / 100, 2),
                'issued_on' => $e->issued_on?->toDateString(),
                'valid_until' => $e->valid_until?->toDateString(),
            ])->values()->all(),
        ]);
    }
}
