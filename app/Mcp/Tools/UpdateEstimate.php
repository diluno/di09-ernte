<?php

namespace App\Mcp\Tools;

use App\Services\Estimating\EstimateBuilder;
use App\Support\EstimateProjections;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class UpdateEstimate extends Tool
{
    use ResolvesEstimates;

    protected string $name = 'update_estimate';

    protected string $description = 'Edit a draft estimate. Only drafts can be edited. Supplying `lines` replaces every line, so send the complete set, not just the changed ones.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'number' => $schema->string()->required()->description('The estimate number, e.g. OF-2026-004.'),
            'title' => $schema->string()->description('Replaces the title.'),
            'notes' => $schema->string()->description('Replaces the notes.'),
            'lines' => $schema->array()->description('Replaces all line items, in order.')->items(
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
        $estimate = $this->findEstimate($request->get('number'));
        if (! $estimate) {
            return $this->notFound($request->get('number'));
        }

        if ($estimate->status !== 'draft') {
            return Response::error("Estimate {$estimate->number} is {$estimate->status}, and only drafts can be edited.");
        }

        $data = [];
        foreach (['title', 'notes'] as $field) {
            if ($request->get($field) !== null) {
                $data[$field] = $request->get($field);
            }
        }

        if ($request->get('lines')) {
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
            $data['lines'] = $lines;
        }

        if ($data === []) {
            return Response::error('Nothing to update — pass title, notes, or lines.');
        }

        return Response::structured(EstimateProjections::detail($builder->updateDraft($estimate, $data)));
    }
}
