<?php

namespace App\Mcp\Tools;

use App\Services\Invoicing\RecurringInvoiceSchedules;
use App\Support\RecurringInvoiceProjections;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class CreateRecurringInvoice extends Tool
{
    use ResolvesRecurringInvoices;

    protected string $name = 'create_recurring_invoice';

    protected string $description = 'Create a recurring invoice schedule: the same lines are invoiced every period (monthly, quarterly, half-yearly or yearly) from next_run_on on. Nothing is generated or sent by this call. Each generated invoice uses the VAT rate in force at the end of the period it bills (shown as `vat_rate` for the next invoice); totals are computed server-side.';

    public function schema(JsonSchema $schema): array
    {
        $fields = self::scheduleSchema($schema);
        foreach (['cadence', 'next_run_on', 'lines'] as $required) {
            $fields[$required] = $fields[$required]->required();
        }

        return ['client_id' => $schema->integer()->required()->description('Client to invoice.')] + $fields;
    }

    public function handle(Request $request, RecurringInvoiceSchedules $schedules): ResponseFactory|Response
    {
        $data = $request->validate(self::scheduleRules('required', $request->get('client_id')));
        $data['lines'] = self::linesToRappen($data['lines']);

        $schedule = $schedules->create($data);

        return Response::structured(RecurringInvoiceProjections::detail($schedule));
    }
}
