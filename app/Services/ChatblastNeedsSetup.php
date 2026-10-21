<?php

namespace App\Services;

use App\Models\BotcakeMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * "Needs setup": ang mga page na hindi napapadalhan ng chatblast dahil sa setup, mula sa mga row na mayroon na
 * (botcake_messages, hindi test, huling 3 araw, no_config / failed / field_mismatch), ayon sa page at sa dahilan sa simpleng
 * English. Isang grouped query sa created_at (index), naka-cache nang 60 s. Ang row na naging ready, sent o skipped ay
 * kusang nawawala; ang no_psid ay hindi kasama (pagtutugma iyon, hindi setup).
 */
class ChatblastNeedsSetup
{
    public const DAYS = 3;
    public const STATUSES = ['no_config', 'failed', 'field_mismatch'];
    public const CACHE = 'chatblast:needs-setup';
    public const TTL = 60;

    public const NOT_SET_UP = 'Not set up in page-settings';
    public const MISSING_FLOW = 'Missing flow or message field';
    public const NEEDS_TEST = 'Needs a test send';
    public const SEND_FAILED = 'Send failed';
    public const MESSAGE_DATA = 'Message details missing (order data)';

    public function __construct(private PageSettings $settings)
    {
    }

    /**
     * Pinakamaraming hindi naipadala muna.
     * @return array<int, array{page: string, group: string, count: int, latest: string}>
     */
    public function list(): array
    {
        return Cache::remember(self::CACHE, self::TTL, function () {
            if (!Schema::hasTable('botcake_messages')) return [];
            $rows = BotcakeMessage::where('created_at', '>=', now()->subDays(self::DAYS))->whereIn('status', self::STATUSES)->where('is_test', false)
                ->selectRaw('page_name, status, reason, COUNT(*) AS n, MAX(created_at) AS latest')->groupBy('page_name', 'status', 'reason')->get();

            // Ang baybay ng page na ipinapakita: ang may pinakamaraming row (iba-iba ang baybay ng PAGE sa mga order).
            $names = [];
            foreach ($rows as $r) {
                $pk = $this->settings->pageKey((string) $r->page_name);
                $name = $this->settings->normPage((string) $r->page_name);
                $names[$pk][$name] = ($names[$pk][$name] ?? 0) + (int) $r->n;
            }
            $names = array_map(function ($spellings) { arsort($spellings); return array_key_first($spellings); }, $names);

            $out = [];
            foreach ($rows as $r) {
                $group = self::group((string) $r->status, (string) $r->reason);
                $k = $this->settings->pageKey((string) $r->page_name) . '|' . $group;
                $out[$k] ??= ['page' => $names[$this->settings->pageKey((string) $r->page_name)], 'group' => $group, 'count' => 0, 'latest' => ''];
                $out[$k]['count'] += (int) $r->n;
                $out[$k]['latest'] = max($out[$k]['latest'], Carbon::parse($r->latest)->timezone('Asia/Manila')->format('Y-m-d H:i'));
            }
            $out = array_values($out);
            usort($out, fn ($a, $b) => [$b['count'], $a['page'], $a['group']] <=> [$a['count'], $b['page'], $b['group']]);

            return $out;
        });
    }

    /** Ilang page ang nasa listahan. */
    public static function pageCount(array $list): int
    {
        return count(array_unique(array_map(fn ($r) => mb_strtolower($r['page']), $list)));
    }

    /** Ang dahilan sa simpleng English (pati ang dating Tagalog na "walang …"). */
    public static function group(string $status, string $reason): string
    {
        if ($status !== 'no_config') return self::SEND_FAILED;
        $reason = trim($reason);
        if ($reason === ChatblastReady::NOT_TESTED) return self::NEEDS_TEST;
        // Ang X ng setup check (082) at ang "none delivered": ang dahilan mismo, walang "Page not ready: ".
        if (str_starts_with($reason, 'Page not ready: ')) return mb_substr($reason, strlen('Page not ready: '), 160);
        // Ang kulang sa mensahe (order data o rider info), hindi sa page: English at ang dating Tagalog.
        foreach (['missing {', 'walang {', 'incomplete text', 'hindi buo ang text', 'missing rider info', 'walang rider info'] as $prefix) {
            if (str_starts_with($reason, $prefix)) return self::MESSAGE_DATA;
        }
        if (preg_match('/^(missing|walang) (.+)$/u', $reason, $m)) {
            $keys = array_map('trim', explode(',', $m[2]));
            return array_intersect($keys, ['page_id', 'token']) ? self::NOT_SET_UP : self::MISSING_FLOW;
        }

        return mb_substr($reason !== '' ? $reason : 'Not sent', 0, 160);
    }
}
