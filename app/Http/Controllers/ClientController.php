<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Client;
use App\Support\ClientDetail;
use App\Support\ClientProjections;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Clients/Index', [
            'clients' => ClientProjections::index()->values(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Clients/Create');
    }

    public function show(Client $client): Response
    {
        return Inertia::render('Clients/Show', ClientDetail::payload($client));
    }

    public function store(StoreClientRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $contacts = $data['contacts'] ?? [];
        unset($data['contacts']);

        $client = Client::create($data);
        $this->syncContacts($client, $contacts);

        return redirect('/clients');
    }

    public function edit(Client $client): Response
    {
        $client->load('contacts');

        return Inertia::render('Clients/Edit', [
            'client' => [
                ...$client->only([
                    'id', 'name', 'short_code',
                    'address_line_1', 'address_line_2', 'postal_code', 'city', 'country',
                    'vat_id', 'default_rate_rappen',
                ]),
                'contacts' => $client->contacts->map(fn ($c) => [
                    'id' => $c->id, 'name' => $c->name, 'email' => $c->email,
                    'role' => $c->role, 'is_default' => $c->is_default, 'sort_order' => $c->sort_order,
                ])->values(),
            ],
        ]);
    }

    public function update(UpdateClientRequest $request, Client $client): RedirectResponse
    {
        $data = $request->validated();
        $contacts = $data['contacts'] ?? null;
        unset($data['contacts']);

        $client->update($data);
        if ($contacts !== null) {
            $this->syncContacts($client, $contacts);
        }

        return redirect("/clients/{$client->id}");
    }

    /** Create/update/delete a client's contacts from a submitted array. */
    private function syncContacts(Client $client, array $contacts): void
    {
        $keptIds = [];
        foreach ($contacts as $i => $row) {
            $attrs = [
                'name' => $row['name'],
                'email' => $row['email'],
                'role' => $row['role'] ?? null,
                'is_default' => (bool) ($row['is_default'] ?? false),
                'sort_order' => $i,
            ];
            if (! empty($row['id'])) {
                $client->contacts()->whereKey($row['id'])->update($attrs);
                $keptIds[] = (int) $row['id'];
            } else {
                $keptIds[] = $client->contacts()->create($attrs)->id;
            }
        }
        $client->contacts()->whereNotIn('id', $keptIds ?: [0])->delete();
    }

    public function destroy(Client $client): RedirectResponse
    {
        // Soft-archive instead of delete (preserves FK integrity for projects/invoices).
        $client->update(['archived_at' => now()]);

        return redirect('/clients');
    }
}
