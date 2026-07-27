<?php

namespace App\Mcp\Tools;

use App\Support\EstimateProjections;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetEstimate extends Tool
{
    use ResolvesEstimates;

    protected string $name = 'get_estimate';

    protected string $description = 'Fetch one estimate in full — line items, totals, VAT, dates and status — by its number (e.g. OF-2026-004).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'number' => $schema->string()->required()->description('The estimate number, e.g. OF-2026-004.'),
        ];
    }

    public function handle(Request $request): ResponseFactory|Response
    {
        $estimate = $this->findEstimate($request->get('number'));

        if (! $estimate) {
            return $this->notFound($request->get('number'));
        }

        return Response::structured(EstimateProjections::detail($estimate));
    }
}
