<?php

namespace App\Mcp\Tools;

use App\Support\InvoiceProjections;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetInvoice extends Tool
{
    use ResolvesInvoices;

    protected string $name = 'get_invoice';

    protected string $description = 'Fetch one invoice in full — line items, totals, VAT, dates, recipients and status — by its number.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'number' => $schema->string()->required()->description('The invoice number.'),
        ];
    }

    public function handle(Request $request): ResponseFactory|Response
    {
        $invoice = $this->findInvoice($request->get('number'));

        if (! $invoice) {
            return $this->notFound($request->get('number'));
        }

        return Response::structured(InvoiceProjections::detail($invoice));
    }
}
