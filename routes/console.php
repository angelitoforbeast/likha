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

// Chatbot Speed (/chatbot/speed): ang default na view ng ngayon (at ng kahapon kapag oras na) ay kinukuwenta bago ito mag-expire,
// para ang nagbubukas ng page ay hindi naghihintay ng buong kuwenta (mga 12 s sa isang abalang araw). Isang takbo lang sa isang server.
Schedule::command('chatbot:speed-warm')
    ->everyTwoMinutes()
    ->withoutOverlapping(10) // 10 min: ang takbong nag-crash ay hindi haharang nang matagal
    ->onOneServer()
    ->runInBackground(); // ilang segundo: hindi pinaghihintay ang ibang job ng parehong minuto

// Chatbot content check (/chatbot/pages Check: item, presyo, larawan, ad video laban sa orders): naka-queue ang takbo,
// gabi-gabi 02:40 Manila. Ang bago o nabagong value lang ang itinatanong sa AI.
Schedule::command('chatbot:content-check')
    ->timezone('Asia/Manila')
    ->dailyAt('02:40')
    ->withoutOverlapping();

// Chatbot Results: ang replied_at ng mga padalang hindi naabot ng job ng chat (huling 48 oras).
Schedule::command('chatbot:replied-backfill')
    ->hourlyAt(7)
    ->withoutOverlapping(30);

// Chatblast setup check (flow, field at template ayon sa Setup box ng /chatblast/page-settings): gabi-gabi, 21:30
// Manila, pagkatapos ng In transit window. Ang delivered check ng bagong flow: kada oras sa :45, ang hindi pa kumpirmado lang.
Schedule::command('botcake:setup-check --all')
    ->timezone('Asia/Manila')
    ->dailyAt('21:30')
    ->withoutOverlapping(120)
    ->runInBackground(); // ilang minuto (60 page, 2 tawag kada segundo): hindi pinaghihintay ang ibang 21:30
// Kada 10 minuto sa araw: ang mga page na pinapadalhan ng chatblast lang, para makita agad ang binago o kinopyang flow.
// Wala sa umagang burst (kapareho nito ang Botcake lane ng page). Walang isinusulat kapag walang nagbago.
$setupWindow = config('services.botcake.setup_check_window');
Schedule::command('botcake:setup-check --chatblast')
    ->timezone('Asia/Manila')
    ->everyTenMinutes()
    ->between($setupWindow['from'], $setupWindow['to'])
    ->unlessBetween($setupWindow['skip_from'], $setupWindow['skip_to'])
    ->withoutOverlapping(30)
    ->runInBackground();
// Pagkatapos ng setup check: ang Botcake at Forbeast token ng bawat page sa /chatbot/pages, at ang nawalang chat block
// (page na On at nasa Botcake), para ang Connected at Check ay ayon sa gabing ito.
Schedule::command('chatbot:connections-check')
    ->timezone('Asia/Manila')
    ->dailyAt('22:15')
    ->withoutOverlapping(60)
    ->runInBackground();
Schedule::command('botcake:setup-delivered')
    ->hourlyAt(45)
    ->withoutOverlapping(30);

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

// Ang bantay ng Forbeast: kada minuto, ang health check at ang paghahambing ng chat ng bawat page na nasa Forbeast; ang
// pumapalya ay kusang lumilipat sa Botcake (ForbeastWatch). Sa background, para hindi maantala ang ibang naka-schedule.
Schedule::command('chatbot:forbeast-watch')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

// Ang usage ng chatbot AI papunta sa Forbeast ng bawat page (spec 210): kada minuto, isang job sa queue na "chatbot" (iisa
// lang ang tumatakbo; ang hindi naipadala ay sa susunod). Ang load ng mga page (ChatbotAiLoad) ay sa chatbot:ai-load.
Schedule::job(new \App\Jobs\SendChatbotAiUsage())
    ->everyMinute()
    ->name('chatbot-ai-usage')
    ->withoutOverlapping(10);
Schedule::command('chatbot:ai-load')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// Kusang J&T track-sync (JntAutoTrackSync): kada oras sa :20 Manila (mga 15 minuto pagkatapos ng hourly J&T upload),
// 04:20–23:20 at 02:20. Hanggang 40 minuto; sa background para hindi maantala ang ibang naka-schedule. Ang command ang
// kumukuha ng oras, kaya ang simula mula sa chatblast:auto sa parehong oras ay walang ginagawa. JNT_TRACK_SYNC_AUTO o
// ang toggle sa /jnt/track-sync ang nag-o-on.
// Horizon: ang metrics ng dashboard (kada 5 minuto), kapag nasa Redis na ang chatbot o chatblast queue lang.
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->when(fn () => config('queue.chatbot_connection') === 'redis' || config('queue.chatblast_connection') === 'redis');

