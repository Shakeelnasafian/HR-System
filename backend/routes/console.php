<?php

use Illuminate\Support\Facades\Schedule;

// Commands live in app/Console/Commands. Sub-minute schedules need `php artisan schedule:work` (or schedule:run every
// minute, which then loops for the minute).
Schedule::command('hr:outbox-relay')->everyFiveSeconds()->withoutOverlapping(1)->onOneServer()
    // Scheduled children otherwise write to /dev/null; send output and stderr logs (critical outbox alerts) to the container log.
    ->appendOutputTo(env('SCHEDULE_OUTPUT', '/proc/1/fd/2'));
