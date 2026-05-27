<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', fn () => redirect('/projects'));

    Route::get('/projects', fn () => Inertia::render('Projects/Index'))->name('projects.index');
    Route::get('/timer',    fn () => Inertia::render('Timer/Today'))->name('timer.show');
    Route::get('/clients',  fn () => Inertia::render('Clients/Index'))->name('clients.index');
    Route::get('/invoices', fn () => Inertia::render('Invoices/Index'))->name('invoices.index');
    Route::get('/reports',  fn () => Inertia::render('Reports/Placeholder'))->name('reports.show');
});

require __DIR__.'/auth.php';
