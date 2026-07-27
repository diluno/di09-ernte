<?php

namespace App\Mcp\Tools;

use App\Models\Client;
use App\Models\Project;
use App\Services\Estimating\EstimateDrafter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class DraftEstimateLines extends Tool
{
    protected string $name = 'draft_estimate_lines';

    protected string $description = 'Turn a prose brief into proposed estimate line items, grounded in this client\'s past estimates and the studio\'s house style. Persists nothing — pass the result to create_estimate once the operator agrees.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'brief' => $schema->string()->required()->description('Prose description of the job to be quoted.'),
            'client_id' => $schema->integer()->required()->description('Client the estimate is for (see list_clients).'),
            'project_id' => $schema->integer()->description('Optional project, used for its usual hourly rate.'),
        ];
    }

    public function handle(Request $request, EstimateDrafter $drafter): ResponseFactory|Response
    {
        $client = Client::find($request->get('client_id'));
        if (! $client) {
            return Response::error('No client with that id. Use list_clients first.');
        }

        try {
            $draft = $drafter->draft(
                brief: (string) $request->get('brief'),
                client: $client,
                project: $request->get('project_id') ? Project::find($request->get('project_id')) : null,
            );
        } catch (\Throwable $e) {
            return Response::error('Drafting failed: '.$e->getMessage());
        }

        return Response::structured($draft + ['note' => 'Nothing was saved. Call create_estimate to persist these lines.']);
    }
}
