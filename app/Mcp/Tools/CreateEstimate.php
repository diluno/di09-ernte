<?php

namespace App\Mcp\Tools;

use App\Models\Client;
use App\Models\Project;
use App\Services\Estimating\EstimateBuilder;
use App\Support\EstimateInputRules;
use App\Support\EstimateProjections;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class CreateEstimate extends Tool
{
    protected string $name = 'create_estimate';

    protected string $description = 'Create a draft estimate from a set of line items. The draft is a real record but is not sent to anyone. Rates are in francs per hour; totals, VAT and rounding are computed server-side.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->required()->description('Client the estimate is for.'),
            'project_id' => $schema->integer()->description('Optional project to attach it to.'),
            'title' => $schema->string()->description('Shown at the top of the PDF.'),
            'notes' => $schema->string()->description('Optional notes shown on the PDF.'),
            'lines' => $schema->array()->required()->min(1)->description('Line items, in order.')->items(
                $schema->object([
                    'description' => $schema->string()->required()->max(1000),
                    'hours' => $schema->number()->required()->min(0),
                    'rate' => $schema->number()->required()->min(0)->description('Hourly rate in francs.'),
                ])
            ),
        ];
    }

    public function handle(Request $request, EstimateBuilder $builder): ResponseFactory|Response
    {
        $data = $request->validate(EstimateInputRules::create($request->get('client_id'), 'rate'));
        $client = Client::findOrFail($data['client_id']);

        $lines = [];
        foreach ($data['lines'] as $line) {
            $description = trim($line['description']);
            if ($description === '') {
                return Response::error('Every line needs a description.');
            }
            $lines[] = [
                'description' => $description,
                'hours' => (float) $line['hours'],
                'rate_rappen' => (int) round(((float) $line['rate']) * 100),
            ];
        }

        $estimate = $builder->createDraft(
            client: $client,
            project: isset($data['project_id']) ? Project::findOrFail($data['project_id']) : null,
            lines: $lines,
            notes: $data['notes'] ?? null,
            title: $data['title'] ?? null,
        );

        return Response::structured(EstimateProjections::detail($estimate));
    }
}
