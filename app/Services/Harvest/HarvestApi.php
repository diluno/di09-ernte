<?php

namespace App\Services\Harvest;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class HarvestApi
{
    public function __construct(
        private string $token,
        private string $accountId,
        private string $baseUrl = 'https://api.harvestapp.com/v2',
        private string $userAgent = 'ernte-import (https://github.com/diluno/di09-ernte)',
    ) {}

    public function clients(): Collection   { return $this->paginate('clients', 'clients'); }
    public function contacts(): Collection  { return $this->paginate('contacts', 'contacts'); }
    public function projects(): Collection  { return $this->paginate('projects', 'projects'); }
    public function invoices(): Collection   { return $this->paginate('invoices', 'invoices'); }
    public function estimates(): Collection  { return $this->paginate('estimates', 'estimates'); }

    /** Fetch every page of a list endpoint, concatenating the rows under $key. */
    private function paginate(string $path, string $key): Collection
    {
        $rows = collect();
        $page = 1;

        do {
            $body = $this->get($path, ['page' => $page, 'per_page' => 100]);
            $rows = $rows->concat($body[$key] ?? []);
            $page = $body['next_page'] ?? null;
        } while ($page !== null);

        return $rows;
    }

    /** Single GET with one retry on HTTP 429 (honouring Retry-After). */
    private function get(string $path, array $query): array
    {
        $response = $this->http()->get($path, $query);

        if ($response->status() === 429) {
            sleep((int) ($response->header('Retry-After') ?: 1));
            $response = $this->http()->get($path, $query);
        }

        if (! $response->successful()) {
            throw new HarvestApiException("Harvest API GET /{$path} failed: HTTP {$response->status()} {$response->body()}");
        }

        return $response->json() ?? [];
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->withHeaders([
                'Authorization' => "Bearer {$this->token}",
                'Harvest-Account-Id' => $this->accountId,
                'User-Agent' => $this->userAgent,
            ]);
    }
}
