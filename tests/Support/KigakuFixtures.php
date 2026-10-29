<?php

namespace Tests\Support;

use App\Models\Wallpaper;
use App\Services\KigakuDetermination;
use App\Services\KigakuPolicy;

class KigakuFixtures
{
    public static function currentPayload(string $date = '2026-10-22'): array
    {
        $payload = self::payload($date);
        $kigaku = app(KigakuDetermination::class)->determine($date);
        $star = $kigaku['direction_star'] ?? 6;
        $payload['kigaku'] = [...$kigaku, 'motifs' => [[
            'motif' => KigakuPolicy::MOTIFS[$star]['motifs'][0], 'meaning' => KigakuPolicy::STARS[$star].'の象意。',
        ]]];
        $payload['composition'] = '主役中心は左'.$kigaku['subject_position']['x_percent'].'%、上'.$kigaku['subject_position']['y_percent'].'%。';

        return $payload;
    }

    public static function payload(string $date = '2026-10-22', string $status = 'auspicious', string $direction = 'north'): array
    {
        return [
            'title' => '白い鳥と水辺', 'art_style' => '実写写真', 'conclusion' => '白い鳥と水辺 × 実写写真',
            'overview' => '白い鳥が水辺に佇む。', 'composition' => '鳥と柳を画面中央に配置する。',
            'color_wu_xing' => '白と青を配する。', 'symbolism' => '主方位の象意を鳥と水辺に表現する。',
            'kigaku' => [
                'target_date' => $date, 'personal_star' => '六白金星', 'timezone' => 'Asia/Ho_Chi_Minh',
                'status' => $status, 'method' => 'テスト用の日盤＞月盤＞年盤の優先方式。対象日は現地の暦日を採用。',
                'candidate_directions' => $status === 'auspicious' ? array_values(array_unique([$direction, 'east'])) : [],
                'primary_direction' => $status === 'auspicious' ? $direction : null,
                'reason' => 'テスト用の判定根拠。', 'fallback_reason' => $status === 'auspicious' ? null : '本命星の象意で補完する。',
                'motifs' => [['motif' => '白い鳥', 'meaning' => '方位または六白金星の象意を表す。']],
            ],
        ];
    }

    public static function wallpaper(string $date, string $direction = 'north', array $attributes = [], string $status = 'auspicious'): Wallpaper
    {
        $payload = self::payload($date, $status, $direction);
        $wallpaper = Wallpaper::factory()->create([...array_intersect_key($payload, array_flip(KigakuPolicy::COMPOSITION_FIELDS)), 'target_date' => $date, ...$attributes]);
        $proposal = $wallpaper->proposals()->create([
            ...array_intersect_key($payload, array_flip(KigakuPolicy::COMPOSITION_FIELDS)),
            'sequence' => 1, 'status' => 'approved', 'input_hash' => str_repeat('a', 64),
            'calendar_context' => ['policy_version' => 'kigaku-v1', 'kigaku' => $payload['kigaku']],
        ]);
        $wallpaper->update(['chosen_proposal_id' => $proposal->id]);

        return $wallpaper;
    }
}
