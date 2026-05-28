<?php

use App\Http\Controllers\ClientController;
use App\Http\Controllers\EntryController;
use App\Http\Controllers\EstimateController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TimerController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/projects');

    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
    Route::get('/projects/{project:code}', [ProjectController::class, 'show'])->name('projects.show');
    Route::get('/projects/{project:code}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
    Route::patch('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::post('/projects/{project:code}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
    Route::post('/projects/{project:code}/pin', [ProjectController::class, 'pin'])->name('projects.pin');
    Route::post('/projects/{project:code}/unpin', [ProjectController::class, 'unpin'])->name('projects.unpin');

    Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::patch('/tasks/reorder', [TaskController::class, 'reorder'])->name('tasks.reorder');
    Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');

    Route::get('/timer', [TimerController::class, 'show'])->name('timer.show');
    Route::post('/timer/start', [TimerController::class, 'start'])->name('timer.start');
    Route::post('/timer/stop', [TimerController::class, 'stop'])->name('timer.stop');
    Route::post('/timer/switch', [TimerController::class, 'switch'])->name('timer.switch');
    Route::post('/timer/discard', [TimerController::class, 'discard'])->name('timer.discard');

    Route::post('/entries', [EntryController::class, 'store'])->name('entries.store');
    Route::patch('/entries/{entry}', [EntryController::class, 'update'])->name('entries.update');
    Route::delete('/entries/{entry}', [EntryController::class, 'destroy'])->name('entries.destroy');

    Route::resource('clients', ClientController::class);

    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/new', [InvoiceController::class, 'create'])->name('invoices.create');
    Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');

    Route::get('/invoices/{invoice:number}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('/invoices/{invoice:number}/preview', [InvoiceController::class, 'preview'])->name('invoices.preview');
    Route::get('/invoices/{invoice:number}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::patch('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
    Route::post('/invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('invoices.send');
    Route::post('/invoices/{invoice}/paid', [InvoiceController::class, 'markPaid'])->name('invoices.paid');
    Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');

    Route::get('/estimates', [EstimateController::class, 'index'])->name('estimates.index');
    Route::get('/estimates/new', [EstimateController::class, 'create'])->name('estimates.create');
    Route::post('/estimates', [EstimateController::class, 'store'])->name('estimates.store');

    Route::get('/estimates/{estimate:number}', [EstimateController::class, 'show'])->name('estimates.show');
    Route::get('/estimates/{estimate:number}/preview', [EstimateController::class, 'preview'])->name('estimates.preview');
    Route::get('/estimates/{estimate:number}/pdf', [EstimateController::class, 'pdf'])->name('estimates.pdf');
    Route::patch('/estimates/{estimate}', [EstimateController::class, 'update'])->name('estimates.update');
    Route::post('/estimates/{estimate}/send', [EstimateController::class, 'send'])->name('estimates.send');
    Route::post('/estimates/{estimate}/accept', [EstimateController::class, 'accept'])->name('estimates.accept');
    Route::post('/estimates/{estimate}/decline', [EstimateController::class, 'decline'])->name('estimates.decline');
    Route::post('/estimates/{estimate}/convert', [EstimateController::class, 'convert'])->name('estimates.convert');

    Route::get('/reports', [ReportController::class, 'show'])->name('reports.show');

    Route::get('/settings', [SettingsController::class, 'show'])->name('settings.show');
    Route::patch('/settings/profile', [SettingsController::class, 'updateProfile'])->name('settings.profile');
    Route::patch('/settings/tweaks', [SettingsController::class, 'updateTweaks'])->name('settings.tweaks');

    Route::get('/api/search', SearchController::class)->name('api.search');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
