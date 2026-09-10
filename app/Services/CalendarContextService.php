<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use com\nlf\calendar\Solar;
use Throwable;

class CalendarContextService
{
    public const NINE_STARS = [
        '一白水天枢',
        '二黒土天璇',
        '三碧木天玑',
        '四绿木天权',
        '五黄土玉衡',
        '六白金开阳',
        '七赤金摇光',
        '八白土洞明',
        '九紫火隐元',
    ];

    public function forDate(string $targetDate): array
    {
        $date = CarbonImmutable::parse($targetDate, config('lucky.timezone'))->setTime(12, 0);
        $context = [
            'target_date' => $date->toDateString(),
            'timezone' => config('lucky.timezone'),
            'season' => $this->season($date->month),
            'warnings' => [],
        ];

        try {
            $lunar = Solar::fromYmd($date->year, $date->month, $date->day)->getLunar();
            $nineStar = $lunar->getDayNineStar();
            $context += [
                'rokuyo' => $lunar->getLiuYao(),
                'day_ganzhi' => $lunar->getDayInGanZhiExact(),
                'nine_star' => $nineStar->toString(),
            ];
        } catch (Throwable) {
            $context['warnings'][] = '暦情報（六曜・日干支・九星）を検証できなかったため省略しました。';
        }

        return $context;
    }

    private function season(int $month): string
    {
        return match ($month) {
            3, 4, 5 => '春',
            6, 7, 8 => '夏',
            9, 10, 11 => '秋',
            default => '冬',
        };
    }
}
