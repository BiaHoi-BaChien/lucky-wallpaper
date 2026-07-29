<?php

use App\Http\Controllers\Settings\PasskeyController;
use App\Http\Controllers\Settings\PasswordController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/password');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');
    Route::get('settings/passkeys', [PasskeyController::class, 'index'])->name('passkeys.index');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');
});
