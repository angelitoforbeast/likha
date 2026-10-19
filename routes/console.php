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

// Night run — macro at Likha import sa oras 1 at oras 2 (default 01:00 at 02:00, Asia/Manila).
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

// Main item ng bawat page-day (daily_page_primary_item), huling 14 araw — safety net para sa lahat ng
// paraan ng pagbabago ng macro_output rows. 03:50 Manila: pagkatapos ng Astra night step (03:00 hanggang ~03:45).
Schedule::command('supply:refresh-primary-items --last=14')
    ->timezone('Asia/Manila')
    ->dailyAt('03:50')
    ->withoutOverlapping();

// Staff activity log: 90 araw lang ang itinatago. 04:20 Manila: pagkatapos ng night step at ng 03:50 refresh.
Schedule::command('staff-events:prune')
    ->timezone('Asia/Manila')
    ->dailyAt('04:20')
    ->withoutOverlapping();

// Chatbot: ang "wait for reply" na lampas na sa skip_after ay nagpapatuloy sa port na skipped.
// Ang tick ay nagpapadala lang ng mga job sa queue na "chatbot" (doon ang padala).
Schedule::command('chatbot:resume-skipped')
    ->everyMinute()
    ->withoutOverlapping(5); // 5 min: ang tick na nag-crash ay hindi haharang nang isang araw

// Chatbot sequences (drip): ang mga due na step ay ipinapadala bilang job sa queue na "chatbot".
Schedule::command('chatbot:sequence-tick')
    ->everyMinute()
    ->withoutOverlapping(5);

// Mga chat ng customer mula sa Botcake (/api/botcake/chat): 90 araw lang ang itinatago.
Schedule::command('botcake:prune-chats')
    ->timezone('Asia/Manila')
    ->dailyAt('04:25')
    ->withoutOverlapping();

// Botcake PSID import: kusa kada oras (lahat ng settings, walang cutoff), maliban sa 03:00.
// 03:00 Manila: binubura muna sa sheet ang mga row na naitala na sa botcake_psids, saka ang import (tahimik na oras,
// tuloy-tuloy ang dagdag ng Botcake). Naka-queue ang trabaho; ang lock sa job ang pumipigil sa dalawang sabay, kasama ang button.
Schedule::command('botcake:psid-import')
    ->timezone('Asia/Manila')
    ->cron('0 0-2,4-23 * * *')
    ->withoutOverlapping();

Schedule::command('botcake:psid-import --clean-sheet')
    ->timezone('Asia/Manila')
    ->dailyAt('03:00')
    ->withoutOverlapping();

// Botcake customers mula sa API (mga page na may botcake.page_id at token), saka matching ng orders.
// 01:00 Manila: Lunes hanggang Sabado ang by date (mula sa huling malinis na petsa ng bawat page), Linggo ang 45 araw
// (--full); iisang basa kada gabi. Sa background, para hindi maantala ng mahabang basa ang ibang naka-schedule.
// Tatakbo lang kapag BOTCAKE_SYNC_ENABLED=true. Ang server na walang schedule:run ay gumagamit ng --nightly sa cron.
Schedule::command('botcake:sync-customers')
    ->timezone('Asia/Manila')
    ->cron('0 1 * * 1-6')
    ->when(fn () => (bool) config('services.botcake.sync_enabled'))
    ->withoutOverlapping(720) // 12 oras: ang mutex ng patay na proseso ay hindi haharang sa susunod na gabi
    ->runInBackground();

Schedule::command('botcake:sync-customers --full')
    ->timezone('Asia/Manila')
    ->weeklyOn(0, '01:00')
    ->when(fn () => (bool) config('services.botcake.sync_enabled'))
    ->withoutOverlapping(720) // 12 oras: ang mutex ng patay na proseso ay hindi haharang sa susunod na gabi
    ->runInBackground();

// /chatblast auto mode: ang mga campaign na naka-auto ay binubuo at ipinapadala sa kanilang oras ng padala (Manila).
// Kada 5 minuto; ang command ang nagpapasya kung oras na (isang beses kada oras kada araw, hanggang 3 oras pagkatapos).
Schedule::command('chatblast:auto')
    ->timezone('Asia/Manila')
    ->everyFiveMinutes()
    ->withoutOverlapping(30);

// Kusang J&T track-sync (JntAutoTrackSync): kada oras sa :20 Manila (mga 15 minuto pagkatapos ng hourly J&T upload),
// 04:20–23:20 at 02:20. Hanggang 40 minuto; sa background para hindi maantala ang ibang naka-schedule. Ang command ang
// kumukuha ng oras, kaya ang simula mula sa chatblast:auto sa parehong oras ay walang ginagawa. JNT_TRACK_SYNC_AUTO o
// ang toggle sa /jnt/track-sync ang nag-o-on.
Schedule::command('jnt:track-sync-auto')
    ->timezone('Asia/Manila')
    ->cron('20 2,4-23 * * *')
    ->withoutOverlapping(60)
    ->runInBackground();
