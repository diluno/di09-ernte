<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'role' => null,
            'is_default' => false,
            'sort_order' => 0,
        ];
    }
}
