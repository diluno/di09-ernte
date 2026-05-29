<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceLineFactory extends Factory
{
    protected $model = InvoiceLine::class;

    public function definition(): array
    {
        $hours = $this->faker->randomFloat(2, 1, 20);
        $rate = 14500; // 145 CHF/h

        return [
            'invoice_id' => Invoice::factory(),
            'description' => ucfirst($this->faker->bs()),
            'hours' => $hours,
            'rate_rappen' => $rate,
            'amount_rappen' => (int) round($hours * $rate),
            'vat_exempt' => false,
            'vat_code' => 'standard',
            'vat_label' => 'Normalsatz',
            'vat_rate' => 8.10,
            'sort_order' => 0,
        ];
    }
}
