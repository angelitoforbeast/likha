<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily HOLD snapshot — 6 AM PH, kino-capture ang KAHAPON (default sa command).
// Tatakbo lang kung naka-setup ang `php artisan schedule:run` sa server crontab.
Schedule::command('holds:snapshot')
    ->timezone('Asia/Manila')
    ->dailyAt('06:00')
    ->withoutOverlapping();
