<?php

namespace Tests\Unit;

use App\Models\Wallpaper;
use App\Services\WallpaperPromptService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WallpaperPromptServiceTest extends TestCase
{
    #[DataProvider('legacySymbolism')]
    public function test_image_prompt_preserves_legacy_visual_instructions(
        ?string $symbolism,
        array $included,
        array $excluded,
    ): void {
        $wallpaper = (new Wallpaper)->setRawAttributes([
            'target_date' => '2026-09-23',
            'title' => '水辺の鳥',
            'art_style' => '実写写真',
            'overview' => '水辺のハクセキレイ。',
            'composition' => '鳥を右側に配置する。',
            'composition_zone' => 'right',
            'color_wu_xing' => '白と緑。',
            'symbolism' => $symbolism,
        ]);

        $prompt = app(WallpaperPromptService::class)->image($wallpaper)['prompt'];
        $this->assertStringContainsString('制作上の必須条件', $prompt);
        $this->assertStringContainsString('画面外へ流出する水や道は禁止', $prompt);
        $this->assertStringContainsString('元の構図説明が矛盾する場合', $prompt);

        foreach ($included as $text) {
            $this->assertStringContainsString($text, $prompt);
        }
        foreach ($excluded as $text) {
            $this->assertStringNotContainsString($text, $prompt);
        }
        if ($included === []) {
            $this->assertStringNotContainsString('補足描写:', $prompt);
        }
        $this->assertSame($symbolism, $wallpaper->symbolism);
    }

    public static function legacySymbolism(): array
    {
        return [
            'visual instructions alongside financial analysis' => [
                '三枚の柳葉と細い止まり枝を添える。過去の当選額は10,000 VND。',
                ['三枚の柳葉と細い止まり枝を添える。'],
                ['当選額', '10,000', 'VND'],
            ],
            'visual clause before financial clause' => [
                '三枚の柳葉と細い止まり枝を添え、過去の当選額は10,000 VNDだった。',
                ['三枚の柳葉と細い止まり枝を添え'],
                ['当選額', '10,000', 'VND'],
            ],
            'visual clause after financial clause' => [
                '過去の当選額は10,000 VNDだったが、三枚の柳葉と細い止まり枝を添える。',
                ['三枚の柳葉と細い止まり枝を添える。'],
                ['当選額', '10,000', 'VND'],
            ],
            'drawing numbers are preserved' => [
                '葉は3枚、余白は画面の30%、枝は1.5cm程度の細さ。金色の光で金貨を照らす。',
                ['葉は3枚、余白は画面の30%、枝は1.5cm程度の細さ。', '金色の光で金貨を照らす。'],
                [],
            ],
            'unlabelled amounts and statistics on separate lines' => [
                "三枚の柳葉と細い止まり枝を添える。\n333.33 VND／口\n5,000,000 VND\n写真表現の高額割合27.1%は全体とほぼ同じ。",
                ['三枚の柳葉と細い止まり枝を添える。'],
                ['333.33', '5,000,000', 'VND', '27.1%'],
            ],
            'Japanese currency' => [
                '三枚の柳葉と細い止まり枝を添える。１万円。',
                ['三枚の柳葉と細い止まり枝を添える。'],
                ['１万円'],
            ],
            'English financial clause' => [
                'Add three willow leaves, previous prize was 5,000,000 VND. Use soft morning light.',
                ['Add three willow leaves', 'Use soft morning light.'],
                ['previous prize', '5,000,000', 'VND'],
            ],
            'analysis only' => [
                '過去447件を参照。直近14件では上向きが両群に共通。当選確率の向上は主張しない。',
                [],
                ['447件', '14件', '当選確率'],
            ],
            'empty symbolism' => ['', [], []],
            'missing symbolism' => [null, [], []],
        ];
    }

    #[DataProvider('invalidFengShuiSelections')]
    public function test_proposal_rejects_invalid_feng_shui_selections(mixed $selection): void
    {
        try {
            app(WallpaperPromptService::class)->parseProposal(json_encode($this->proposalPayload($selection), JSON_THROW_ON_ERROR));
            $this->fail('A proposal outside the feng shui policy must not be accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('proposal_json', $exception->errors());
            $this->assertStringContainsString('風水', $exception->errors()['proposal_json'][0]);
        }
    }

    public function test_color_names_are_not_blacklisted_when_a_supported_assessment_is_supplied(): void
    {
        foreach (['赤' => '火', '青' => '水', '緑' => '木', '黒' => '水', '紫' => '火'] as $color => $element) {
            $selection = [
                'palette' => [
                    ['color' => 'ベージュ', 'element' => '土', 'role' => '主色'],
                    ['color' => $color, 'element' => $element, 'role' => '差し色'],
                ],
                'color_judgement' => '吉',
                'color_reason' => 'ベージュの土生金を軸に、'.$color.'は低彩度の小さな差し色として全体と調和させる。',
                'flow' => '内向き',
                'structure' => '安定した矩形',
            ];
            $payload = $this->proposalPayload($selection);
            $payload['color_wu_xing'] = 'ベージュを主色に、'.$color.'を小さな差し色として配する。';

            $parsed = app(WallpaperPromptService::class)->parseProposal(json_encode($payload, JSON_THROW_ON_ERROR));

            $this->assertSame($selection, $parsed['feng_shui']);
        }
    }

    private function proposalPayload(mixed $selection): array
    {
        $payload = [
            'title' => '白い庭',
            'art_style' => '実写写真',
            'conclusion' => '白い庭 × 実写写真',
            'overview' => '白い庭と金色の光。',
            'composition' => '丸みのある石を安定した重心で置く。',
            'composition_zone' => 'center',
            'color_wu_xing' => '白と金。',
            'symbolism' => '金の比和を採用。',
        ];
        if ($selection !== null) {
            $payload['feng_shui'] = $selection;
        }

        return $payload;
    }

    public static function invalidFengShuiSelections(): array
    {
        $color = ['color' => '白', 'element' => '金', 'role' => '主色'];
        $valid = [
            'palette' => [$color],
            'color_judgement' => '吉',
            'color_reason' => '白の金と本命星の金の比和を軸に、穏やかな明暗で整える。',
            'flow' => '静穏',
            'structure' => '丸み',
        ];
        $withoutReason = $valid;
        unset($withoutReason['color_reason']);

        return [
            'missing review' => [null],
            'unstructured declaration' => ['吉です'],
            'inauspicious assessment' => [[...$valid, 'color_judgement' => '凶']],
            'unknown assessment' => [[...$valid, 'color_judgement' => '判断不能']],
            'unsupported element' => [[...$valid, 'palette' => [[...$color, 'element' => '空']]]],
            'missing dominant color' => [[...$valid, 'palette' => [[...$color, 'role' => '差し色']]]],
            'missing reason' => [$withoutReason],
            'empty palette' => [[...$valid, 'palette' => []]],
            'duplicate palette' => [[...$valid, 'palette' => [$color, $color]]],
            'non-list palette' => [[...$valid, 'palette' => ['background' => $color]]],
            'outward flow' => [[...$valid, 'flow' => '外向き']],
            'unstable structure' => [[...$valid, 'structure' => '鋭角']],
            'missing flow' => [['palette' => [$color], 'structure' => '丸み']],
            'unexpected override' => [[...$valid, 'override' => true]],
        ];
    }
}
