<?php

use App\Http\Controllers\ClientController;
use App\Http\Controllers\EntryController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TimerController;
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

    Route::get ('/timer',          [TimerController::class, 'show'])->name('timer.show');
    Route::post('/timer/start',    [TimerController::class, 'start'])->name('timer.start');
    Route::post('/timer/stop',     [TimerController::class, 'stop'])->name('timer.stop');
    Route::post('/timer/switch',   [TimerController::class, 'switch'])->name('timer.switch');
    Route::post('/timer/discard',  [TimerController::class, 'discard'])->name('timer.discard');

    Route::post  ('/entries',          [EntryController::class, 'store'])->name('entries.store');
    Route::patch ('/entries/{entry}',  [EntryController::class, 'update'])->name('entries.update');
    Route::delete('/entries/{entry}',  [EntryController::class, 'destroy'])->name('entries.destroy');

    Route::resource('clients', ClientController::class)->except(['show']);

    Route::get ('/invoices',     [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get ('/invoices/new', [InvoiceController::class, 'create'])->name('invoices.create');
    Route::post('/invoices',     [InvoiceController::class, 'store'])->name('invoices.store');

    Route::get('/reports',  fn () => Inertia::render('Reports/Placeholder'))->name('reports.show');

    Route::patch('/settings/tweaks', [\App\Http\Controllers\SettingsController::class, 'updateTweaks'])
        ->name('settings.tweaks');

    Route::get('/profile',    [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile',  [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
