<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotionSyncController;
use App\Http\Controllers\OperationController;
use App\Http\Controllers\ResultController;
use App\Http\Controllers\WallpaperAnalysisController;
use App\Http\Controllers\WallpaperController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (! User::query()->exists()) {
        return to_route('setup.create');
    }

    return auth()->guard()->check() ? to_route('dashboard') : to_route('login');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::post('notion-syncs', [NotionSyncController::class, 'store'])->name('notion-syncs.store');

    Route::get('wallpapers/create', [WallpaperController::class, 'create'])->name('wallpapers.create');
    Route::post('wallpaper-analyses', [WallpaperAnalysisController::class, 'store'])->name('wallpaper-analyses.store');
    Route::post('wallpapers/proposals', [WallpaperController::class, 'storeProposal'])->name('wallpapers.proposals.store');
    Route::get('wallpapers', [WallpaperController::class, 'index'])->name('wallpapers.index');
    Route::get('wallpapers/{wallpaper}', [WallpaperController::class, 'show'])->name('wallpapers.show');
    Route::post('wallpapers/{wallpaper}/repropose', [WallpaperController::class, 'repropose'])->name('wallpapers.repropose');
    Route::post('wallpapers/{wallpaper}/image', [WallpaperController::class, 'image'])->name('wallpapers.image');
    Route::post('wallpapers/{wallpaper}/restore-image', [WallpaperController::class, 'restoreImage'])->name('wallpapers.image.restore');
    Route::get('wallpapers/{wallpaper}/preview', [WallpaperController::class, 'preview'])->name('wallpapers.preview');
    Route::get('wallpapers/{wallpaper}/download', [WallpaperController::class, 'download'])->name('wallpapers.download');
    Route::delete('wallpapers/{wallpaper}/image', [WallpaperController::class, 'destroyImage'])->name('wallpapers.image.destroy');
    Route::delete('wallpapers/{wallpaper}', [WallpaperController::class, 'destroy'])->name('wallpapers.destroy');

    Route::get('results', [ResultController::class, 'index'])->name('results.index');
    Route::put('wallpapers/{wallpaper}/result', [ResultController::class, 'update'])->name('wallpapers.result.update');
    Route::get('operations/{id}', [OperationController::class, 'show'])->name('operations.show');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
