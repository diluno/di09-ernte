<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::get('/', fn () => redirect('/projects'));

    Route::get   ('/projects',                          [ProjectController::class, 'index'])->name('projects.index');
    Route::post  ('/projects',                          [ProjectController::class, 'store'])->name('projects.store');
    Route::get   ('/projects/{project:code}',           [ProjectController::class, 'show'])->name('projects.show');
    Route::patch ('/projects/{project}',                [ProjectController::class, 'update'])->name('projects.update');
    Route::post  ('/projects/{project:code}/archive',   [ProjectController::class, 'archive'])->name('projects.archive');

    Route::post  ('/tasks',          [TaskController::class, 'store'])->name('tasks.store');
    Route::patch ('/tasks/reorder',  [TaskController::class, 'reorder'])->name('tasks.reorder');
    Route::patch ('/tasks/{task}',   [TaskController::class, 'update'])->name('tasks.update');
    Route::delete('/tasks/{task}',   [TaskController::class, 'destroy'])->name('tasks.destroy');

    Route::get('/timer',    fn () => Inertia::render('Timer/Today'))->name('timer.show');
    Route::get('/clients',  fn () => Inertia::render('Clients/Index'))->name('clients.index');
    Route::get('/invoices', fn () => Inertia::render('Invoices/Index'))->name('invoices.index');
    Route::get('/reports',  fn () => Inertia::render('Reports/Placeholder'))->name('reports.show');

    Route::patch('/settings/tweaks', [\App\Http\Controllers\SettingsController::class, 'updateTweaks'])
        ->name('settings.tweaks');

    Route::get('/profile',    [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile',  [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
