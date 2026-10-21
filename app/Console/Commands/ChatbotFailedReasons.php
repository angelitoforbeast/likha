<?php

namespace App\Console\Commands;

use App\Models\ChatbotReply;
use App\Services\BotcakeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Basa lang: ang mga pumalyang padala ng chatbot (chatbot_replies, status failed) mula --since, pinagsama ayon sa totoong
 * dahilan (mula sa naitalang sagot ng Botcake, raw). Ang "Botcake said …" na walang naitalang sagot (bago ito naidagdag)
 * ay "unknown (before 098)"; ang walang sagot talaga (walang token, naka-off, walang koneksyon) ay ang error mismo.
 */
class ChatbotFailedReasons extends Command
{
    public const UNKNOWN = 'unknown (before 098)';

    protected $signature   = 'chatbot:failed-reasons {--since=-7 days : From when (e.g. "-2 days" or 2026-10-20)}';
    protected $description = 'Group recent failed chatbot sends by the real reason Botcake gave (read-only)';

    public function handle(): int
    {
        if (!Schema::hasTable('chatbot_replies')) {
            $this->info('No chatbot_replies table.');
            return self::SUCCESS;
        }
        try {
            $since = Carbon::parse((string) $this->option('since'));
        } catch (\Throwable) {
            $this->error('--since: not a date (e.g. "-2 days" or 2026-10-20).');
            return self::FAILURE;
        }

        $cols = ['id', 'error', 'http', 'sent_at'];
        if (ChatbotReply::hasRaw()) $cols[] = 'raw';
        $groups = [];
        $total = 0;
        ChatbotReply::where('status', 'failed')->where('sent_at', '>=', $since)->orderBy('id')->select($cols)
            ->chunkById(1000, function ($rows) use (&$groups, &$total) {
                foreach ($rows as $r) {
                    $total++;
                    $why = $this->reason($r);
                    $g = $groups[$why] ?? ['n' => 0, 'last' => null, 'shown' => ''];
                    $g['n']++;
                    if ($g['last'] === null || $r->sent_at >= $g['last']) [$g['last'], $g['shown']] = [$r->sent_at, (string) $r->error];
                    $groups[$why] = $g;
                }
            });

        uasort($groups, fn ($a, $b) => [$b['n'], $b['last']] <=> [$a['n'], $a['last']]);
        $this->info(count($groups) . ' reasons, ' . $total . ' failed sends since ' . $since->copy()->timezone('Asia/Manila')->format('Y-m-d H:i') . ' (Manila)');
        $this->table(['Count', 'Reason', 'Last', 'Shown as'], array_map(fn ($why, $g) => [(string) $g['n'], $why,
            $g['last']?->copy()->timezone('Asia/Manila')->format('Y-m-d H:i') ?? '', mb_substr($g['shown'], 0, 120)], array_keys($groups), $groups));

        return self::SUCCESS;
    }

    private function reason(ChatbotReply $r): string
    {
        $raw = $r->getAttribute('raw');
        if (is_string($raw) && $raw !== '') {
            $why = BotcakeClient::messageText(json_decode($raw, true) ?? ['message' => $raw]);
            return mb_substr($why !== '' ? $why : (string) $r->error, 0, 200);
        }
        // Ang "Botcake said …" ay galing sa sagot ng Botcake, pero hindi pa naitatala ang sagot noon.
        if (str_starts_with((string) $r->error, 'Botcake said')) return self::UNKNOWN;

        return (string) $r->error;
    }
}
