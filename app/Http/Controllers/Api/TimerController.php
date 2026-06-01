<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

    private function payload(Request $request): array
    {
        $user = $request->user();
        $data = TimerToday::payload($user);

        $running = TimeEntry::running()
            ->where('user_id', $user->id)
            ->with('project:id,name,code')
            ->first();

        $data['running'] = $running ? [
            'id' => $running->id,
            'description' => $running->description,
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
