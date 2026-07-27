<?php

namespace App\Mcp\Tools;

use App\Services\Estimating\EstimateLifecycle;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ConvertEstimateToInvoice extends Tool
{
    use ResolvesEstimates;

    protected string $name = 'convert_estimate_to_invoice';

    protected string $description = 'Turn an accepted estimate into a draft invoice, copying its lines across. The invoice is a draft — it is not sent, and it gets its QR bill when it is issued.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'number' => $schema->string()->required()->description('The accepted estimate number, e.g. OF-2026-004.'),
        ];
    }

    public function handle(Request $request, EstimateLifecycle $lifecycle): ResponseFactory|Response
    {
        $estimate = $this->findEstimate($request->get('number'));
        if (! $estimate) {
            return $this->notFound($request->get('number'));
        }

        try {
            $invoice = $lifecycle->convertToInvoice($estimate);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured([
            'result' => "Converted {$estimate->number} into draft invoice {$invoice->number}.",
            'invoice' => [
                'number' => $invoice->number,
                'status' => $invoice->status,
                'total' => round($invoice->total_rappen / 100, 2),
            ],
        ]);
    }
}
