<?php

namespace App\Mcp\Tools;

use App\Models\RecurringInvoice;
use Laravel\Mcp\Response;

/** Shared lookup for the tools that act on one recurring schedule, by id. */
trait ResolvesRecurringInvoices
{
    protected function findSchedule(mixed $id): ?RecurringInvoice
    {
        if (! is_numeric($id)) {
            return null;
        }

        return RecurringInvoice::query()
            ->with(['client', 'project', 'lines' => fn ($q) => $q->orderBy('sort_order')])
            ->find((int) $id);
    }

    protected function scheduleNotFound(mixed $id): Response
    {
        return Response::error("No recurring invoice with id '{$id}'. Use list_recurring_invoices to see what exists.");
    }

    /** Validation shared by create and update; `rate` is francs per hour. */
    protected static function scheduleRules(string $presence, mixed $clientId): array
    {
        return [
            'client_id' => "{$presence}|integer|exists:clients,id",
            'project_id' => ['sometimes', 'nullable', 'integer', \Illuminate\Validation\Rule::exists('projects', 'id')->where(fn ($q) => $q->where('client_id', $clientId))],
            'title' => 'sometimes|nullable|string|max:255',
            'notes' => 'sometimes|nullable|string|max:20000',
            'cadence' => "{$presence}|in:monthly,quarterly,half-yearly,yearly",
            'next_run_on' => "{$presence}|date",
            'auto_send' => 'sometimes|boolean',
            'lines' => "{$presence}|array|min:1",
            'lines.*.description' => 'required|string|max:1000',
            'lines.*.hours' => 'required|numeric|min:0',
            'lines.*.rate' => 'required|numeric|min:0',
            'recipients' => 'sometimes|array',
            'recipients.*.name' => 'required|string|max:255',
            'recipients.*.email' => 'required|email|max:255',
        ];
    }

    /** @return array<int, array{description:string, hours:float, rate_rappen:int}> */
    protected static function linesToRappen(array $lines): array
    {
        return array_map(fn (array $l) => [
            'description' => trim($l['description']),
            'hours' => (float) $l['hours'],
            'rate_rappen' => (int) round(((float) $l['rate']) * 100),
        ], array_values($lines));
    }

    protected static function scheduleSchema(\Illuminate\Contracts\JsonSchema\JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('Optional project of the same client.'),
            'title' => $schema->string()->max(255)->description('Invoice title. "{period}" is replaced with the billing period, e.g. "Hosting – {period}" becomes "Hosting – June 2026".'),
            'notes' => $schema->string()->description('Optional notes printed on each invoice.'),
            'cadence' => $schema->string()->enum(['monthly', 'quarterly', 'half-yearly', 'yearly'])->description('How often an invoice is generated.'),
            'next_run_on' => $schema->string()->description('Date of the next (or first) invoice, YYYY-MM-DD. Its day of month becomes the billing anchor. A date in the past is moved forward to the next matching date; missed periods are never backfilled.'),
            'auto_send' => $schema->boolean()->description('If true, each generated invoice is issued and emailed to the client automatically. If false (default), it is left as a draft for review.'),
            'lines' => $schema->array()->description('Line items billed every period, in order.')->items(
                $schema->object([
                    'description' => $schema->string()->required()->max(1000),
                    'hours' => $schema->number()->required()->min(0)->description('Quantity; use 1 for a flat fee.'),
                    'rate' => $schema->number()->required()->min(0)->description('Rate in francs (per hour, or the flat fee).'),
                ])
            ),
            'recipients' => $schema->array()->description('Who receives the invoices. Defaults to the client\'s default contacts.')->items(
                $schema->object([
                    'name' => $schema->string()->required(),
                    'email' => $schema->string()->required(),
                ])
            ),
        ];
    }
}
