<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectNote;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectNoteFactory extends Factory
{
    protected $model = ProjectNote::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'body' => $this->faker->sentence(),
        ];
    }
}
