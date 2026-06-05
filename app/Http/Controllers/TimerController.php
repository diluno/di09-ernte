<?php

namespace App\Http\Controllers;

use App\Http\Requests\StartTimerRequest;
use App\Models\Project;
use App\Models\Task;
use App\Services\Timer\TimerService;
use App\Support\TimerToday;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class TimerController extends Controller
{
    public function __construct(private TimerService $timer) {}

    public function show(Request $request): Response
    {
        // The day being viewed; defaults to today. A malformed ?date= falls back to
        // today rather than erroring, so a stale or hand-edited URL stays usable.
        $date = Carbon::today();
        if ($request->filled('date')) {
            $date = rescue(
                fn () => Carbon::createFromFormat('Y-m-d', (string) $request->input('date'))->startOfDay(),
                Carbon::today(),
                report: false,
            );
        }

        return Inertia::render('Timer/Today', TimerToday::payload($request->user(), $date));
    }

    public function start(StartTimerRequest $request): RedirectResponse
    {
        $project = Project::findOrFail($request->integer('project_id'));
        $task = $request->filled('task_id') ? Task::find($request->integer('task_id')) : null;

        $this->timer->start($request->user(), $project, $task, (string) $request->input('description', ''));

        return back();
    }

    public function stop(Request $request): RedirectResponse
    {
        $this->timer->stop($request->user());

        return back();
    }

    public function switch(StartTimerRequest $request): RedirectResponse
    {
        $project = Project::findOrFail($request->integer('project_id'));
        $task = $request->filled('task_id') ? Task::find($request->integer('task_id')) : null;

        $this->timer->switch($request->user(), $project, $task, (string) $request->input('description', ''));

        return back();
    }

    public function discard(Request $request): RedirectResponse
    {
        $this->timer->discard($request->user());

        return back();
    }
}
