<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            // Test-only number format; production numbers come from InvoiceNumberer in Task 8.
            'number' => now()->year . '-T' . str_pad((string) $this->faker->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
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
            'issued_on' => now()->subDays(5)->toDateString(),
            'due_on' => now()->addDays(25)->toDateString(),
            'sent_at' => now()->subDays(5),
        ]);
    }

    public function paid(): self
    {
        return $this->state(fn () => [
            'status' => 'paid',
            'issued_on' => now()->subDays(20)->toDateString(),
            'due_on' => now()->addDays(10)->toDateString(),
            'sent_at' => now()->subDays(20),
            'paid_at' => now()->subDays(2),
        ]);
    }
}
