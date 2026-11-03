<?php

namespace App\Mcp\Tools;

use App\Services\Invoicing\RecurringInvoiceSchedules;
use App\Support\RecurringInvoiceProjections;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ResumeRecurringInvoice extends Tool
{
    use ResolvesRecurringInvoices;

    protected string $name = 'resume_recurring_invoice';

    protected string $description = 'Resume a paused recurring invoice schedule. The next run moves forward to the next date on or after today; periods missed while paused are not invoiced.';

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
        if (! $schedule->isPaused()) {
            return Response::error("Recurring invoice {$schedule->id} is not paused.");
        }

        return Response::structured(RecurringInvoiceProjections::detail($schedules->resume($schedule)));
    }
}
