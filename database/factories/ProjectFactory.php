<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $name = $this->faker->bs();
        return [
            'client_id' => Client::factory(),
            'name' => ucfirst($name),
            'code' => strtoupper($this->faker->unique()->lexify('????-???')),
            'description' => $this->faker->sentence(),
            'status' => 'active',
            'billable' => true,
            'budget_hours' => $this->faker->numberBetween(40, 200),
            'budget_amount_rappen' => $this->faker->numberBetween(500000, 3000000),
            'rate_rappen' => $this->faker->randomElement([10000, 12000, 14000, 15000]),
            'started_on' => now()->subDays($this->faker->numberBetween(10, 120))->toDateString(),
            'deadline_on' => now()->addDays($this->faker->numberBetween(10, 90))->toDateString(),
        ];
    }

    public function retainer(): self
    {
        return $this->state(fn () => [
            'retainer' => true,
            'retainer_hours' => 16,
            'retainer_resets_monthly' => true,
        ]);
    }

    public function archived(): self
    {
        return $this->state(fn () => ['status' => 'archived']);
    }
}
