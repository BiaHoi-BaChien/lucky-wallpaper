<?php

namespace Tests\Unit;

use App\Models\CompositionProposal;
use App\Models\Wallpaper;
use App\Services\KigakuCalendar;
use App\Services\KigakuPolicy;
use App\Services\WallpaperPromptService;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WallpaperPromptServiceTest extends TestCase
{
    #[DataProvider('savedPositions')]
    public function test_image_regeneration_uses_saved_direction_and_coordinates_without_recalculating(
        ?string $direction, string $label, int $x, int $y,
    ): void {
        $calendar = Mockery::mock(KigakuCalendar::class);
        $calendar->shouldNotReceive('board');
        $this->app->instance(KigakuCalendar::class, $calendar);
        $kigaku = [
            'policy_version' => KigakuPolicy::VERSION,
            'primary_direction' => $direction, 'direction_star' => $direction === null ? null : 7,
            'subject_position' => ['x_percent' => $x, 'y_percent' => $y],
        ];
        $proposal = new CompositionProposal([
            'wallpaper_id' => 42, 'status' => 'approved', 'title' => '白い花', 'art_style' => '実写写真',
            'overview' => '朝の花。', 'composition' => '主役を配置する。', 'color_wu_xing' => '銀白。', 'symbolism' => '',
            'calendar_context' => ['policy_version' => KigakuPolicy::VERSION, 'kigaku' => $kigaku],
        ]);
        $wallpaper = new Wallpaper(['id' => 42, 'target_date' => '2026-10-22', ...$proposal->only(KigakuPolicy::COMPOSITION_FIELDS)]);
        $wallpaper->setRelation('chosenProposal', $proposal);
        $service = app(WallpaperPromptService::class);
        foreach ([null, $proposal] as $selected) {
            $prompt = $service->image($wallpaper, $selected)['prompt'];
            $this->assertStringContainsString('保存済みの主方位: '.$label, $prompt);
            $this->assertStringContainsString('回座星: '.($direction === null ? 'なし（六白金星で補完）' : '七赤金星'), $prompt);
            $this->assertStringContainsString('左から'.$x.'%、上から'.$y.'%', $prompt);
            $this->assertStringContainsString('上部30%', $prompt);
        }
        $this->assertSame($kigaku, $proposal->calendar_context['kigaku']);
    }

    public static function savedPositions(): array
    {
        return [
            ['north', '北', 50, 40], ['northeast', '北東', 70, 40], ['east', '東', 70, 55], ['southeast', '南東', 70, 70],
            ['south', '南', 50, 70], ['southwest', '南西', 30, 70], ['west', '西', 30, 55], ['northwest', '北西', 30, 40],
            [null, '指定なし', 50, 55],
        ];
    }

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
}
