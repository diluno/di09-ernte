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
    use MapsEstimateLines;

    protected string $name = 'create_estimate';

    protected string $description = 'Create a draft estimate from a set of line items. The draft is a real record but is not sent to anyone. Rates are in francs per hour; totals, VAT and rounding are computed server-side.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->required()->description('Client the estimate is for.'),
            'project_id' => $schema->integer()->description('Optional project to attach it to.'),
            'title' => $schema->string()->description('Shown at the top of the PDF.'),
            'notes' => $schema->string()->description('Optional notes shown on the PDF.'),
            'lines' => $schema->array()->description('Line items, in order. Each needs a title or a description. Required unless `sections` is given.')->items(
                self::lineSchema($schema)
            ),
            'sections' => $schema->array()->description('Alternative to `lines`: line items grouped into named sections, shown on the PDF with a heading, subtotal and a package overview. Use this for multi-part proposals.')->items(
                $schema->object([
                    'label' => $schema->string()->max(120)->description('Short section label, e.g. "Bündel 1". Do not repeat it in line titles.'),
                    'title' => $schema->string()->max(255)->description('Optional descriptive title, e.g. "Regionale Webapp".'),
                    'lines' => $schema->array()->required()->min(1)->items(self::lineSchema($schema)),
                ])
            ),
            'assumptions' => $schema->array()->items($schema->string()->max(500))->description('Short bullet points shown as "Grundlagen der Schätzung" after the totals.'),
        ];
    }

    public function handle(Request $request, EstimateBuilder $builder): ResponseFactory|Response
    {
        $data = $request->validate(EstimateInputRules::create($request->get('client_id'), 'rate'));
        $client = Client::findOrFail($data['client_id']);

        try {
            $lines = isset($data['lines']) ? self::toRappenLines($data['lines']) : [];
            $sections = isset($data['sections']) ? self::toRappenSections($data['sections']) : null;
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        $estimate = $builder->createDraft(
            client: $client,
            project: isset($data['project_id']) ? Project::findOrFail($data['project_id']) : null,
            lines: $lines,
            notes: $data['notes'] ?? null,
            title: $data['title'] ?? null,
            sections: $sections,
            assumptions: $data['assumptions'] ?? null,
        );

        return Response::structured(EstimateProjections::detail($estimate));
    }
}
