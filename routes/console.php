<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('workflows:sweep')->everyMinute()->withoutOverlapping();
Schedule::command('workflows:run-schedules')->everyMinute()->withoutOverlapping();
Schedule::command('workflows:poll-triggers')->everyMinute()->withoutOverlapping();
Schedule::command('workflows:purge-executions')->hourly()->withoutOverlapping();
Schedule::command('workflows:prune-store')->hourly()->withoutOverlapping();
