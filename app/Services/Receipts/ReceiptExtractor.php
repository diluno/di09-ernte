<?php

namespace App\Services\Receipts;

use Anthropic\Client;
use RuntimeException;

/** Reads vendor, date and amounts off a receipt PDF with Claude. */
class ReceiptExtractor
{
    /**
     * @return array{vendor: ?string, vendor_domain: ?string, document_date: ?string, total: ?string, currency: ?string,
     *   amounts: list<array{amount: string, currency: ?string}>, invoice_number: ?string,
     *   payment_method: string, confidence: string}
     */
    public function read(string $pdfBytes): array
    {
        $apiKey = config('services.anthropic.api_key');
        if (! $apiKey) {
            throw new RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        $message = (new Client(apiKey: $apiKey))->messages->create(
            model: config('services.anthropic.receipt_model') ?: config('services.anthropic.model'),
            maxTokens: 2000,
            system: [['type' => 'text', 'text' => $this->systemPrompt()]],
            messages: [[
                'role' => 'user',
                'content' => [
                    ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode($pdfBytes)]],
                    ['type' => 'text', 'text' => 'Extract the fields from this receipt.'],
                ],
            ]],
            outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $this->schema()]],
        );

        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('Claude declined to read this document.');
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text' && is_array($json = json_decode($block->text, true))) {
                return self::normalise($json);
            }
        }

        throw new RuntimeException('Claude returned an unexpected response.');
    }

    /** Clean model output into predictable types; anything malformed becomes null. */
    public static function normalise(array $json): array
    {
        $text = fn ($v) => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, 255) : null;
        $amount = fn ($v) => (is_string($v) || is_numeric($v)) && preg_match('/^-?\d+(\.\d{1,2})?$/', trim((string) $v))
            ? number_format((float) trim((string) $v), 2, '.', '') : null;
        $currency = fn ($v) => is_string($v) && preg_match('/^[A-Za-z]{3}$/', trim($v)) ? strtoupper(trim($v)) : null;

        $date = $text($json['document_date'] ?? null);
        if ($date !== null) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            $date = $parsed && $parsed->format('Y-m-d') === $date ? $date : null;
        }

        $amounts = [];
        foreach (is_array($json['amounts'] ?? null) ? $json['amounts'] : [] as $row) {
            if (is_array($row) && ($value = $amount($row['amount'] ?? null)) !== null) {
                $amounts[] = ['amount' => $value, 'currency' => $currency($row['currency'] ?? null)];
            }
        }

        return [
            'vendor' => $text($json['vendor'] ?? null),
            'vendor_domain' => VendorLogos::normalise(is_string($json['vendor_domain'] ?? null) ? $json['vendor_domain'] : null),
            'document_date' => $date,
            'total' => $amount($json['total'] ?? null),
            'currency' => $currency($json['currency'] ?? null),
            'amounts' => $amounts,
            'invoice_number' => $text($json['invoice_number'] ?? null),
            'payment_method' => in_array($json['payment_method'] ?? null, ['bank', 'card'], true) ? $json['payment_method'] : 'unknown',
            'confidence' => in_array($json['confidence'] ?? null, ['high', 'medium', 'low'], true) ? $json['confidence'] : 'low',
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
        You read receipts and supplier invoices for the bookkeeping of a small Swiss company. Extract exactly what the document states; never infer or calculate a value that is not printed.

        - vendor: the company that issued the document, as a short name without legal boilerplate (e.g. "Netlify", "Swisscom", "Coop"). Null if not identifiable.
        - vendor_domain: the vendor's own website domain as printed on the document (from a web address or the part after the @ of its email address), lower case and without "www." (e.g. "hetzner.com"). Not a payment provider's, a marketplace's or a free mail provider's domain. Null if none is printed; never guess one.
        - document_date: the invoice or receipt date as YYYY-MM-DD. Not the due date, not the service period. Swiss documents write dates day-first (03.07.2026 is 3 July). Null if absent.
        - total: the final amount to pay or paid, including tax, as a plain decimal with a dot and two decimals, no thousands separators (1'297.20 becomes "1297.20"). Null if no total is printed.
          On a credit card statement the total is the amount due for this statement (Viseca: "Total Rechnungsbetrag zu unseren Gunsten"), not the previous statement's total, not a payment received and not a single transaction.
        - currency: ISO code of the total (CHF, EUR, USD, …). Null if not stated.
        - amounts: every distinct monetary amount printed anywhere in the document, including subtotals, tax, instalments, amounts on payment slips and amounts in other currencies, each with its currency if stated. Include the total. Same decimal format.
        - invoice_number: the document's own invoice or receipt number. Null if absent.
        - payment_method: "card" if the document shows it was paid by credit or debit card, "bank" if it asks for or confirms a bank transfer, direct debit or payment slip, otherwise "unknown".
        - confidence: how sure you are of what you read, whatever kind of document it is. Salary statements, tax assessments, contracts and insurance policies are ordinary bookkeeping documents here, not a reason for doubt. "high" if vendor, date and total are all clearly printed; "medium" if one of them needed judgement; "low" only if the document is hard to read or its total is unclear.
        TXT;
    }

    private function schema(): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'properties' => [
                'vendor' => $nullableString,
                'vendor_domain' => $nullableString,
                'document_date' => $nullableString,
                'total' => $nullableString,
                'currency' => $nullableString,
                'amounts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['amount' => ['type' => 'string'], 'currency' => $nullableString],
                        'required' => ['amount', 'currency'],
                        'additionalProperties' => false,
                    ],
                ],
                'invoice_number' => $nullableString,
                'payment_method' => ['type' => 'string', 'enum' => ['bank', 'card', 'unknown']],
                'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
            ],
            'required' => ['vendor', 'vendor_domain', 'document_date', 'total', 'currency', 'amounts', 'invoice_number', 'payment_method', 'confidence'],
            'additionalProperties' => false,
        ];
    }
}
