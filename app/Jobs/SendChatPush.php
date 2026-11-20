<?php

namespace App\Jobs;

use App\Jobs\Concerns\RestoresMissingDefaults;
use App\Models\ChatMessage;
use App\Services\Chat\ChatPush;
use App\Services\Chat\PushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Likha Chat (spec 308): ang web push ng isang bagong message, sa default queue (database sa server). Hindi ipinapadala
 * kapag na-remove o na-edit na ang message bago tumakbo ito. Ang 404 / 410 ay nag-aalis ng device; ang ibang palya ay
 * inilalagay sa log at sinusubukan ulit hanggang dalawang beses, para sa mga device na pumalya lang (walang doble sa iba).
 */
class SendChatPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RestoresMissingDefaults;

    public int $tries = 1; // ang retry ay sariling job (mga pumalyang device lang), hindi ang buong padala
    public int $timeout = 60;

    /** @param  string[]|null  $only  mga endpoint lang na ito (retry) */
    public function __construct(public int $messageId, public ?array $only = null, public int $attempt = 0)
    {
    }

    public function handle(): void
    {
        $sender = ChatPush::sender();
        if (! ChatPush::enabled()) {
            return;
        }
        $m = ChatMessage::withTrashed()->find($this->messageId);
        if (! $m || $m->trashed() || $m->edited_at !== null || $m->isSystem()) {
            return;
        }
        $deliveries = ChatPush::deliveries($m, $this->only);
        if (! $deliveries) {
            return;
        }

        try {
            $results = $sender->send($deliveries);
        } catch (Throwable $e) {
            $results = array_fill_keys(array_keys($deliveries), PushSender::FAILED);
            Log::warning('chat push: send threw', ['message' => $m->id, 'attempt' => $this->attempt, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }

        $failed = [];
        foreach ($deliveries as $i => $d) {
            $result = $results[$i] ?? PushSender::FAILED;
            if ($result === PushSender::GONE) {
                ChatPush::forget($d['user_id'], $d['endpoint']);
            } elseif ($result !== PushSender::OK) {
                $failed[] = $d['endpoint'];
                // Ang host lang, hindi ang buong endpoint o ang keys.
                Log::warning('chat push failed', ['message' => $m->id, 'user' => $d['user_id'], 'host' => parse_url($d['endpoint'], PHP_URL_HOST), 'attempt' => $this->attempt]);
            }
        }

        if ($failed && $this->attempt < (int) config('chat.push.retries', 2)) {
            self::dispatch($m->id, $failed, $this->attempt + 1)->delay(now()->addSeconds(30 * ($this->attempt + 1)));
        }
    }
}
