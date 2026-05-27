<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        $name = $this->faker->company();
        return [
            'name' => $name,
            'short_code' => strtoupper(substr(str_replace(' ', '', $name), 0, 2)),
            'contact_name' => $this->faker->name(),
            'email' => $this->faker->companyEmail(),
            'address_line_1' => $this->faker->streetAddress(),
            'postal_code' => $this->faker->postcode(),
            'city' => $this->faker->city(),
            'country' => 'CH',
            'default_rate_rappen' => $this->faker->numberBetween(10000, 20000),
        ];
    }

    public function archived(): self
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }
}
