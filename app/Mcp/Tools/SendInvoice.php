<?php

namespace App\Mcp\Tools;

use App\Services\Invoicing\InvoiceLifecycle;
use App\Support\InvoiceProjections;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class SendInvoice extends Tool
{
    use ResolvesInvoices;

    protected string $name = 'send_invoice';

    protected string $description = 'Issue a draft invoice: stamp issue and due dates, render the PDF with QR bill, and email it to its recipients. NOT REVERSIBLE — the client receives it. Only call this when the operator has explicitly asked for this specific invoice, by number, to be sent.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'number' => $schema->string()->required()->description('The draft invoice number to send.'),
        ];
    }

    public function handle(Request $request, InvoiceLifecycle $lifecycle): ResponseFactory|Response
    {
        $invoice = $this->findInvoice($request->get('number'));
        if (! $invoice) {
            return $this->notFound($request->get('number'));
        }

        try {
            $lifecycle->issue($invoice);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }

        $fresh = $invoice->fresh();

        return Response::structured([
            'result' => "Sent {$fresh->number}.",
            'invoice' => InvoiceProjections::detail($fresh),
        ]);
    }
}
