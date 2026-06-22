<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use App\Models\AppSetting;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily HOLD snapshot — kino-capture ang KAHAPON (default sa command).
// Ang ORAS ay EDITABLE sa UI (/jnt/hold-snapshots/schedule) — naka-store sa
// app_settings['hold_snapshot_time'] (HH:MM, Asia/Manila). Default 06:00.
// Binabasa kada `schedule:run` kaya agad na-pipick-up ang pagbabago (mabilis i-debug).
// Tatakbo LANG kung naka-setup ang `php artisan schedule:run` sa server crontab.
$holdSnapshotTime = '06:00';
try {
    if (Schema::hasTable('app_settings')) {
        $t = AppSetting::get('hold_snapshot_time', '06:00');
        if (is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) {
            $holdSnapshotTime = $t;
        }
    }
} catch (\Throwable $e) {
    // DB di available sa ibang artisan command (hal. fresh migrate) — fallback 06:00.
}

Schedule::command('holds:snapshot')
    ->timezone('Asia/Manila')
    ->dailyAt($holdSnapshotTime)
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/holds-snapshot.log')); // file log ng cron output
