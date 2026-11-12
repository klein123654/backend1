<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Runs where a cron is available; on Render the GitHub Actions workflow calls the API instead.
Schedule::command('listings:send-availability-reminders')->dailyAt('08:00');
