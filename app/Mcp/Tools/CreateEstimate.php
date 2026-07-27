<?php

namespace App\Mcp\Tools;

use App\Models\Client;
use App\Models\Project;
use App\Services\Estimating\EstimateBuilder;
use App\Support\EstimateProjections;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class CreateEstimate extends Tool
{
    protected string $name = 'create_estimate';

    protected string $description = 'Create a draft estimate from a set of line items. The draft is a real record but is not sent to anyone. Rates are in whole francs per hour; totals, VAT and rounding are computed server-side.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->required()->description('Client the estimate is for.'),
            'project_id' => $schema->integer()->description('Optional project to attach it to.'),
            'title' => $schema->string()->description('Shown at the top of the PDF.'),
            'notes' => $schema->string()->description('Optional notes shown on the PDF.'),
            'lines' => $schema->array()->required()->min(1)->description('Line items, in order.')->items(
                $schema->object([
                    'description' => $schema->string()->required(),
                    'hours' => $schema->number()->required(),
                    'rate' => $schema->integer()->required()->description('Hourly rate in whole francs.'),
                ])
            ),
        ];
    }

    public function handle(Request $request, EstimateBuilder $builder): ResponseFactory|Response
    {
        $client = Client::find($request->get('client_id'));
        if (! $client) {
            return Response::error('No client with that id. Use list_clients first.');
        }

        $lines = [];
        foreach ((array) $request->get('lines') as $line) {
            $description = trim((string) ($line['description'] ?? ''));
            if ($description === '') {
                return Response::error('Every line needs a description.');
            }
            $lines[] = [
                'description' => $description,
                'hours' => (float) ($line['hours'] ?? 0),
                'rate_rappen' => (int) round(((float) ($line['rate'] ?? 0)) * 100),
            ];
        }

        if ($lines === []) {
            return Response::error('An estimate needs at least one line.');
        }

        $estimate = $builder->createDraft(
            client: $client,
            project: $request->get('project_id') ? Project::find($request->get('project_id')) : null,
            lines: $lines,
            notes: $request->get('notes'),
            title: $request->get('title'),
        );

        return Response::structured(EstimateProjections::detail($estimate));
    }
}
