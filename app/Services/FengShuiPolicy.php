<?php

namespace App\Services;

use Illuminate\Validation\Rule;

class FengShuiPolicy
{
    public const VERSION = 'rokupaku-five-elements-v1';

    public const ELEMENTS = ['木', '火', '土', '金', '水'];

    public const COLOR_ROLES = ['主色', '補助色', '差し色'];

    public const FLOWS = ['静穏', '内向き'];

    public const STRUCTURES = ['丸み', '安定した矩形'];

    public const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['palette', 'color_judgement', 'color_reason', 'flow', 'structure'],
        'properties' => [
            'palette' => [
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => 12,
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['color', 'element', 'role'],
                    'properties' => [
                        'color' => ['type' => 'string'],
                        'element' => ['type' => 'string', 'enum' => self::ELEMENTS],
                        'role' => ['type' => 'string', 'enum' => self::COLOR_ROLES],
                    ],
                ],
            ],
            'color_judgement' => ['type' => 'string', 'enum' => ['吉', '凶', '判断不能']],
            'color_reason' => ['type' => 'string'],
            'flow' => ['type' => 'string', 'enum' => self::FLOWS],
            'structure' => ['type' => 'string', 'enum' => self::STRUCTURES],
        ],
    ];

    public static function validationRules(): array
    {
        return [
            'feng_shui' => ['required', 'array:palette,color_judgement,color_reason,flow,structure'],
            'feng_shui.palette' => ['required', 'array', 'list', 'min:1', 'max:12'],
            'feng_shui.palette.*' => ['required', 'array:color,element,role'],
            'feng_shui.palette.*.color' => ['required', 'string', 'max:80', 'distinct'],
            'feng_shui.palette.*.element' => ['required', 'string', Rule::in(self::ELEMENTS)],
            'feng_shui.palette.*.role' => ['required', 'string', Rule::in(self::COLOR_ROLES)],
            'feng_shui.color_judgement' => ['required', Rule::in(['吉'])],
            'feng_shui.color_reason' => ['required', 'string', 'max:4000'],
            'feng_shui.flow' => ['required', 'string', Rule::in(self::FLOWS)],
            'feng_shui.structure' => ['required', 'string', Rule::in(self::STRUCTURES)],
        ];
    }

    public static function context(): array
    {
        return [
            'version' => self::VERSION,
            'personal_star' => '六白金星',
            'personal_element' => '金',
            'supporting_relations' => ['土生金', '比和（金と金）'],
            'elements' => self::ELEMENTS,
            'color_roles' => self::COLOR_ROLES,
            'color_name_blacklist' => [],
            'allowed_flows' => self::FLOWS,
            'allowed_structures' => self::STRUCTURES,
            'screen_zones_are_compass_directions' => false,
        ];
    }

    public static function compositionInstructions(): string
    {
        $visual = self::visualInstructions();

        return <<<PROMPT
風水の必須基準:
利用者の本命星は六白金星（五行：金）。土生金と金同士の比和を参考に、五行全体の相生・相剋と陰陽の調和から配色を評価します。
これは五行と形勢を参考にした壁紙用の基準です。住居の風水鑑定、八宅の命卦、飛星盤や吉方位の判定と混同しないでください。
この基準を過去実績、対象日の九星、画風のローテーション、未知のモチーフ探索より優先してください。分析がない場合も必須です。
色名だけで吉凶を固定せず、白・銀・金・土系の色だけに限定しないでください。赤・青・緑なども、主色・補助色・差し色の役割、面積、彩度、五行の関係から配色全体として判断してください。
相剋の存在だけで凶とせず、過剰な要素や衝突が調和を損なっていないかを確認してください。火剋金が強調される場合などは、分量・彩度の調整や土を介した相生などで調和させ、具体的な調整を記述してください。一律の面積比を風水の普遍的な正解として作ってはいけません。
{$visual}
feng_shui.paletteには背景・差し色を含む各色をcolor・element（五行）・role（主色/補助色/差し色）で列挙し、少なくとも1つの主色を指定してください。色名は自由ですが重複させないでください。
color_judgementには配色全体の評価（吉/凶/判断不能）、color_reasonには具体的な色、五行の関係、分量や彩度を踏まえた根拠を書いてください。根拠なしに吉とせず、判断できなければ判断不能としてください。
flowとstructureには実際の構図に対応する許可値を選んでください。overview、composition、color_wu_xingの描画内容をこの指定と一致させ、symbolismにも配色の評価と配置の基準を満たす理由を書いてください。
出力前に配色・配置・描画内容の整合を点検し、矛盾や禁止表現があれば案を修正してください。単に「吉」「確認済み」と宣言するだけでは不十分です。
九宮構図は画面上の視覚的重心だけを表します。上を北、左を西などと読み替えたり、実際の方位情報がないのに吉方位と断定したりしないでください。
この基準での吉は創作上の判定です。運気や当選確率の向上を保証しないでください。
PROMPT;
    }

    public static function visualInstructions(?array $selection = null): string
    {
        $palette = $selection === null
            ? '元の構図の配色を出発点に、六白金星（金）との五行の関係と明暗の調和を踏まえて配色全体を整える。色名だけで除外せず、過度な相剋は分量・彩度や仲介色で和らげる。'
            : '配色の指定: '.implode('、', array_map(
                fn (array $color): string => $color['color'].'（'.$color['role'].'）',
                $selection['palette'],
            )).'。色の役割を保ち、説明にある分量・彩度・調和を反映する。';
        $flow = $selection['flow'] ?? implode('または', self::FLOWS);
        $structure = $selection['structure'] ?? implode('または', self::STRUCTURES);

        return <<<PROMPT
制作上の必須条件（説明文を画像に描き込まない）:
{$palette}
流れは{$flow}。静穏は動きや流出を強調しない配置、内向きは水・道・視線などが画面内の主要モチーフへ穏やかに集まる配置とする。
主要な形は{$structure}を用い、余白と重心を整える。鋭い先端が主要モチーフや鑑賞者を突き刺す配置、傾倒・落下・崩壊を感じる不安定な配置、画面外へ流出する水や道は禁止する。
炎・破壊・枯死を主題にせず、穏やかな光で明暗を釣り合わせる。これらの条件と元の構図説明が矛盾する場合は、モチーフと画風を可能な限り保ちつつ、配色と配置を条件に合わせて修正する。
PROMPT;
    }
}
