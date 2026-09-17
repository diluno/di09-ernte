<?php

namespace App\Mcp\Tools;

use App\Services\Estimating\EstimateBuilder;
use App\Support\EstimateInputRules;
use App\Support\EstimateProjections;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class UpdateEstimate extends Tool
{
    use MapsEstimateLines;
    use ResolvesEstimates;

    protected string $name = 'update_estimate';

    protected string $description = 'Edit a draft estimate. Only drafts can be edited. Supplying `lines` replaces every line, so send the complete set, not just the changed ones.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'number' => $schema->string()->required()->description('The estimate number, e.g. OF-2026-004.'),
            'title' => $schema->string()->description('Replaces the title.'),
            'notes' => $schema->string()->description('Replaces the notes.'),
            'lines' => $schema->array()->description('Replaces all line items (and any sections), in order. Each needs a title or a description.')->items(
                self::lineSchema($schema)
            ),
            'sections' => $schema->array()->description('Alternative to `lines`: line items grouped into named sections, shown on the PDF with a heading, subtotal and a package overview. Replaces all sections and lines.')->items(
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
        $estimate = $this->findEstimate($request->get('number'));
        if (! $estimate) {
            return $this->notFound($request->get('number'));
        }

        if ($estimate->status !== 'draft') {
            return Response::error("Estimate {$estimate->number} is {$estimate->status}, and only drafts can be edited.");
        }

        $data = $request->validate(EstimateInputRules::update($estimate->client_id, 'rate'));

        try {
            if (array_key_exists('lines', $data)) {
                $data['lines'] = self::toRappenLines($data['lines']);
            }
            if (array_key_exists('sections', $data)) {
                $data['sections'] = self::toRappenSections($data['sections']);
            }
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        if ($data === []) {
            return Response::error('Nothing to update — pass title, notes, assumptions, lines, or sections.');
        }

        return Response::structured(EstimateProjections::detail($builder->updateDraft($estimate, $data)));
    }
}
