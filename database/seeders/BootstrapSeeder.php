<?php

namespace Database\Seeders;

use App\Models\User;
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
    }
}
