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
        $owner = config('ernte.owner');

        // Only a missing user needs the password; later deploys run this seeder with the
        // variable long removed from the environment.
        if (! User::where('email', $owner['email'])->exists()) {
            if (blank($owner['password'])) {
                throw new \RuntimeException('ERNTE_USER_PASSWORD is empty: set it in the environment before the first seed. There is no default password.');
            }

            User::create([
                'email' => $owner['email'],
                'name' => $owner['name'],
                'password' => Hash::make($owner['password']),
                'email_verified_at' => now(),
                'settings' => [
                    'theme' => 'paper',
                    'density' => 'comfortable',
                    'accent' => '#2d4a3a',
                ],
            ]);
        }

        BusinessProfile::firstOrCreate(['id' => 1], config('ernte.business'));

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
