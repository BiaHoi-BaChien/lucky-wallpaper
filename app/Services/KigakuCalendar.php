<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use com\nlf\calendar\Solar;
use com\nlf\calendar\util\LunarUtil;
use RuntimeException;

class KigakuCalendar
{
    public const BASIS = 'lunar-phpの定気を現地日に変換。立春・十二節入りの現地日0時で年・月を切替。日界は現地0時。日星は至近甲子、180日／240日置閏方式（閏後半30日を逆転）。盤内は常に後天定位の順で順飛。';

    private array $terms = [];

    public function board(string $date, string $type): array
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, config('lucky.timezone'));
        if ($day === null || $day->format('Y-m-d') !== $date) {
            throw new RuntimeException('有効な対象日が必要です。');
        }
        $civilYear = intval($day->format('Y'));
        if ($civilYear < 1901 || $civilYear > 2099) {
            throw new RuntimeException('暦の対応範囲は1901～2099年の有効な日付です。');
        }
        if ($type === 'day') {
            return $this->dayBoard($day);
        }
        $year = $civilYear - ($day->lt($this->term($civilYear, '立春')->startOfDay()) ? 1 : 0);
        $yearBranch = $this->mod($year - 4, 12);
        if ($type === 'year') {
            return ['center_star' => $this->star(11 - $year), 'branch' => $yearBranch];
        }
        if ($type !== 'month') {
            throw new RuntimeException('未知の盤です。');
        }
        $names = ['立春', '惊蛰', '清明', '立夏', '芒种', '小暑', '立秋', '白露', '寒露', '立冬', '大雪', '小寒'];
        for ($month = 11; $month >= 0; $month--) {
            $boundary = $this->term($year + ($month === 11 ? 1 : 0), $names[$month])->startOfDay();
            if ($day->gte($boundary)) {
                return ['center_star' => $this->star(8 - ($yearBranch % 3) * 3 - $month), 'branch' => ($month + 2) % 12];
            }
        }
        throw new RuntimeException('節入りを取得できませんでした。');
    }

    public function term(int $year, string $name): CarbonImmutable
    {
        if (! isset($this->terms[$year])) {
            $this->terms[$year] = Solar::fromYmd($year, 7, 1)->getLunar()->getJieQiTable();
        }
        $solar = $this->terms[$year][$name] ?? null;
        // The library's 冬至 is the previous December; DONG_ZHI is this December.
        if ($name === '冬至') {
            $solar = $this->terms[$year]['DONG_ZHI'] ?? null;
        }
        if ($solar === null || $solar->getYear() !== $year) {
            throw new RuntimeException('節気を取得できませんでした。');
        }

        // ShouXingUtil::qiAccurate returns UTC+08:00 wall time, not UTC or Japan time.
        return CarbonImmutable::parse($solar->toYmdHms(), '+08:00')->setTimezone(config('lucky.timezone'));
    }

    private function dayBoard(CarbonImmutable $day): array
    {
        $anchors = [];
        $civilYear = intval($day->format('Y'));
        for ($year = $civilYear - 2; $year <= $civilYear + 1; $year++) {
            foreach (['夏至' => -1, '冬至' => 1] as $term => $step) {
                $solstice = $this->term($year, $term)->startOfDay();
                $index = $this->dayIndex($solstice);
                $anchors[] = ['date' => $solstice->addDays($index <= 29 ? -$index : 60 - $index), 'step' => $step];
            }
        }
        $selected = null;
        for ($i = 1; $i < count($anchors); $i++) {
            $anchor = $anchors[$i];
            $interval = (int) $anchors[$i - 1]['date']->diffInDays($anchor['date']);
            if (! in_array($interval, [180, 240], true)) {
                throw new RuntimeException('転遁の間隔を確定できませんでした。');
            }
            $leap = $interval === 240;
            $boundary = $anchor['date']->subDays($leap ? 30 : 0);
            if ($boundary->lte($day)) {
                $selected = [
                    'date' => $boundary, 'step' => $anchor['step'],
                    'star' => $anchor['step'] === 1 ? ($leap ? 7 : 1) : ($leap ? 3 : 9),
                ];
            }
        }
        if ($selected === null) {
            throw new RuntimeException('日星を確定できませんでした。');
        }

        return [
            'center_star' => $this->star($selected['star'] + $selected['step'] * (int) $selected['date']->diffInDays($day)),
            'branch' => $this->dayIndex($day) % 12,
            'cycle' => $selected['step'] === 1 ? '陽遁' : '陰遁',
        ];
    }

    private function dayIndex(CarbonImmutable $day): int
    {
        return LunarUtil::getJiaZiIndex(Solar::fromYmd(intval($day->format('Y')), intval($day->format('n')), intval($day->format('j')))->getLunar()->getDayInGanZhi());
    }

    private function mod(int $number, int $modulus): int
    {
        return ($number % $modulus + $modulus) % $modulus;
    }

    private function star(int $number): int
    {
        return $this->mod($number - 1, 9) + 1;
    }
}
