<?php

namespace App\Services\Harvest;

use App\Models\Client;
use App\Support\PostalAddress;

class ClientImporter
{
    /** @var array<string,string> short codes already used in this run */
    private array $usedCodes = [];

    /**
     * @param  array<int,array>  $harvestClients
     * @param  array<int,array>  $harvestContacts
     * @return array<int,Client>  map of harvest client id => ernte Client
     */
    public function import(array $harvestClients, array $harvestContacts): array
    {
        $contactByClient = $this->firstContactPerClient($harvestContacts);
        $map = [];

        foreach ($harvestClients as $row) {
            $contact = $contactByClient[$row['id']] ?? null;
            $address = PostalAddress::parse($row['address'] ?? null);

            $map[$row['id']] = Client::create([
                'name' => $row['name'],
                'short_code' => $this->uniqueShortCode($row['name']),
                'address_line_1' => $address['address_line_1'],
                'address_line_2' => $address['address_line_2'],
                'postal_code' => $address['postal_code'],
                'city' => $address['city'],
                'country' => 'CH',
                'contact_name' => $contact
                    ? (trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')) ?: null)
                    : null,
                'email' => $contact['email'] ?? null,
                'archived_at' => ($row['is_active'] ?? true) ? null : now(),
            ]);
        }

        return $map;
    }

    /** @return array<int,array> first contact keyed by client id */
    private function firstContactPerClient(array $contacts): array
    {
        $byClient = [];
        foreach ($contacts as $contact) {
            $cid = $contact['client']['id'] ?? null;
            if ($cid !== null && ! isset($byClient[$cid])) {
                $byClient[$cid] = $contact;
            }
        }
        return $byClient;
    }

    private function uniqueShortCode(string $name): string
    {
        $base = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name) ?: 'CL', 0, 4));
        $base = $base === '' ? 'CL' : $base;

        if (! isset($this->usedCodes[$base])) {
            return $this->usedCodes[$base] = $base;
        }

        // Collision: replace the tail with digits, keeping length <= 4.
        $stem = substr($base, 0, 3);
        for ($n = 2; $n <= 99; $n++) {
            $candidate = substr($stem, 0, 4 - strlen((string) $n)) . $n;
            if (! isset($this->usedCodes[$candidate])) {
                return $this->usedCodes[$candidate] = $candidate;
            }
        }

        $fallback = substr($base, 0, 2) . random_int(10, 99);
        return $this->usedCodes[$fallback] = $fallback;
    }
}
