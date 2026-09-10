<?php

namespace App\Http\Controllers;

use App\Models\Wallpaper;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('dashboard', [
            'stats' => [
                'wallpapers' => Wallpaper::query()->count(),
                'total_prize_vnd' => (int) Wallpaper::query()->sum('prize_vnd'),
                'generated_images' => Wallpaper::query()->whereNotNull('image_path')->count(),
            ],
        ]);
    }
}
