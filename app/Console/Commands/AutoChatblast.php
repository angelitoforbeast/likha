<?php

namespace App\Console\Commands;

use App\Models\BotcakeMessage;
use App\Models\ChatblastCampaign;
use App\Services\Chatblast;
use App\Services\ChatblastSender;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Auto mode ng /chatblast (naka-schedule kada 5 minuto): ang campaign na naka-on at auto ay binubuo at ipinapadala
 * (queue) sa bawat oras ng padala nito (Manila), isang beses kada oras kada araw. Ang oras ay may bisa hanggang 3 oras
 * pagkatapos: ang mahabang pause o patay na scheduler ay hindi nagpapadala nang gabi. Naka-pause: walang ginagawa (at
 * hindi itinuturing na tumakbo). Ang daily cap ng page at ang iba pang patakaran ay sa sender, gaya ng manual.
 */
class AutoChatblast extends Command
{
    protected $signature   = 'chatblast:auto';
    protected $description = 'Build and queue the /chatblast campaigns in auto mode whose send time has come';

    public const LATE_LIMIT_HOURS = 3;

    public function handle(Chatblast $chatblast, ChatblastSender $sender): int
    {
        if ($sender->paused()) {
            $this->line('Naka-pause ang chatblast; walang auto.');
            return self::SUCCESS;
        }

        $now   = Carbon::now('Asia/Manila');
        $today = $now->copy()->startOfDay()->timezone(config('app.timezone'));
        foreach (ChatblastCampaign::where('active', true)->where('mode', 'auto')->orderBy('id')->get(ChatblastCampaign::COLUMNS) as $c) {
            $slot = $this->dueSlot($c, $now);
            if ($slot === null) continue;

            $r = $chatblast->build($c->kind, null);
            if (!empty($r['busy'])) {
                $this->line($c->name . ': may tumatakbong pagkuha; sa susunod na takbo.');
                continue;
            }

            // Isang takbo lang kada oras: ang nakakuha ng update na ito ang magku-queue (at hindi kung na-off o naging
            // manual habang binubuo).
            $claimed = ChatblastCampaign::whereKey($c->id)->where('active', true)->where('mode', 'auto')
                ->where(fn ($w) => $w->whereNull('auto_ran_at')->orWhere('auto_ran_at', '<', $slot->copy()->timezone(config('app.timezone'))))
                ->update(['auto_ran_at' => now()]);
            if (!$claimed) continue;

            $ids = BotcakeMessage::where('kind', $c->kind)->where('is_test', false)->where('status', 'ready')
                ->where('created_at', '>=', $today)->pluck('id')->all();
            $queued = $sender->queue($ids, null);

            Log::info('chatblast: auto', ['campaign' => $c->id, 'slot' => $slot->format('H:i'), 'added' => $r['added'], 'queued' => $queued]);
            $this->line(sprintf('%s (%s): %d bago, %d naka-queue', $c->name, $slot->format('H:i'), $r['added'], $queued));
        }

        return self::SUCCESS;
    }

    /** Ang pinakahuling oras ng padala ngayong araw na dumating na (hanggang 3 oras) at hindi pa tumatakbo, o null. */
    private function dueSlot(ChatblastCampaign $c, Carbon $now): ?Carbon
    {
        $due = null;
        foreach ((array) $c->send_times as $time) {
            if (!is_string($time) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m)) continue;
            $at = $now->copy()->setTime((int) $m[1], (int) $m[2]);
            if ($at->gt($now) || $now->gte($at->copy()->addHours(self::LATE_LIMIT_HOURS))) continue;
            if ($due === null || $at->gt($due)) $due = $at;
        }
        if ($due === null || ($c->auto_ran_at && $c->auto_ran_at->gte($due))) return null;

        return $due;
    }
}
