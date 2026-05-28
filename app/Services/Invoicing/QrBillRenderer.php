<?php

namespace App\Services\Invoicing;

use App\Models\BusinessProfile;
use App\Models\Invoice;
use Sprain\SwissQrBill as QrBill;
use Sprain\SwissQrBill\DataGroup\AddressInterface;
use Sprain\SwissQrBill\DataGroup\Element\StructuredAddress;
use Sprain\SwissQrBill\PaymentPart\Output\DisplayOptions;

class QrBillRenderer
{
    /** Build the QR payment part as an HTML/SVG string for embedding in the invoice document. */
    public function html(Invoice $invoice): string
    {
        $profile = BusinessProfile::current();
        $qrBill = QrBill\QrBill::create();

        // Creditor (us).
        $qrBill->setCreditor($this->address(
            $profile->name ?: 'Creditor',
            trim(($profile->address_line_1 ?? '') . ' ' . ($profile->address_line_2 ?? '')),
            $profile->postal_code,
            $profile->city,
            $profile->country,
        ));

        // QR-IBAN ⇒ QRR reference; plain IBAN ⇒ no reference (NON).
        // The reference type must match the IBAN type, so the fallback IBAN must match
        // the mode: QR mode uses the library's test QR-IBAN, NON mode a plain IBAN.
        $useQrIban = ! empty($profile->qr_iban);
        $iban = $useQrIban
            ? ($profile->qr_iban ?: 'CH4431999123000889012')
            : ($profile->iban ?: 'CH9300762011623852957');
        $qrBill->setCreditorInformation(
            QrBill\DataGroup\Element\CreditorInformation::create($iban)
        );

        // Debtor (client).
        $client = $invoice->client;
        $qrBill->setUltimateDebtor($this->address(
            $client->name,
            trim(($client->address_line_1 ?? '') . ' ' . ($client->address_line_2 ?? '')),
            $client->postal_code,
            $client->city,
            $client->country,
        ));

        // Amount.
        $qrBill->setPaymentAmountInformation(
            QrBill\DataGroup\Element\PaymentAmountInformation::create(
                $invoice->currency ?: 'CHF',
                round($invoice->total_rappen / 100, 2),
            )
        );

        // Reference.
        if ($useQrIban && $invoice->qr_reference) {
            $qrBill->setPaymentReference(
                QrBill\DataGroup\Element\PaymentReference::create(
                    QrBill\DataGroup\Element\PaymentReference::TYPE_QR,
                    $invoice->qr_reference,
                )
            );
        } else {
            $qrBill->setPaymentReference(
                QrBill\DataGroup\Element\PaymentReference::create(
                    QrBill\DataGroup\Element\PaymentReference::TYPE_NON,
                    null,
                )
            );
        }

        // Additional info: the invoice number, so the payer can reconcile.
        $qrBill->setAdditionalInformation(
            QrBill\DataGroup\Element\AdditionalInformation::create("Rechnung {$invoice->number}")
        );

        $violations = $qrBill->getViolations();
        if (count($violations) > 0) {
            $messages = [];
            foreach ($violations as $v) {
                $messages[] = $v->getMessage();
            }
            throw new \RuntimeException('Invalid QR bill: ' . implode('; ', $messages));
        }

        $output = new QrBill\PaymentPart\Output\HtmlOutput\HtmlOutput($qrBill, 'de');

        $displayOptions = (new DisplayOptions())->setPrintable(false);

        return (string) $output->setDisplayOptions($displayOptions)->getPaymentPart();
    }

    /**
     * Build a structured address. A free-form street line is passed verbatim as the
     * street (the library allows the building number to be embedded in the street);
     * an empty street falls back to the street-less constructor.
     */
    private function address(string $name, string $street, ?string $postalCode, ?string $city, ?string $country): AddressInterface
    {
        $postalCode = ($postalCode ?? '') !== '' ? $postalCode : '-';
        $city = ($city ?? '') !== '' ? $city : '-';
        $country = ($country ?? '') !== '' ? $country : 'CH';

        if ($street !== '') {
            return StructuredAddress::createWithStreet($name, $street, null, $postalCode, $city, $country);
        }

        return StructuredAddress::createWithoutStreet($name, $postalCode, $city, $country);
    }
}
