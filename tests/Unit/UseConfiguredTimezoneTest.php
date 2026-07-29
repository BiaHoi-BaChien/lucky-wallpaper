<?php

namespace Tests\Unit;

use App\Logging\UseConfiguredTimezone;
use DateTimeZone;
use Illuminate\Log\Logger as IlluminateLogger;
use Monolog\Logger as MonologLogger;
use Tests\TestCase;

class UseConfiguredTimezoneTest extends TestCase
{
    public function test_it_applies_the_application_timezone_to_monolog(): void
    {
        config(['app.timezone' => 'Asia/Ho_Chi_Minh']);
        $monolog = new MonologLogger(
            name: 'test',
            timezone: new DateTimeZone('UTC'),
        );

        (new UseConfiguredTimezone)(new IlluminateLogger($monolog));

        $this->assertSame(
            'Asia/Ho_Chi_Minh',
            $monolog->getTimezone()->getName(),
        );
    }
}
