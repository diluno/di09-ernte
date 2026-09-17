<?php

namespace App\Mcp\Tools;

use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\Invoicing\InvoiceBuilder;
use App\Support\InvoiceProjections;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class CreateInvoice extends Tool
{
    protected string $name = 'create_invoice';

    protected string $description = 'Create a draft invoice. Either pass explicit `lines`, or set `from_time_entries` to bill the client\'s unbilled billable time in the period (grouped into lines at project rates, and linked so it is not billed twice). The draft is not sent. Rates are in francs per hour; totals, VAT and rounding are computed server-side.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->required()->description('Client the invoice is for.'),
            'project_id' => $schema->integer()->description('Optional project; with from_time_entries, restricts to that project\'s time.'),
            'title' => $schema->string()->max(255)->description('Shown at the top of the PDF.'),
            'notes' => $schema->string()->description('Optional notes shown on the PDF.'),
            'period_start' => $schema->string()->description('Billing period start (YYYY-MM-DD). Defaults to the start of last month when from_time_entries is set.'),
            'period_end' => $schema->string()->description('Billing period end (YYYY-MM-DD). Defaults to the end of last month when from_time_entries is set.'),
            'from_time_entries' => $schema->boolean()->description('Build the lines from unbilled time instead of `lines`.'),
            'lines' => $schema->array()->description('Line items, in order. Required unless from_time_entries is set.')->items(
                $schema->object([
                    'description' => $schema->string()->required()->max(1000),
                    'hours' => $schema->number()->required()->min(0),
                    'rate' => $schema->number()->required()->min(0)->description('Hourly rate in francs.'),
                    'vat_exempt' => $schema->boolean()->description('True if no VAT applies to this line.'),
                ])
            ),
        ];
    }

    public function handle(Request $request, InvoiceBuilder $builder): ResponseFactory|Response
    {
        $data = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'project_id' => ['nullable', Rule::exists('projects', 'id')->where(fn ($q) => $q->where('client_id', $request->get('client_id')))],
            'title' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:5000',
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date|after_or_equal:period_start',
            'from_time_entries' => 'sometimes|boolean',
            'lines' => 'required_unless:from_time_entries,true|array|min:1',
            'lines.*.description' => 'required|string|max:1000',
            'lines.*.hours' => 'required|numeric|min:0',
            'lines.*.rate' => 'required|numeric|min:0',
            'lines.*.vat_exempt' => 'sometimes|boolean',
        ]);

        $client = Client::findOrFail($data['client_id']);
        $project = isset($data['project_id']) ? Project::findOrFail($data['project_id']) : null;
        $periodStart = $data['period_start'] ?? null;
        $periodEnd = $data['period_end'] ?? null;
        $entryIds = [];

        if ($data['from_time_entries'] ?? false) {
            $start = $periodStart ? Carbon::parse($periodStart)->startOfDay() : Carbon::now()->subMonthNoOverflow()->startOfMonth();
            $end = $periodEnd ? Carbon::parse($periodEnd)->endOfDay() : Carbon::now()->subMonthNoOverflow()->endOfMonth();
            $periodStart = $start->toDateString();
            $periodEnd = $end->toDateString();

            // Same query as the web "New invoice" editor.
            $entries = TimeEntry::query()
                ->with(['project:id,name,code,rate_rappen'])
                ->where('billable', true)
                ->whereNull('invoice_id')
                ->finished()
                ->whereBetween('started_at', [$start, $end])
                ->when($project, fn ($q) => $q->where('project_id', $project->id),
                    fn ($q) => $q->whereIn('project_id', $client->projects()->pluck('id')))
                ->get();

            $lines = $builder->suggestLinesFromEntries($entries, $project, $end);
            if (empty($lines)) {
                return Response::error("No unbilled billable time for {$client->name} between {$periodStart} and {$periodEnd}.");
            }
            $entryIds = array_merge(...array_column($lines, 'entry_ids'));
        } else {
            $lines = array_map(fn (array $l) => [
                'description' => trim($l['description']),
                'hours' => (float) $l['hours'],
                'rate_rappen' => (int) round(((float) $l['rate']) * 100),
                'vat_exempt' => (bool) ($l['vat_exempt'] ?? false),
            ], array_values($data['lines']));
        }

        $invoice = $builder->createDraft(
            client: $client,
            project: $project,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            lines: $lines,
            entryIds: $entryIds,
            title: $data['title'] ?? null,
            notes: $data['notes'] ?? null,
        );

        return Response::structured(InvoiceProjections::detail($invoice) + ['linked_time_entries' => count($entryIds)]);
    }
}
