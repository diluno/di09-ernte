<?php

namespace App\Services\Harvest;

use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Support\Carbon;

class InvoiceImporter
{
    private const STATUS = [
        'draft' => 'draft',
        'open' => 'sent',
        'paid' => 'paid',
        'closed' => 'void',
    ];

    /**
     * @param  array<int,array>  $harvestInvoices
     * @param  array<int,Client>  $clientMap
     * @return array{imported:int, warnings:string[]}
     */
    public function import(array $harvestInvoices, array $clientMap): array
    {
        $imported = 0;
        $warnings = [];

        foreach ($harvestInvoices as $row) {
            $client = $clientMap[$row['client']['id'] ?? null] ?? null;
            if (! $client) {
                $warnings[] = "Skipped invoice {$row['number']}: client not imported.";

                continue;
            }

            $amount = (float) ($row['amount'] ?? 0);
            $hasNegative = $amount < 0 || collect($row['line_items'] ?? [])->contains(
                fn ($l) => (float) ($l['amount'] ?? 0) < 0 || (float) ($l['unit_price'] ?? 0) < 0
            );
            if ($hasNegative) {
                $warnings[] = "Skipped invoice {$row['number']}: negative amounts (credit note/discount) are not supported by ernte.";

                continue;
            }

            $currency = $row['currency'] ?? 'CHF';
            if ($currency !== 'CHF') {
                $warnings[] = "Invoice {$row['number']} is {$currency}; imported as-is (amounts treated as CHF).";
            }

            $total = (int) round(((float) ($row['amount'] ?? 0)) * 100);
            $vat = (int) round((((float) ($row['tax_amount'] ?? 0)) + ((float) ($row['tax2_amount'] ?? 0))) * 100);

            if ($vat > $total) {
                $warnings[] = "Invoice {$row['number']}: VAT exceeds total; subtotal clamped to 0.";
            }
            if (! empty($row['tax2'])) {
                $warnings[] = "Invoice {$row['number']} has a second tax (tax2); stored as a single blended VAT rate.";
            }

            $invoice = Invoice::create([
                'number' => $row['number'],
                'client_id' => $client->id,
                'project_id' => null,
                'status' => self::STATUS[$row['state'] ?? 'draft'] ?? 'draft',
                'issued_on' => $row['issue_date'] ?? null,
                'due_on' => $row['due_date'] ?? null,
                'sent_at' => isset($row['sent_at']) ? Carbon::parse($row['sent_at']) : null,
                'paid_at' => isset($row['paid_at']) ? Carbon::parse($row['paid_at']) : null,
                'currency' => $currency,
                'vat_rate' => (float) ($row['tax'] ?? 0),
                'subtotal_rappen' => max(0, $total - $vat),
                'vat_rappen' => $vat,
                'total_rappen' => $total,
                'title' => $row['subject'] ?? null,
                'notes' => $row['notes'] ?? null,
            ]);

            $sort = 0;
            foreach ($row['line_items'] ?? [] as $line) {
                $taxed = (bool) ($line['taxed'] ?? true);
                $invoice->lines()->create([
                    'description' => $line['description'] ?? '',
                    'hours' => (float) ($line['quantity'] ?? 0),
                    'rate_rappen' => (int) round(((float) ($line['unit_price'] ?? 0)) * 100),
                    'amount_rappen' => (int) round(((float) ($line['amount'] ?? 0)) * 100),
                    'vat_exempt' => ! $taxed,
                    'vat_code' => $taxed ? 'standard' : 'exempt',
                    'vat_label' => $taxed ? 'Normalsatz' : 'MwSt-befreit',
                    'vat_rate' => $taxed ? (float) ($row['tax'] ?? 0) : 0,
                    'sort_order' => $sort++,
                ]);
            }

            $invoice->events()->create([
                'kind' => 'created',
                'occurred_at' => now(),
                'payload' => ['source' => 'harvest', 'harvest_id' => $row['id']],
            ]);

            $imported++;
        }

        return ['imported' => $imported, 'warnings' => $warnings];
    }
}
