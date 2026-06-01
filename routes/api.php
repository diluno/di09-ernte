<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TimerController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/token', [AuthController::class, 'store']);

Route::middleware('auth:sanctum')->group(function () {
    Route::delete('/auth/token', [AuthController::class, 'destroy']);
    Route::get('/me', [\App\Http\Controllers\Api\MeController::class, 'show']);
    Route::get('/timer', [TimerController::class, 'show']);
});
