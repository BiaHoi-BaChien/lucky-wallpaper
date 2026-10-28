<?php

use Illuminate\Support\Facades\Schedule;

// Cover the 240-second worker run plus a final job's 600-second timeout.
Schedule::command('queue:work database --queue=default,integrations --stop-when-empty --max-jobs=10 --max-time=240 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(15);
