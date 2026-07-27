<?php

namespace App\Mcp\Tools;

use App\Services\Estimating\EstimateLifecycle;
use App\Support\EstimateProjections;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class SendEstimate extends Tool
{
    use ResolvesEstimates;

    protected string $name = 'send_estimate';

    protected string $description = 'Email an estimate to its recipients and stamp the validity date. NOT REVERSIBLE — the client receives it. Only call this when the operator has explicitly asked for this specific estimate, by number, to be sent.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'number' => $schema->string()->required()->description('The estimate number to send, e.g. OF-2026-004.'),
        ];
    }

    public function handle(Request $request, EstimateLifecycle $lifecycle): ResponseFactory|Response
    {
        $estimate = $this->findEstimate($request->get('number'));
        if (! $estimate) {
            return $this->notFound($request->get('number'));
        }

        try {
            $lifecycle->send($estimate);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }

        $fresh = $estimate->fresh();

        return Response::structured([
            'result' => "Sent {$fresh->number}.",
            'recipients' => $fresh->recipients,
            'estimate' => EstimateProjections::detail($fresh),
        ]);
    }
}
