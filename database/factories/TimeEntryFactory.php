<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TimeEntryFactory extends Factory
{
    protected $model = TimeEntry::class;

    public function definition(): array
    {
        $start = now()->subHours($this->faker->numberBetween(1, 48));
        $end = (clone $start)->addMinutes($this->faker->numberBetween(15, 180));

        return [
            'user_id' => User::factory(),
            'project_id' => Project::factory(),
            'task_id' => null,
            'description' => ucfirst($this->faker->bs()),
            'started_at' => $start,
            'ended_at' => $end,
            'billable' => true,
            'invoice_id' => null,
        ];
    }

    public function running(): self
    {
        return $this->state(fn () => ['ended_at' => null]);
    }
}
