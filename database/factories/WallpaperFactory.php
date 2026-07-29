<?php

namespace Database\Factories;

use App\Models\Wallpaper;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Wallpaper> */
class WallpaperFactory extends Factory
{
    protected $model = Wallpaper::class;

    public function definition(): array
    {
        return [
            'target_date' => fake()->unique()->date(),
            'title' => fake()->sentence(4),
            'art_style' => '実写写真',
            'conclusion' => fake()->sentence(),
            'overview' => fake()->paragraph(),
            'composition' => fake()->paragraph(),
            'color_wu_xing' => fake()->paragraph(),
            'symbolism' => fake()->paragraph(),
            'state' => 'generated',
        ];
    }
}
