<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => ucfirst($this->faker->bs()),
            'budget_hours' => $this->faker->numberBetween(4, 32),
            'done' => false,
            'sort_order' => 0,
        ];
    }

    public function done(): self
    {
        return $this->state(fn () => ['done' => true]);
    }
}
