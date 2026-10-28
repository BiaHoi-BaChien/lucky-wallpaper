<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueueScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_prevents_overlap_but_recovers_from_an_abandoned_lock(): void
    {
        config(['cache.default' => 'database']);
        $this->freezeTime();
        $event = app(Schedule::class)->events()[0];

        $this->assertTrue($event->filtersPass($this->app));
        $this->assertTrue($event->mutex->create($event));
        $this->assertFalse($event->filtersPass($this->app));

        $this->travel(14)->minutes();
        $this->assertFalse($event->filtersPass($this->app));

        $this->travel(2)->minutes();
        $this->assertTrue($event->filtersPass($this->app));
    }
}
