<?php

namespace App\Http\Controllers;

use App\Models\Wallpaper;
use App\Services\KigakuPolicy;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $rows = [];
        foreach (KigakuPolicy::GROUPS as $key => $label) {
            $rows[$key] = [
                'key' => $key, 'label' => $label, 'wallpapers' => 0, 'result_count' => 0,
                'prize_total' => 0, 'ticket_result_count' => 0, 'ticket_prize_total' => 0, 'ticket_total' => 0,
            ];
        }
        $wallpapers = Wallpaper::query()->with('chosenProposal')->get();
        foreach ($wallpapers as $wallpaper) {
            $key = KigakuPolicy::group($wallpaper->kigaku());
            $rows[$key]['wallpapers']++;
            if ($wallpaper->prize_vnd === null) {
                continue;
            }
            $rows[$key]['result_count']++;
            $rows[$key]['prize_total'] += $wallpaper->prize_vnd;
            if ($wallpaper->purchase_count !== null && $wallpaper->purchase_count > 0) {
                $rows[$key]['ticket_result_count']++;
                $rows[$key]['ticket_prize_total'] += $wallpaper->prize_vnd;
                $rows[$key]['ticket_total'] += $wallpaper->purchase_count;
            }
        }

        return Inertia::render('dashboard', [
            'latestWallpaperDate' => Wallpaper::query()->orderByDesc('target_date')->first(['target_date'])?->target_date->toDateString(),
            'stats' => [
                'wallpapers' => $wallpapers->count(),
                'result_count' => $wallpapers->whereNotNull('prize_vnd')->count(),
                'total_prize_vnd' => $wallpapers->sum('prize_vnd'),
            ],
            'directionStats' => array_values(array_map(fn (array $row): array => [
                ...$row,
                'average_prize' => $row['result_count'] === 0 ? null : (int) round($row['prize_total'] / $row['result_count']),
                'prize_per_ticket' => $row['ticket_total'] === 0 ? null : (int) round($row['ticket_prize_total'] / $row['ticket_total']),
            ], $rows)),
        ]);
    }
}
