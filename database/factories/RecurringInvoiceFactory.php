<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\RecurringInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class RecurringInvoiceFactory extends Factory
{
    protected $model = RecurringInvoice::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'project_id' => null,
            'title' => 'Hosting — {period}',
            'notes' => null,
            'currency' => 'CHF',
            'vat_rate' => 8.10,
            'cadence' => 'monthly',
            'anchor_day' => 1,
            'next_run_on' => now()->startOfMonth()->toDateString(),
            'last_generated_on' => null,
            'auto_send' => false,
            'paused_at' => null,
        ];
    }

    public function paused(): self { return $this->state(fn () => ['paused_at' => now()]); }

    public function autoSend(): self { return $this->state(fn () => ['auto_send' => true]); }

    public function cadence(string $cadence, int $anchorDay): self
    {
        return $this->state(fn () => ['cadence' => $cadence, 'anchor_day' => $anchorDay]);
    }
}
