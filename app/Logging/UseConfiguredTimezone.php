<?php

namespace App\Logging;

use DateTimeZone;
use Illuminate\Log\Logger as IlluminateLogger;
use Monolog\Logger as MonologLogger;

final class UseConfiguredTimezone
{
    public function __invoke(IlluminateLogger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! $monolog instanceof MonologLogger) {
            return;
        }

        $monolog->setTimezone(
            new DateTimeZone((string) config('app.timezone', 'UTC')),
        );
    }
}
