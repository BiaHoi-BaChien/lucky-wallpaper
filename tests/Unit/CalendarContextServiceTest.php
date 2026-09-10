<?php

namespace Tests\Unit;

use App\Services\CalendarContextService;
use Tests\TestCase;

class CalendarContextServiceTest extends TestCase
{
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
