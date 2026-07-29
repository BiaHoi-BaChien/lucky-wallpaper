<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\InitialSetupController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('setup', [InitialSetupController::class, 'create'])
        ->name('setup.create');
    Route::post('setup', [InitialSetupController::class, 'store'])
        ->middleware('throttle:3,1')
        ->name('setup.store');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login');
});

Route::middleware('auth')->group(function () {
    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