Schedule::command('jnt:track-sync-auto')
    ->timezone('Asia/Manila')
    ->cron('20 2,4-23 * * *')
    ->withoutOverlapping(60)
    ->runInBackground();

// Projected RTS% (/owner/private, /api/owner/rts-check): ang p(edad) ng RTS ay natututunan mula sa mga order na tapos na
// (huling 8 linggo). 04:40 Manila: tahimik na oras, pagkatapos ng night step at ng mga 03:50–04:25 na trabaho.
Schedule::command('rts:learn')
    ->timezone('Asia/Manila')
    ->dailyAt('04:40')
    ->withoutOverlapping();

// Mira RTS araw-araw: ang tantya ng final RTS ng bawat page+item (gamit ang rate ng 04:40) at ang accuracy ng mga tantya
// 7 at 14 araw na ang nakalipas. 06:00 Manila: malayo sa night Astra step (03:00–03:45), na tinatanggihan din ng command.
// Ligtas ulitin: isang beses lang bawat araw ang bawat page+item.
Schedule::command('rts:daily-estimate')
    ->timezone('Asia/Manila')
    ->dailyAt(\App\Services\AnalystRtsDaily::RUN_AT)
    ->withoutOverlapping(60)
    ->onOneServer();

// Order encode (spec 130): ang paghahambing ng mga shadow na order sa DATABASE row ng sheet, kada 10 minuto. Walang gagawin kung
// walang order na naka-shadow.
Schedule::command('order-encode:compare')
    ->everyTenMinutes()
    ->withoutOverlapping(30);

// Order encode: ang paghahambing ng encode ng mga imported na order sa final na order sa macro_output, kada 15 minuto.
Schedule::command('order-encode:compare-final')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30);

// Spec 211: ang mga padala ng order sa Forbeast na naka-pause (401/403 ng gateway): isang probe kada gateway kada 5 minuto,
// saka ang lahat kapag tinanggap na ang site key. Walang gagawin kung walang naka-pause.
Schedule::command('order-encode:forbeast-resume')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Order encode: ang natutunang address (address_notes) mula sa mga itinamang at na-deliver na order ng nakaraang 3 araw, 05:30 Manila.
// Ligtas ulitin: bawat order ay isang beses lang nabibilang. Ang buong 60 araw ay manu-mano: order-encode:notes-build --days=60.
Schedule::command('order-encode:notes-build --days=3')
    ->timezone('Asia/Manila')
    ->dailyAt('05:30')
    ->withoutOverlapping(60);

// Meta Ads API → ads_manager_reports: tumatakbo lang kapag naka-on ang write mode (when), at ang command mismo ay sumusulat
// lang kapag naka-on pa rin ito at oras na ng schedule (default 10:00, 15:00, 18:45 Manila; nasa /ads_manager/report, CEO).
Schedule::command('meta-ads:sync --scheduled')
    ->everyMinute()
    ->when(fn () => \App\Services\MetaAds\Sync::writeMode())
    ->withoutOverlapping(120)
    ->runInBackground();

// Ads Manager data health: bawat ad account at kung may data kahapon (sync man o upload); itinatabi para sa pulang paalala
// sa /ads_manager/report. Default 12:00 Manila (services.meta_ads.data_health_at), pagkatapos ng mga upload sa umaga.
Schedule::command('ads:data-health --record')
    ->timezone('Asia/Manila')
    ->dailyAt((string) config('services.meta_ads.data_health_at', '12:00'))
    ->withoutOverlapping(30);

// Nexus stock-in (warehouse) → nexus_stock_ins para sa stock sa /item: kada oras sa :10 Manila, kahapon at ngayon
// (ang unang takbo ay mula sa item_stock_start). Walang token = walang tawag (hindi naka-configure).
Schedule::command('nexus:sync-stock')
    ->timezone('Asia/Manila')
    ->hourlyAt(10)
    ->withoutOverlapping(30);
// Araw-araw 02:40 Manila (wala sa :10 ng hourly, para hindi maharang ng lock nito): ang huling 7 araw ulit, para makuha
// ang stock-in na inilagay ng warehouse sa nakaraang petsa.
Schedule::command('nexus:sync-stock --days=7')
    ->timezone('Asia/Manila')
    ->dailyAt('02:40')
    ->withoutOverlapping(60);
