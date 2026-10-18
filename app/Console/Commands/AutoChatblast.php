<?php

namespace App\Console\Commands;

use App\Models\BotcakeMessage;
use App\Models\ChatblastCampaign;
use App\Services\Chatblast;
use App\Services\ChatblastSchedule;
use App\Services\ChatblastSender;
use Carbon\Carbon;
use Illuminate\Console\Command;
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
        if ($sender->paused()) {
            $this->line('Naka-pause ang chatblast; walang auto.');
            return self::SUCCESS;
        }

        if (!Schema::hasTable('chatblast_campaigns')) return self::SUCCESS; // hindi pa na-migrate (mano-mano pagkatapos ng deploy)

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

            // Isang takbo lang kada oras: ang nakakuha ng update na ito ang magku-queue (at hindi kung na-off o naging
            // manual habang binubuo).
            $claimed = ChatblastCampaign::whereKey($c->id)->where('active', true)->where('mode', 'auto')
                ->where(fn ($w) => $w->whereNull('auto_ran_at')->orWhere('auto_ran_at', '<', $latest->copy()->timezone(config('app.timezone'))))
                ->update(['auto_ran_at' => now()]);
            if (!$claimed) continue;

            // Ang bago lang: nabuo ngayong araw (Manila) at pagkatapos i-auto ang campaign. Ang naipong ready (backlog)
            // ay hindi kailanman ipinapadala ng auto: sa /chatblast lang, kung gusto. Ang page na wala na sa campaign ay
            // hindi rin (nilalaktawan din ito ng build at ng sender). Ang step na may ibang oras ay sa oras nito.
            $from = $now->copy()->startOfDay();
            if ($c->mode_changed_at && $c->mode_changed_at->gt($from)) $from = $c->mode_changed_at->copy();
            $ids = BotcakeMessage::where('kind', $c->kind)->where('is_test', false)->where('status', 'ready')->whereIn('day', $steps)
                ->where('created_at', '>=', $from->timezone(config('app.timezone')))->get(['id', 'page_name'])
                ->filter(fn ($m) => $chatblast->onPage($c, (string) $m->page_name))->pluck('id')->all();
            $queued = $sender->queue($ids, null);

            $at = implode(', ', array_keys($due));
            Log::info('chatblast: auto', ['campaign' => $c->id, 'times' => $at, 'steps' => $steps, 'added' => $r['added'], 'queued' => $queued]);
            $this->line(sprintf('%s (%s): %d bago, %d naka-queue', $c->name, $at, $r['added'], $queued));
        }

        return self::SUCCESS;
    }
}
