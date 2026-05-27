<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    public function store(StoreTaskRequest $request): RedirectResponse
    {
        $next = (int) Task::where('project_id', $request->integer('project_id'))->max('sort_order');
        Task::create([
            ...$request->validated(),
            'sort_order' => $next + 1,
            'done' => false,
        ]);

        return back();
    }

    public function update(UpdateTaskRequest $request, Task $task): RedirectResponse
    {
        $task->update($request->validated());

        return back();
    }

    public function destroy(Task $task): RedirectResponse
    {
        $task->delete();

        return back();
    }

    public function reorder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'order' => 'required|array|min:1',
            'order.*' => 'required|integer|exists:tasks,id',
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['order'] as $i => $taskId) {
                Task::where('id', $taskId)->update(['sort_order' => $i]);
            }
        });

        return back();
    }
}
