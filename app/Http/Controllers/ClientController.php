<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Client;
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

    public function store(StoreClientRequest $request): RedirectResponse
    {
        Client::create($request->validated());
        return redirect('/clients');
    }

    public function edit(Client $client): Response
    {
        return Inertia::render('Clients/Edit', [
            'client' => $client->only([
                'id', 'name', 'short_code', 'contact_name', 'email',
                'address_line_1', 'address_line_2', 'postal_code', 'city', 'country',
                'vat_id', 'default_rate_rappen',
            ]),
        ]);
    }

    public function update(UpdateClientRequest $request, Client $client): RedirectResponse
    {
        $client->update($request->validated());
        return back();
    }

    public function destroy(Client $client): RedirectResponse
    {
        // Soft-archive instead of delete (preserves FK integrity for projects/invoices).
        $client->update(['archived_at' => now()]);
        return redirect('/clients');
    }
}
