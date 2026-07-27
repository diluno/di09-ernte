<?php

namespace App\Mcp\Tools;

use App\Services\Estimating\EstimateLifecycle;
use App\Support\EstimateProjections;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class DeclineEstimate extends Tool
{
    use ResolvesEstimates;

    protected string $name = 'decline_estimate';

    protected string $description = 'Mark a sent estimate as declined by the client.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'number' => $schema->string()->required()->description('The estimate number, e.g. OF-2026-004.'),
        ];
    }

    public function handle(Request $request, EstimateLifecycle $lifecycle): ResponseFactory|Response
    {
        $estimate = $this->findEstimate($request->get('number'));
        if (! $estimate) {
            return $this->notFound($request->get('number'));
        }

        try {
            $lifecycle->decline($estimate);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured([
            'result' => "Marked {$estimate->number} as declined.",
            'estimate' => EstimateProjections::detail($estimate->fresh()),
        ]);
    }
}
