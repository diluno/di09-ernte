<?php

namespace App\Mcp\Tools;

use App\Services\Invoicing\RecurringInvoiceGenerator;
use App\Support\InvoiceProjections;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class RunRecurringInvoice extends Tool
{
    use ResolvesRecurringInvoices;

    protected string $name = 'run_recurring_invoice';

    protected string $description = 'Generate the next invoice of a recurring schedule now instead of waiting for its run date, and advance the schedule by one period. If the schedule has auto_send on, the invoice is also issued and EMAILED to the client — not reversible. Only call this when the operator asked for this specific schedule to be run.';

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->required()->description('The schedule id.')];
    }

    public function handle(Request $request, RecurringInvoiceGenerator $generator): ResponseFactory|Response
    {
        $schedule = $this->findSchedule($request->get('id'));
        if (! $schedule) {
            return $this->scheduleNotFound($request->get('id'));
        }
        if ($schedule->isPaused()) {
            return Response::error("Recurring invoice {$schedule->id} is paused. Resume it first.");
        }

        try {
            $invoice = $generator->generate($schedule, Carbon::parse($schedule->next_run_on));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }

        $invoice = $invoice->fresh(['client', 'project', 'lines']);

        return Response::structured([
            'result' => $invoice->status === 'draft'
                ? "Generated draft invoice {$invoice->number}."
                : "Generated and sent invoice {$invoice->number}.",
            'next_run_on' => $schedule->fresh()->next_run_on?->toDateString(),
            'invoice' => InvoiceProjections::detail($invoice),
        ]);
    }
}
