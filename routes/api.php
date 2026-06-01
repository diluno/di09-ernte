<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EstimateController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\TimerController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/token', [AuthController::class, 'store'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::delete('/auth/token', [AuthController::class, 'destroy']);
    Route::get('/me', [MeController::class, 'show']);
    Route::get('/dashboard', [DashboardController::class, 'show']);
    Route::get('/timer', [TimerController::class, 'show']);
    Route::post('/timer/start', [TimerController::class, 'start']);
    Route::post('/timer/switch', [TimerController::class, 'switch']);
    Route::post('/timer/stop', [TimerController::class, 'stop']);
    Route::post('/timer/discard', [TimerController::class, 'discard']);
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::get('/projects/{project:code}', [ProjectController::class, 'show']);
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/{invoice:number}', [InvoiceController::class, 'show']);
    Route::get('/estimates', [EstimateController::class, 'index']);
    Route::get('/estimates/{estimate:number}', [EstimateController::class, 'show']);
});
