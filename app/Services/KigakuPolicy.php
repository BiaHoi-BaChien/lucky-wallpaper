<?php

namespace App\Services;

use Illuminate\Validation\Rule;

class KigakuPolicy
{
    public const VERSION = 'lucky-wallpaper-v1';

    public const PERSONAL_STAR = '六白金星';

    public const STARS = [1 => '一白水星', 2 => '二黒土星', 3 => '三碧木星', 4 => '四緑木星', 5 => '五黄土星',
        6 => '六白金星', 7 => '七赤金星', 8 => '八白土星', 9 => '九紫火星'];

    public const BRANCHES = ['子', '丑', '寅', '卯', '辰', '巳', '午', '未', '申', '酉', '戌', '亥'];

    public const MOTIFS = [
        1 => ['motifs' => ['水滴', '清流', '白い水鳥'], 'texture' => '透明な水、なめらかな自然の反射'],
        2 => ['motifs' => ['野の草花', '田園の草穂'], 'texture' => 'きめ細かい土、柔らかな生成りの繊維'],
        3 => ['motifs' => ['若芽', '若木'], 'texture' => 'みずみずしい緑、伸びる木の繊維'],
        4 => ['motifs' => ['柳葉', '風になびく草'], 'texture' => 'しなやかな葉、風の通る軽い質感'],
        5 => ['motifs' => ['自然石', '大地の稜線'], 'texture' => '自然の土と石の落ち着いた粒子'],
        6 => ['motifs' => ['白い鳥', '銀白の自然石'], 'texture' => '銀白、自然光の控えめな光沢'],
        7 => ['motifs' => ['白い花', '水辺の鳥'], 'texture' => '白い花弁、淡い銀白の反射'],
        8 => ['motifs' => ['山の稜線', '岩に根づく植物'], 'texture' => '自然岩、積層した石の質感'],
        9 => ['motifs' => ['朝日に照らされた花', '明るい羽の鳥'], 'texture' => '自然光を受けた花弁や羽、控えめな赤紫'],
    ];

    public static function position(?string $direction): array
    {
        [$x, $y] = match ($direction) {
            'north' => [50, 40], 'northeast' => [70, 40], 'east' => [70, 55], 'southeast' => [70, 70],
            'south' => [50, 70], 'southwest' => [30, 70], 'west' => [30, 55], 'northwest' => [30, 40],
            default => [50, 55],
        };

        return ['x_percent' => $x, 'y_percent' => $y];
    }

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
            'policy_version' => ['type' => 'string', 'enum' => [self::VERSION]],
            'target_date' => ['type' => 'string', 'format' => 'date'],
            'personal_star' => ['type' => 'string', 'enum' => [self::PERSONAL_STAR]],
            'timezone' => ['type' => 'string', 'enum' => [config('lucky.timezone')]],
            'status' => ['type' => 'string', 'enum' => array_keys(self::STATUSES)],
            'selected_board' => ['type' => ['string', 'null'], 'enum' => ['day', 'month', 'year', null]],
            'direction_star' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 9],
            'subject_position' => self::objectSchema([
                'x_percent' => ['type' => 'integer', 'enum' => [30, 50, 70]],
                'y_percent' => ['type' => 'integer', 'enum' => [40, 55, 70]],
            ]),
            'method' => $text,
            'candidate_directions' => ['type' => 'array', 'uniqueItems' => true, 'maxItems' => 8, 'items' => ['type' => 'string', 'enum' => $directions]],
            'primary_direction' => ['type' => ['string', 'null'], 'enum' => [...$directions, null]],
            'reason' => $text,
            'fallback_reason' => ['type' => ['string', 'null']],
            'motifs' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => self::objectSchema(['motif' => $text, 'meaning' => $text])],
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
            'kigaku' => ['required', 'array:policy_version,target_date,personal_star,timezone,status,selected_board,direction_star,subject_position,method,candidate_directions,primary_direction,reason,fallback_reason,motifs,references'],
            'kigaku.policy_version' => ['required', Rule::in([self::VERSION])],
            'kigaku.selected_board' => ['present', 'nullable', Rule::in(['day', 'month', 'year'])],
            'kigaku.direction_star' => ['present', 'nullable', 'integer', 'between:1,9'],
            'kigaku.subject_position' => ['required', 'array:x_percent,y_percent'],
            'kigaku.subject_position.x_percent' => ['required', 'integer', Rule::in([30, 50, 70])],
            'kigaku.subject_position.y_percent' => ['required', 'integer', Rule::in([40, 55, 70])],
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
            'kigaku.references' => ['sometimes', 'array', 'list', 'max:20'],
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
        if (isset($kigaku['policy_version'])) {
            $lines[] = '方式の版: '.$kigaku['policy_version'];
            $lines[] = '採用盤: '.(['day' => '日盤', 'month' => '月盤', 'year' => '年盤'][$kigaku['selected_board'] ?? ''] ?? 'なし');
            $lines[] = '回座星: '.(self::STARS[$kigaku['direction_star'] ?? 0] ?? 'なし（六白金星で補完）');
            $lines[] = '主役中心: 左から'.$kigaku['subject_position']['x_percent'].'%、上から'.$kigaku['subject_position']['y_percent'].'%';
        }
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
