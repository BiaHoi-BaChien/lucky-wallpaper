<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class KigakuDetermination
{
    public function __construct(private KigakuCalendar $calendar) {}

    public function determine(string $date): array
    {
        $result = [
            'policy_version' => KigakuPolicy::VERSION,
            'target_date' => $date, 'personal_star' => KigakuPolicy::PERSONAL_STAR, 'timezone' => config('lucky.timezone'),
            'status' => 'none', 'selected_board' => null, 'candidate_directions' => [], 'primary_direction' => null,
            'direction_star' => null, 'subject_position' => KigakuPolicy::position(null),
            'method' => 'lucky-wallpaper方式 v1。本命星六白、月命星不使用。日盤＞月盤＞年盤の優先方式。'.KigakuCalendar::BASIS,
            'reason' => '', 'fallback_reason' => '3盤すべてに吉方位がないため、六白金星の象意で補完。',
        ];
        $reasons = [];
        foreach (['day' => '日盤', 'month' => '月盤', 'year' => '年盤'] as $type => $label) {
            try {
                $board = $this->calendar->board($date, $type);
                $judgement = $this->evaluate($board);
            } catch (Throwable $exception) {
                Log::warning('Kigaku calendar unavailable', ['date' => $date, 'board' => $type, 'exception' => $exception->getMessage()]);
                $result['status'] = 'undetermined';
                $result['fallback_reason'] = $label.'を計算できないため判定不能。下位盤へ進まず、六白金星の象意で補完。';
                $reasons[] = $label.'は判定不能。';
                break;
            }
            $candidates = $judgement['candidate_directions'];
            $excluded = [];
            foreach ($judgement['excluded'] as $direction => $names) {
                $excluded[] = KigakuPolicy::DIRECTIONS[$direction].'（'.implode('・', $names).'）';
            }
            $reasons[] = $label.'中宮'.KigakuPolicy::STARS[$board['center_star']].'、十二支'.KigakuPolicy::BRANCHES[$board['branch']]
                .(isset($board['cycle']) ? '、'.$board['cycle'] : '').'。除外: '
                .implode('、', $excluded)
                .'。候補: '.implode('、', array_map(fn (string $d): string => KigakuPolicy::DIRECTIONS[$d], $candidates)).'。';
            if ($candidates !== []) {
                $direction = $candidates[0];
                $result = [...$result,
                    'status' => 'auspicious', 'selected_board' => $type, 'candidate_directions' => $candidates,
                    'primary_direction' => $direction, 'direction_star' => $judgement['stars'][$direction],
                    'subject_position' => KigakuPolicy::position($direction), 'fallback_reason' => null,
                ];
                $reasons[] = $label.'を採用。北→北東→東→南東→南→南西→西→北西の固定順で主方位を選択（吉の強弱ではない）。下位盤は比較しない。';
                break;
            }
        }
        $result['reason'] = implode("\n", $reasons);

        return $result;
    }

    public function evaluate(array $board): array
    {
        if (! is_int($board['center_star'] ?? null) || $board['center_star'] < 1 || $board['center_star'] > 9
            || ! is_int($board['branch'] ?? null) || $board['branch'] < 0 || $board['branch'] > 11) {
            throw new RuntimeException('盤の中宮・十二支が不正です。');
        }
        $stars = [];
        // Lo Shu offsets describe geographic palaces, independent of screen coordinates and daily cycle.
        foreach (['north' => -4, 'northeast' => 3, 'east' => -2, 'southeast' => -1,
            'south' => 4, 'southwest' => -3, 'west' => 2, 'northwest' => 1] as $direction => $offset) {
            $stars[$direction] = ($board['center_star'] + $offset + 8) % 9 + 1;
        }
        $excluded = [];
        foreach ([5 => ['五黄殺', '暗剣殺'], 6 => ['本命殺', '本命的殺']] as $star => [$direct, $opposite]) {
            $direction = array_search($star, $stars, true);
            if ($direction !== false) {
                $excluded[$direction][] = $direct;
                $excluded[$this->opposite($direction)][] = $opposite;
            }
        }
        $branches = ['north', 'northeast', 'northeast', 'east', 'southeast', 'southeast',
            'south', 'southwest', 'southwest', 'west', 'northwest', 'northwest'];
        $excluded[$branches[($board['branch'] + 6) % 12]][] = '破';

        return [
            'stars' => $stars, 'excluded' => $excluded,
            'candidate_directions' => array_keys(array_filter($stars,
                fn (int $star, string $direction): bool => in_array($star, [2, 8, 7, 1], true) && ! isset($excluded[$direction]), ARRAY_FILTER_USE_BOTH)),
        ];
    }

    private function opposite(string $direction): string
    {
        $directions = array_keys(KigakuPolicy::DIRECTIONS);

        return $directions[(array_search($direction, $directions, true) + 4) % 8];
    }
}
