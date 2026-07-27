<?php

namespace App\Mcp\Tools;

use App\Services\Estimating\EstimateLifecycle;
use App\Support\EstimateProjections;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class AcceptEstimate extends Tool
{
    use ResolvesEstimates;

    protected string $name = 'accept_estimate';

    protected string $description = 'Mark a sent estimate as accepted by the client. An accepted estimate can then be converted into a draft invoice.';

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
            $lifecycle->accept($estimate);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured([
            'result' => "Marked {$estimate->number} as accepted.",
            'estimate' => EstimateProjections::detail($estimate->fresh()),
        ]);
    }
}
