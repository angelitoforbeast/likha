<?php

namespace App\Console\Commands;

use App\Models\BotcakeMessage;
use App\Models\ChatblastCampaign;
use App\Services\Chatblast;
use App\Services\ChatblastSchedule;
use App\Services\ChatblastSender;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Auto mode ng /chatblast (kada 5 minuto): ang campaign na naka-on at auto ay binubuo at ipinapadala (queue) sa bawat
 * oras ng padala nito (Manila) sa mga napiling araw ng linggo, isang beses kada oras kada araw; ang step na may sariling
 * oras ay binubuo at ipinapadala sa oras nito lang (ChatblastSchedule). Ang oras ay may bisa hanggang 3 oras
 * pagkatapos: ang mahabang pause o patay na scheduler ay hindi nagpapadala nang gabi. Naka-pause: walang ginagawa (at
 * hindi itinuturing na tumakbo). Ang daily cap ng page at ang iba pang patakaran ay sa sender, gaya ng manual.
 */
class AutoChatblast extends Command
{
    protected $signature   = 'chatblast:auto';
    protected $description = 'Build and queue the /chatblast campaigns in auto mode whose send time has come';

    public function handle(Chatblast $chatblast, ChatblastSender $sender, ChatblastSchedule $schedule): int
    {
        if (!Schema::hasTable('chatblast_campaigns')) return self::SUCCESS; // hindi pa na-migrate (mano-mano pagkatapos ng deploy)

        // Ang kusang refresh ng To send (kada oras sa :15): pagkuha lang, walang padala, kaya tumatakbo kahit naka-pause.
        // Ang pumalyang refresh ay hindi humaharang sa padala ng auto sa takbong ito.
        try {
            if (($r = $chatblast->refreshIfDue()) !== null) {
                Log::info('chatblast: refresh', array_intersect_key($r, array_flip(['busy', 'added', 'skipped', 'tracked'])));
                $this->line(!empty($r['busy']) ? 'Refresh: may tumatakbong pagkuha; sa susunod na takbo.' : sprintf('Refresh: %d bago, %d skipped', $r['added'] ?? 0, $r['skipped'] ?? 0));
            }
        } catch (\Throwable $e) {
            report($e);
            $this->line('Refresh: pumalya; susubok ulit sa susunod na oras.');
        }

        if ($sender->paused()) {
            $this->line('Naka-pause ang chatblast; walang auto.');
            return self::SUCCESS;
        }

        $now = Carbon::now('Asia/Manila');
        foreach (ChatblastCampaign::where('active', true)->where('mode', 'auto')->orderBy('id')->get(ChatblastCampaign::COLUMNS) as $c) {
            // Ang mga oras na dumating na ngayong araw (kung kasama ang araw), at ang mga step na may ganoong oras.
            $due = $schedule->due($c, $now);
            if (!$due || !($steps = $schedule->stepsAt($c, array_keys($due)))) continue;
            $latest = max($due);

            $r = $chatblast->build($c->kind, null, Chatblast::TRACK_BUDGET, $steps);
            if (!empty($r['busy'])) {
                $this->line($c->name . ': may tumatakbong pagkuha; sa susunod na takbo.');
                continue;
            }

            // Isang takbo lang kada oras: naka-lock ang sequence habang itinatala ang mga oras na kinukuha ng takbong ito
            // (ang dapat pa ring tumakbo lang; hindi kapag na-off o naging manual habang binubuo).
            [$claimed, $fresh] = DB::transaction(function () use ($c, $due, $now, $schedule, $latest) {
                $fresh = ChatblastCampaign::whereKey($c->id)->lockForUpdate()->first();
                if (!$fresh || !$fresh->active || $fresh->mode !== 'auto') return [[], null];
                $mine = array_keys(array_intersect_key($schedule->due($fresh, $now), $due));
                if (!$mine) return [[], null];
                $schedule->markRan($fresh, $mine, $now);
                if (!$fresh->auto_ran_at || $fresh->auto_ran_at->lt($latest)) $fresh->auto_ran_at = $latest->copy()->timezone(config('app.timezone'));
                $fresh->save();
                return [$mine, $fresh];
            });
            if (!$claimed) continue;
            // Ang mga step na dumating na ang oras ngayong araw: ang due ngayon, at ang row ng step na tapos na ang oras pero
            // naging ready lang pagkatapos (hal. natugma ang PSID). Hindi kailanman bago ang oras ng step.
            $steps = array_values(array_unique(array_merge($steps, $schedule->stepsCome($fresh, $now)))); // ang schedule ngayon, hindi bago ang build

            // Ang ready na nabuo O nasuri ulit ng isang pagkuha pagkatapos i-auto ang campaign (kasama ang pagkuha sa itaas,
            // na sumusuri sa lahat ng hindi pa naipapadala at tumatatak sa updated_at). Ang hindi nasuri mula bago i-auto ay
            // hindi ipinapadala ng auto. Ang page na wala na sa campaign ay hindi rin (nilalaktawan din ito ng build at ng
            // sender). Ang step na may ibang oras ay sa oras nito.
            $from = ($c->mode_changed_at ?? $now->copy()->startOfDay())->copy()->timezone(config('app.timezone'));
            $ids = BotcakeMessage::where('kind', $c->kind)->where('is_test', false)->where('status', 'ready')->whereIn('day', $steps)
                ->where(fn ($w) => $w->where('created_at', '>=', $from)->orWhere('updated_at', '>=', $from))->get(['id', 'page_name'])
                ->filter(fn ($m) => $chatblast->onPage($c, (string) $m->page_name))->pluck('id')->all();
            $queued = $sender->queue($ids, null);

            $at = implode(', ', $claimed);
            Log::info('chatblast: auto', ['campaign' => $c->id, 'times' => $at, 'steps' => $steps, 'added' => $r['added'], 'queued' => $queued]);
            $this->line(sprintf('%s (%s): %d bago, %d naka-queue', $c->name, $at, $r['added'], $queued));
        }

        return self::SUCCESS;
    }
}
