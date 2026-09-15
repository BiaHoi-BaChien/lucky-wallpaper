<?php

namespace Tests\Unit;

use App\Services\CalendarContextService;
use Tests\TestCase;

class CalendarContextServiceTest extends TestCase
{
    public function test_daily_nine_star_names_match_the_dashboard_options(): void
    {
        config(['lucky.timezone' => 'Asia/Ho_Chi_Minh']);

        $service = app(CalendarContextService::class);
        $expectedStars = [
            '2026-09-08' => '九紫火隐元',
            '2026-09-09' => '八白土洞明',
            '2026-09-10' => '七赤金摇光',
            '2026-09-11' => '六白金开阳',
            '2026-09-12' => '五黄土玉衡',
            '2026-09-13' => '四绿木天权',
            '2026-09-14' => '三碧木天玑',
            '2026-09-15' => '二黒土天璇',
            '2026-09-16' => '一白水天枢',
        ];

        foreach ($expectedStars as $date => $expectedStar) {
            $context = $service->forDate($date);

            $this->assertSame($expectedStar, $context['nine_star'], $date);
            $this->assertContains($context['nine_star'], CalendarContextService::NINE_STARS, $date);
            $this->assertSame([], $context['warnings'], $date);
        }
    }

    public function test_known_calendar_context_for_2026_07_26(): void
    {
        config(['lucky.timezone' => 'Asia/Ho_Chi_Minh']);

        $service = app(CalendarContextService::class);
        $context = $service->forDate('2026-07-26');

        $this->assertSame('2026-07-26', $context['target_date']);
        $this->assertSame('Asia/Ho_Chi_Minh', $context['timezone']);
        $this->assertSame('夏', $context['season']);
        $this->assertSame('赤口', $context['rokuyo']);
        $this->assertSame('辛丑', $context['day_ganzhi']);
        $this->assertSame('八白土洞明', $context['nine_star']);
        $this->assertArrayNotHasKey('moon_age', $context);
        $this->assertArrayNotHasKey('moon_illumination', $context);
        $this->assertArrayNotHasKey('moon_phase', $context);
        $this->assertSame([], $context['warnings']);
    }
}
