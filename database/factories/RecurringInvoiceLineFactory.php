<?php

namespace Database\Factories;

use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

class RecurringInvoiceLineFactory extends Factory
{
    protected $model = RecurringInvoiceLine::class;

    public function definition(): array
    {
        return [
            'recurring_invoice_id' => RecurringInvoice::factory(),
            'description' => 'Hosting',
            'hours' => 1,
            'rate_rappen' => 10000, // 100 CHF
            'sort_order' => 0,
        ];
    }
}
