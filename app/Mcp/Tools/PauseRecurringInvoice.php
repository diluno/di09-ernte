<?php

namespace App\Mcp\Tools;

use App\Services\Invoicing\RecurringInvoiceSchedules;
use App\Support\RecurringInvoiceProjections;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class PauseRecurringInvoice extends Tool
{
    use ResolvesRecurringInvoices;

    protected string $name = 'pause_recurring_invoice';

    protected string $description = 'Pause a recurring invoice schedule: no invoices are generated until it is resumed.';

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->required()->description('The schedule id.')];
    }

    public function handle(Request $request, RecurringInvoiceSchedules $schedules): ResponseFactory|Response
    {
        $schedule = $this->findSchedule($request->get('id'));
        if (! $schedule) {
            return $this->scheduleNotFound($request->get('id'));
        }
        if ($schedule->isPaused()) {
            return Response::error("Recurring invoice {$schedule->id} is already paused.");
        }

        return Response::structured(RecurringInvoiceProjections::detail($schedules->pause($schedule)));
    }
}
