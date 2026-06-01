<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StartTimerRequest;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Services\Timer\TimerService;
use App\Support\TimerToday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimerController extends Controller
{
    public function __construct(private TimerService $timer) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    public function start(StartTimerRequest $request): JsonResponse
    {
        $project = Project::findOrFail($request->integer('project_id'));
        $task = $request->filled('task_id') ? Task::find($request->integer('task_id')) : null;

        $this->timer->start($request->user(), $project, $task, (string) $request->input('description', ''));

        return response()->json($this->payload($request));
    }

    public function switch(StartTimerRequest $request): JsonResponse
    {
        $project = Project::findOrFail($request->integer('project_id'));
        $task = $request->filled('task_id') ? Task::find($request->integer('task_id')) : null;

        $this->timer->switch($request->user(), $project, $task, (string) $request->input('description', ''));

        return response()->json($this->payload($request));
    }

    public function stop(Request $request): JsonResponse
    {
        $this->timer->stop($request->user());

        return response()->json($this->payload($request));
    }

    public function discard(Request $request): JsonResponse
    {
        $this->timer->discard($request->user());

        return response()->json($this->payload($request));
    }

    private function payload(Request $request): array
    {
        $user = $request->user();
        $data = TimerToday::payload($user);

        $running = TimeEntry::running()
            ->where('user_id', $user->id)
            ->with(['project:id,name,code', 'task:id,name'])
            ->first();

        $data['running'] = $running ? [
            'id' => $running->id,
            'description' => $running->description,
            'task_name' => $running->task?->name,
            'started_at' => $running->started_at->toIso8601String(),
            'duration_seconds' => $running->duration_seconds,
            'billable' => (bool) $running->billable,
            'project' => [
                'id' => $running->project->id,
                'name' => $running->project->name,
                'code' => $running->project->code,
            ],
        ] : null;

        return $data;
    }
}
