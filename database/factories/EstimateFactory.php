<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Estimate;
use Illuminate\Database\Eloquent\Factories\Factory;

class EstimateFactory extends Factory
{
    protected $model = Estimate::class;

    public function definition(): array
    {
        return [
            // Test-only number format; production numbers come from EstimateNumberer (Task 4).
            'number' => 'OF-' . now()->year . '-T' . str_pad((string) $this->faker->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'client_id' => Client::factory(),
            'status' => 'draft',
            'currency' => 'CHF',
            'vat_rate' => 8.10,
            'subtotal_rappen' => 0,
            'vat_rappen' => 0,
            'total_rappen' => 0,
        ];
    }

    public function sent(): self
    {
        return $this->state(fn () => [
            'status' => 'sent',
            'issued_on' => now()->subDays(3)->toDateString(),
            'valid_until' => now()->addDays(27)->toDateString(),
            'sent_at' => now()->subDays(3),
        ]);
    }

    public function accepted(): self
    {
        return $this->state(fn () => [
            'status' => 'accepted',
            'issued_on' => now()->subDays(10)->toDateString(),
            'valid_until' => now()->addDays(20)->toDateString(),
            'sent_at' => now()->subDays(10),
            'decided_at' => now()->subDays(2),
        ]);
    }

    public function declined(): self
    {
        return $this->state(fn () => [
            'status' => 'declined',
            'issued_on' => now()->subDays(10)->toDateString(),
            'valid_until' => now()->addDays(20)->toDateString(),
            'sent_at' => now()->subDays(10),
            'decided_at' => now()->subDays(1),
        ]);
    }
}
