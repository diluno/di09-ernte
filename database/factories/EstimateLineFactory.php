<?php

namespace Database\Factories;

use App\Models\Estimate;
use App\Models\EstimateLine;
use Illuminate\Database\Eloquent\Factories\Factory;

class EstimateLineFactory extends Factory
{
    protected $model = EstimateLine::class;

    public function definition(): array
    {
        $hours = $this->faker->randomFloat(2, 1, 20);
        $rate = 14500; // 145 CHF/h
        return [
            'estimate_id' => Estimate::factory(),
            'description' => ucfirst($this->faker->bs()),
            'hours' => $hours,
            'rate_rappen' => $rate,
            'amount_rappen' => (int) round($hours * $rate),
            'vat_exempt' => false,
            'sort_order' => 0,
        ];
    }
}
