<?php

namespace App\Mcp\Tools;

use App\Support\RecurringInvoiceProjections;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetRecurringInvoice extends Tool
{
    use ResolvesRecurringInvoices;

    protected string $name = 'get_recurring_invoice';

    protected string $description = 'Fetch one recurring invoice schedule in full: lines, cadence, next run with the period and title the next invoice will get, recipients, and the most recent invoices it generated.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('The schedule id (from list_recurring_invoices).'),
        ];
    }

    public function handle(Request $request): ResponseFactory|Response
    {
        $schedule = $this->findSchedule($request->get('id'));

        return $schedule
            ? Response::structured(RecurringInvoiceProjections::detail($schedule))
            : $this->scheduleNotFound($request->get('id'));
    }
}
