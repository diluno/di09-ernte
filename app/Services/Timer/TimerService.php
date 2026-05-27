<?php

namespace App\Services\Timer;

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TimerService
{
    public function start(User $user, Project $project, ?Task $task = null, string $description = ''): TimeEntry
    {
        return DB::transaction(function () use ($user, $project, $task, $description) {
            $this->stopRunningFor($user);

            return TimeEntry::create([
                'user_id' => $user->id,
                'project_id' => $project->id,
                'task_id' => $task?->id,
                'description' => $description,
                'started_at' => now(),
                'ended_at' => null,
                'billable' => (bool) $project->billable,
            ]);
        });
    }

    public function switch(User $user, Project $project, ?Task $task = null, string $description = ''): TimeEntry
    {
        return $this->start($user, $project, $task, $description);
    }

    public function stop(User $user): ?TimeEntry
    {
        return DB::transaction(function () use ($user) {
            return $this->stopRunningFor($user);
        });
    }

    public function discard(User $user): void
    {
        DB::transaction(function () use ($user) {
            TimeEntry::running()->where('user_id', $user->id)->delete();
        });
    }

    private function stopRunningFor(User $user): ?TimeEntry
    {
        $running = TimeEntry::running()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if (! $running) {
            return null;
        }

        $running->ended_at = now();
        $running->save();

        return $running;
    }
}
