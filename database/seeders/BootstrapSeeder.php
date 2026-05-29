<?php

namespace Database\Seeders;

use App\Models\BusinessProfile;
use App\Models\User;
use App\Models\VatRate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class BootstrapSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ERNTE_USER_EMAIL', 'owner@ernte.local');
        $name = env('ERNTE_USER_NAME', 'Owner');
        $password = env('ERNTE_USER_PASSWORD', 'changeme');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'settings' => [
                    'theme' => 'paper',
                    'density' => 'comfortable',
                    'accent' => '#2d4a3a',
                ],
            ]
        );

        BusinessProfile::firstOrCreate(
            ['id' => 1],
            [
                'name' => env('BUSINESS_NAME', 'Your Name'),
                'address_line_1' => env('BUSINESS_ADDRESS_LINE_1', ''),
                'postal_code' => env('BUSINESS_POSTAL_CODE', ''),
                'city' => env('BUSINESS_CITY', ''),
                'country' => env('BUSINESS_COUNTRY', 'CH'),
                'uid' => env('BUSINESS_UID', ''),
                'vat_id' => env('BUSINESS_VAT_ID', ''),
                'iban' => env('BUSINESS_IBAN', ''),
                'qr_iban' => env('BUSINESS_QR_IBAN', ''),
                'email' => env('BUSINESS_EMAIL', ''),
                'default_currency' => env('BUSINESS_DEFAULT_CURRENCY', 'CHF'),
                'default_vat_rate' => env('BUSINESS_DEFAULT_VAT_RATE', '8.10'),
                'reminder_days_after_due' => (int) env('BUSINESS_REMINDER_DAYS_AFTER_DUE', 7),
            ]
        );

        foreach ($this->vatRates() as $rate) {
            VatRate::updateOrCreate(['valid_from' => $rate['valid_from']], $rate);
        }
    }

    private function vatRates(): array
    {
        return [
            ['rate' => 7.70, 'valid_from' => '2018-01-01', 'valid_until' => '2023-12-31'],
            ['rate' => 8.10, 'valid_from' => '2024-01-01', 'valid_until' => null],
        ];
    }
}
