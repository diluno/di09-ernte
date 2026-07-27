<?php

namespace App\Mcp\Tools;

use App\Models\Client;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListClients extends Tool
{
    protected string $name = 'list_clients';

    protected string $description = 'List the studio\'s active clients with their projects and contacts. Use this to resolve a client name to the id that the estimate tools expect.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Optional name fragment to filter by.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $search = $request->get('search');

        $clients = Client::query()
            ->active()
            ->when($search, fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            ->with(['contacts', 'projects' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get();

        return Response::structured([
            'clients' => $clients->map(fn (Client $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'short_code' => $c->short_code,
                'contacts' => $c->contacts->map(fn ($ct) => [
                    'name' => $ct->name,
                    'email' => $ct->email,
                    'role' => $ct->role,
                    'is_default' => (bool) $ct->is_default,
                ])->values()->all(),
                'projects' => $c->projects->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'rate' => (int) round(($p->rate_rappen ?? 0) / 100),
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }
}
