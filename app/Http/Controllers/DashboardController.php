<?php

namespace App\Http\Controllers;

use App\Models\Wallpaper;
use App\Services\CalendarContextService;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(CalendarContextService $calendar): Response
    {
        $totals = [];
        foreach (CalendarContextService::NINE_STARS as $nineStar) {
            $totals[$nineStar] = array_fill_keys(Wallpaper::COMPOSITION_ZONES, [
                'records' => 0,
                'prize_total' => 0,
                'per_ticket_records' => 0,
                'per_ticket_total' => 0.0,
            ]);
        }

        $unclassifiedRecords = 0;
        $records = Wallpaper::query()
            ->whereNotNull('prize_vnd')
            ->get(['target_date', 'prize_vnd', 'purchase_count', 'composition_zone']);
        foreach ($records as $wallpaper) {
            if (! in_array($wallpaper->composition_zone, Wallpaper::COMPOSITION_ZONES, true)) {
                $unclassifiedRecords++;

                continue;
            }

            $nineStar = $calendar->forDate($wallpaper->target_date->format('Y-m-d'))['nine_star'] ?? null;
            if (! is_string($nineStar) || ! isset($totals[$nineStar])) {
                continue;
            }

            $zone = (string) $wallpaper->composition_zone;
            $totals[$nineStar][$zone]['records']++;
            $totals[$nineStar][$zone]['prize_total'] += (int) $wallpaper->prize_vnd;
            if ($wallpaper->purchase_count !== null && $wallpaper->purchase_count > 0) {
                $totals[$nineStar][$zone]['per_ticket_records']++;
                $totals[$nineStar][$zone]['per_ticket_total'] += $wallpaper->prize_vnd / $wallpaper->purchase_count;
            }
        }

        $today = CarbonImmutable::now((string) config('lucky.timezone'))->toDateString();
        $todayNineStar = $calendar->forDate($today)['nine_star'] ?? null;

        return Inertia::render('dashboard', [
            'stats' => [
                'wallpapers' => Wallpaper::query()->count(),
                'total_prize_vnd' => (int) Wallpaper::query()->sum('prize_vnd'),
                'generated_images' => Wallpaper::query()->whereNotNull('image_path')->count(),
            ],
            'ninePalace' => [
                'todayNineStar' => is_string($todayNineStar) ? $todayNineStar : null,
                'unclassifiedRecords' => $unclassifiedRecords,
                'zoneLabels' => Wallpaper::COMPOSITION_ZONE_LABELS,
                'stars' => collect(CalendarContextService::NINE_STARS)
                    ->map(fn (string $nineStar): array => [
                        'name' => $nineStar,
                        'zones' => $this->zoneStats($totals[$nineStar]),
                    ])->all(),
            ],
        ]);
    }

    private function zoneStats(array $totals): array
    {
        $zones = collect($totals)->map(function (array $total): array {
            $records = $total['records'];
            $perTicketRecords = $total['per_ticket_records'];

            return [
                'records' => $records,
                'averagePrizeVnd' => $records === 0 ? null : (int) round($total['prize_total'] / $records),
                'perTicketRecords' => $perTicketRecords,
                'averagePrizePerTicketVnd' => $perTicketRecords === 0
                    ? null
                    : (int) round($total['per_ticket_total'] / $perTicketRecords),
                'heatLevel' => 0,
            ];
        });
        $averages = $zones->pluck('averagePrizeVnd')
            ->filter(fn (?int $average): bool => $average !== null)
            ->unique()
            ->sort()
            ->values();

        return $zones->map(function (array $zone) use ($averages): array {
            if ($zone['averagePrizeVnd'] !== null) {
                $rank = $averages->search($zone['averagePrizeVnd'], true);
                $zone['heatLevel'] = (int) ceil(((int) $rank + 1) / $averages->count() * 4);
            }

            return $zone;
        })->all();
    }
}
