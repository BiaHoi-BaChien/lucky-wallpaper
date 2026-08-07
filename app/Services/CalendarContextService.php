<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use com\nlf\calendar\Solar;
use Solaris\MoonPhase;
use Throwable;

class CalendarContextService
{
    public function moonForDate(string $targetDate): array
    {
        $date = CarbonImmutable::parse($targetDate, config('lucky.timezone'))->setTime(12, 0);

        return $this->moonContext($date);
    }

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

        $moonContext = $this->moonContext($date);

        return [
            ...$context,
            ...$moonContext,
            'warnings' => [...$context['warnings'], ...$moonContext['warnings']],
        ];
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

    private function moonPhaseName(float $phase): string
    {
        return match (true) {
            $phase < 0.0625 || $phase >= 0.9375 => '新月',
            $phase < 0.1875 => '満ちていく三日月',
            $phase < 0.3125 => '上弦',
            $phase < 0.4375 => '満ちていく凸月',
            $phase < 0.5625 => '満月',
            $phase < 0.6875 => '欠けていく凸月',
            $phase < 0.8125 => '下弦',
            default => '欠けていく三日月',
        };
    }

    private function moonContext(CarbonImmutable $date): array
    {
        $context = [
            'target_date' => $date->toDateString(),
            'timezone' => config('lucky.timezone'),
            'warnings' => [],
        ];

        try {
            $moon = new MoonPhase($date->toDateTime());
            $phase = $moon->getPhase();
            $context += [
                'moon_age' => round($moon->getAge(), 2),
                'moon_illumination' => round($moon->getIllumination(), 4),
                'moon_phase' => $this->moonPhaseName($phase),
            ];
        } catch (Throwable) {
            $context['warnings'][] = '月齢情報を検証できなかったため省略しました。';
        }

        return $context;
    }
}
