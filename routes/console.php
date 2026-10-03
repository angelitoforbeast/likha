<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use App\Models\AppSetting;
use App\Models\NightRunStep;
use App\Support\NightRunSettings;

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

// Night run (handoff 007) — macro at Likha import sa oras 1 at oras 2 (default 01:00 at 02:00, Asia/Manila).
// Ang mga switch at oras ay nasa app_settings (CEO lang), binabasa kada `schedule:run` sa pamamagitan ng
// NightRunSettings: mali ang oras → default; DB di available o walang app_settings → lahat OFF.
// Naka-OFF ang switch → WALANG entry para sa hakbang na iyon (hindi lalabas sa `schedule:list`).
$nightSettings = NightRunSettings::read();

foreach ([1 => $nightSettings['night_import_time_1'], 2 => $nightSettings['night_import_time_2']] as $nightSlot => $nightTime) {
    foreach (['macro', 'likha'] as $nightKind) {
        if (!$nightSettings["night_{$nightKind}_import_enabled"]) {
            continue;
        }

        Schedule::command("night:import {$nightKind} {$nightSlot}")
            ->timezone('Asia/Manila')
            ->dailyAt($nightTime)
            ->withoutOverlapping(30); // 30 min lang ang mutex: ang nag-crash na start ay hindi haharang sa susunod na gabi
    }
}

// Astra night run — tick kada minuto. Naka-schedule kapag naka-ON ang Astra switch, O may Astra step na
// `waiting` o `running` (para ang manual na run na naka-off ang switch ay nababantayan pa rin at natatapos).
$nightAstraActive = false;
try {
    if (!$nightSettings['night_astra_enabled'] && Schema::hasTable('night_run_steps')) {
        $nightAstraActive = NightRunStep::where('kind', 'astra')->whereIn('state', ['waiting', 'running'])->exists();
    }
} catch (\Throwable $e) {
    // DB di available — walang tick.
}

if ($nightSettings['night_astra_enabled'] || $nightAstraActive) {
    Schedule::command('night:astra-tick')
        ->timezone('Asia/Manila')
        ->everyMinute()
        ->withoutOverlapping(5); // 5 min: ang tick na nag-crash ay hindi haharang sa mga susunod nang isang araw
}
