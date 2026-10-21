<?php

namespace App\Services;

use Illuminate\Validation\Rule;

class KigakuPolicy
{
    public const VERSION = 'kigaku-v1';

    public const PERSONAL_STAR = '六白金星';

    public const DIRECTIONS = [
        'north' => '北', 'northeast' => '北東', 'east' => '東', 'southeast' => '南東',
        'south' => '南', 'southwest' => '南西', 'west' => '西', 'northwest' => '北西',
    ];

    public const STATUSES = ['auspicious' => '吉方位あり', 'none' => '吉方位なし', 'undetermined' => '判定不能'];

    public const GROUPS = self::DIRECTIONS + ['none' => '吉方位なし', 'undetermined' => '判定不能', 'legacy' => '方位情報なし'];

    public const COMPOSITION_FIELDS = ['title', 'art_style', 'conclusion', 'overview', 'composition', 'color_wu_xing', 'symbolism'];

    public static function schema(): array
    {
        $text = ['type' => 'string'];
        $directions = array_keys(self::DIRECTIONS);
        $kigaku = [
            'target_date' => ['type' => 'string', 'format' => 'date'],
            'personal_star' => ['type' => 'string', 'enum' => [self::PERSONAL_STAR]],
            'timezone' => ['type' => 'string', 'enum' => [config('lucky.timezone')]],
            'status' => ['type' => 'string', 'enum' => array_keys(self::STATUSES)],
            'method' => $text,
            'candidate_directions' => ['type' => 'array', 'uniqueItems' => true, 'maxItems' => 8, 'items' => ['type' => 'string', 'enum' => $directions]],
            'primary_direction' => ['type' => ['string', 'null'], 'enum' => [...$directions, null]],
            'reason' => $text,
            'fallback_reason' => ['type' => ['string', 'null']],
            'motifs' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => self::objectSchema(['motif' => $text, 'meaning' => $text])],
            'references' => ['type' => 'array', 'maxItems' => 20, 'items' => self::objectSchema([
                'title' => $text, 'url' => ['type' => ['string', 'null']],
            ])],
        ];

        return self::objectSchema([
            ...array_fill_keys(self::COMPOSITION_FIELDS, $text),
            'kigaku' => self::objectSchema($kigaku),
        ]);
    }

    private static function objectSchema(array $properties): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($properties), 'properties' => $properties];
    }

    public static function validationRules(string $targetDate): array
    {
        return [
            'kigaku' => ['required', 'array:target_date,personal_star,timezone,status,method,candidate_directions,primary_direction,reason,fallback_reason,motifs,references'],
            'kigaku.target_date' => ['required', 'date_format:Y-m-d', Rule::in([$targetDate])],
            'kigaku.personal_star' => ['required', Rule::in([self::PERSONAL_STAR])],
            'kigaku.timezone' => ['required', Rule::in([config('lucky.timezone')])],
            'kigaku.status' => ['required', Rule::in(array_keys(self::STATUSES))],
            'kigaku.method' => ['required', 'string', 'max:4000'],
            'kigaku.candidate_directions' => ['present', 'array', 'list', 'max:8'],
            'kigaku.candidate_directions.*' => ['required', 'string', 'distinct:strict', Rule::in(array_keys(self::DIRECTIONS))],
            'kigaku.primary_direction' => ['present', 'nullable', 'string', Rule::in(array_keys(self::DIRECTIONS))],
            'kigaku.reason' => ['required', 'string', 'max:10000'],
            'kigaku.fallback_reason' => ['present', 'nullable', 'string', 'max:4000'],
            'kigaku.motifs' => ['required', 'array', 'list', 'min:1', 'max:20'],
            'kigaku.motifs.*' => ['required', 'array:motif,meaning'],
            'kigaku.motifs.*.motif' => ['required', 'string', 'max:500'],
            'kigaku.motifs.*.meaning' => ['required', 'string', 'max:2000'],
            'kigaku.references' => ['present', 'array', 'list', 'max:20'],
            'kigaku.references.*' => ['required', 'array:title,url'],
            'kigaku.references.*.title' => ['required', 'string', 'max:500'],
            'kigaku.references.*.url' => ['present', 'nullable', 'url:http,https', 'max:2000'],
        ];
    }

    public static function group(?array $kigaku): string
    {
        $status = $kigaku['status'] ?? null;
        $direction = $kigaku['primary_direction'] ?? null;
        if ($status === 'auspicious' && is_string($direction) && isset(self::DIRECTIONS[$direction])) {
            return $direction;
        }

        return in_array($status, ['none', 'undetermined'], true) ? $status : 'legacy';
    }

    public static function description(array $kigaku): string
    {
        $lines = [
            '九星気学の判定',
            '対象日: '.($kigaku['target_date'] ?? ''),
            '本命星: '.($kigaku['personal_star'] ?? ''),
            'タイムゾーン: '.($kigaku['timezone'] ?? ''),
            '判定: '.(self::STATUSES[$kigaku['status'] ?? ''] ?? '判定情報なし'),
            '主方位: '.(self::DIRECTIONS[$kigaku['primary_direction'] ?? ''] ?? 'なし'),
            '候補方位: '.implode('、', array_map(fn (string $direction): string => self::DIRECTIONS[$direction] ?? $direction, $kigaku['candidate_directions'] ?? [])),
            '採用方式: '.($kigaku['method'] ?? ''),
            '判断根拠: '.($kigaku['reason'] ?? ''),
        ];
        if (! empty($kigaku['fallback_reason'])) {
            $lines[] = '補完理由: '.$kigaku['fallback_reason'];
        }
        foreach ($kigaku['motifs'] ?? [] as $motif) {
            $lines[] = $motif['motif'].': '.$motif['meaning'];
        }
        foreach ($kigaku['references'] ?? [] as $reference) {
            $lines[] = '参照資料: '.$reference['title'].($reference['url'] === null ? '' : ' '.$reference['url']);
        }

        return implode("\n", $lines);
    }
}
