<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('queue:work database --queue=default,integrations,openai --stop-when-empty --max-jobs=10 --max-time=240 --tries=3')
    ->everyMinute()
    ->withoutOverlapping();
