<?php

namespace App\Mcp\Tools;

use App\Models\RecurringInvoiceLine;
use App\Services\Invoicing\RecurringInvoiceSchedules;
use App\Support\RecurringInvoiceProjections;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class UpdateRecurringInvoice extends Tool
{
    use ResolvesRecurringInvoices;

    protected string $name = 'update_recurring_invoice';

    protected string $description = 'Change a recurring invoice schedule. Only the fields you pass change; `lines`, when given, replaces the whole line set. Invoices already generated are not touched.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('The schedule id.'),
            'client_id' => $schema->integer()->description('Move the schedule to another client.'),
        ] + self::scheduleSchema($schema);
    }

    public function handle(Request $request, RecurringInvoiceSchedules $schedules): ResponseFactory|Response
    {
        $schedule = $this->findSchedule($request->get('id'));
        if (! $schedule) {
            return $this->scheduleNotFound($request->get('id'));
        }

        $clientId = $request->get('client_id', $schedule->client_id);
        $data = $request->validate(['id' => 'required'] + self::scheduleRules('sometimes', $clientId));
        unset($data['id']);

        // Merge onto the current schedule: the service takes a complete set.
        $merged = [
            'client_id' => $schedule->client_id,
            'project_id' => $schedule->project_id,
            'title' => $schedule->title,
            'notes' => $schedule->notes,
            'cadence' => $schedule->cadence,
            'next_run_on' => $schedule->next_run_on->toDateString(),
            'auto_send' => (bool) $schedule->auto_send,
            'lines' => $schedule->lines->map(fn (RecurringInvoiceLine $l) => [
                'description' => $l->description,
                'hours' => (float) $l->hours,
                'rate_rappen' => (int) $l->rate_rappen,
            ])->all(),
        ];
        if (array_key_exists('lines', $data)) {
            $data['lines'] = self::linesToRappen($data['lines']);
        }
        // A project only makes sense for its own client: drop it when the client moves.
        if (isset($data['client_id']) && $data['client_id'] != $schedule->client_id && ! array_key_exists('project_id', $data)) {
            $data['project_id'] = null;
        }
        $updated = $schedules->update($schedule, array_merge($merged, $data));

        return Response::structured(RecurringInvoiceProjections::detail($updated));
    }
}
