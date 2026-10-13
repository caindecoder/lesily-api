<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('trials:expire')->dailyAt('00:00')->onOneServer();
Schedule::command('invitations:expire')->dailyAt('00:00')->onOneServer();
Schedule::command('dsar:expire-links')->dailyAt('00:00')->onOneServer();
