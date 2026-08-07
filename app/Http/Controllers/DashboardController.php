<?php

namespace App\Http\Controllers;

use App\Models\Wallpaper;
use App\Services\CalendarContextService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const MIN_MOON_CHART_PRIZE_VND = 10_000;

    public function __invoke(CalendarContextService $calendar): Response
    {
        $wallpapers = Wallpaper::query()
            ->where('prize_vnd', '>', self::MIN_MOON_CHART_PRIZE_VND)
            ->orderBy('target_date')
            ->get(['id', 'target_date', 'title', 'art_style', 'prize_vnd', 'image_path', 'notion_page_id']);
        $today = $calendar->moonForDate(CarbonImmutable::now(config('lucky.timezone'))->toDateString());
        $todayMoon = is_numeric($today['moon_age'] ?? null) ? [
            'target_date' => $today['target_date'],
            'moon_age' => (float) $today['moon_age'],
            'moon_phase' => $today['moon_phase'],
        ] : null;

        return Inertia::render('dashboard', [
            'stats' => [
                'wallpapers' => Wallpaper::query()->count(),
                'total_prize_vnd' => (int) Wallpaper::query()->sum('prize_vnd'),
                'generated_images' => Wallpaper::query()->whereNotNull('image_path')->count(),
            ],
            'moonChart' => $this->moonChart($wallpapers, $calendar),
            'moonChartMinimumPrizeVnd' => self::MIN_MOON_CHART_PRIZE_VND,
            'todayMoon' => $todayMoon,
        ]);
    }

    private function moonChart(Collection $wallpapers, CalendarContextService $calendar): array
    {
        $counts = $wallpapers->pluck('prize_vnd')->countBy()->sortKeys();
        $total = $wallpapers->count();
        $cumulative = 0;
        $percentiles = [];

        foreach ($counts as $prize => $count) {
            $cumulative += $count;
            $percentiles[(string) $prize] = round($cumulative / $total, 4);
        }

        return $wallpapers->map(function (Wallpaper $wallpaper) use ($calendar, $percentiles): ?array {
            $context = $calendar->moonForDate($wallpaper->target_date->format('Y-m-d'));
            if (! is_numeric($context['moon_age'] ?? null)) {
                return null;
            }

            return [
                'id' => $wallpaper->id,
                'target_date' => $wallpaper->target_date->format('Y-m-d'),
                'title' => $wallpaper->title,
                'art_style' => $wallpaper->art_style,
                'prize_vnd' => $wallpaper->prize_vnd,
                'prize_percentile' => $percentiles[(string) $wallpaper->prize_vnd],
                'moon_age' => (float) $context['moon_age'],
                'moon_phase' => $context['moon_phase'],
                'has_image' => $wallpaper->image_path !== null || $wallpaper->notion_page_id !== null,
            ];
        })->filter()->values()->all();
    }
}
