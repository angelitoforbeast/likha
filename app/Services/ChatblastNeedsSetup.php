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

    /** Ang mga dahilan ng failed na maaaring sagot ng Facebook na hindi maabot ang customer (hindi setup). */
    private const CANDIDATE_REASONS = ['The flow was not sent (HTTP%', 'The custom field was not written%'];
    /** Ang bahagi ng mga subok ng page na setup-side ang bagsak na nagpapasok sa kanya sa listahan. */
    public const FAIL_RATE = 0.10;

    /**
     * Pinakamaraming hindi naipadala muna. Ang page na bagsak lang dahil hindi maabot ang customer ay wala dito (nasa
     * unreachable()); ang "Send failed" ay nakalista lang kapag ≥10% ng subok ng page ay setup-side ang bagsak sa 3 araw, o
     * bagsak lahat ng subok sa huling oras.
     * @return array<int, array{page: string, group: string, count: int, latest: string}>
     */
    public function list(): array
    {
        return $this->compute()['list'];
    }

    /**
     * Ang mga page na may failed na sagot ng Facebook na hindi maabot ang customer (#551, #100): impormasyon lang.
     * @return array<int, array{page: string, count: int}> pinakamarami muna
     */
    public function unreachable(): array
    {
        return $this->compute()['unreachable'];
    }

    /** Hindi maabot ang customer ang sagot ng Botcake sa flow o field step (#551 "isn't available", #100 "No matching user")? */
    public static function isUnreachable(?array $steps): bool
    {
        foreach ((array) $steps as $s) {
            if (!is_array($s) || !in_array($s['step'] ?? '', ['flow', 'write'], true)) continue;
            $resp = is_string($s['response'] ?? null) ? $s['response'] : (string) json_encode($s['response'] ?? '');
            if (preg_match('/\(#(551|100)\)/', $resp)) return true;
        }

        return false;
    }

    private function compute(): array
    {
        return Cache::remember(self::CACHE, self::TTL, function () {
            if (!Schema::hasTable('botcake_messages')) return ['list' => [], 'unreachable' => []];
            $since = now()->subDays(self::DAYS);
            $hour = now()->subHour();
            $candidate = function ($q) {
                foreach (self::CANDIDATE_REASONS as $i => $like) $i ? $q->orWhere('reason', 'like', $like) : $q->where('reason', 'like', $like);
            };

            $rows = [];
            foreach (BotcakeMessage::where('created_at', '>=', $since)->whereIn('status', self::STATUSES)->where('is_test', false)
                ->where(fn ($q) => $q->where('status', '!=', 'failed')->orWhereNull('reason')->orWhereNot($candidate))
                ->selectRaw('page_name, status, reason, COUNT(*) AS n, MAX(created_at) AS latest')->groupBy('page_name', 'status', 'reason')->get() as $r) {
                $rows[] = ['page_name' => (string) $r->page_name, 'status' => (string) $r->status, 'reason' => (string) $r->reason, 'n' => (int) $r->n, 'latest' => $r->latest];
            }

            // Ang failed ng flow/field: tingnan sa steps kung hindi maabot ang customer ang sagot ng Botcake.
            $unreach = []; $unreachHour = []; $unreachNames = [];
            foreach (BotcakeMessage::where('created_at', '>=', $since)->where('status', 'failed')->where('is_test', false)->where($candidate)
                ->get(['id', 'page_name', 'reason', 'steps', 'created_at']) as $m) {
                $pk = $this->settings->pageKey((string) $m->page_name);
                if (!self::isUnreachable($m->steps)) {
                    $rows[] = ['page_name' => (string) $m->page_name, 'status' => 'failed', 'reason' => (string) $m->reason, 'n' => 1, 'latest' => $m->created_at];
                    continue;
                }
                $unreach[$pk] = ($unreach[$pk] ?? 0) + 1;
                if ($m->created_at >= $hour) $unreachHour[$pk] = ($unreachHour[$pk] ?? 0) + 1;
                $name = $this->settings->normPage((string) $m->page_name);
                $unreachNames[$pk][$name] = ($unreachNames[$pk][$name] ?? 0) + 1;
            }

            // Ilan ang subok ng bawat page at ilan ang bagsak na setup-side (hindi kasama ang hindi maabot ang customer).
            $count = function ($from, array $unreachable) {
                $tried = []; $bad = [];
                $q = BotcakeMessage::where('created_at', '>=', $from)->where('is_test', false)->whereIn('status', ['sent', 'failed', 'field_mismatch', 'unknown'])
                    ->selectRaw('page_name, status, COUNT(*) AS n')->groupBy('page_name', 'status')->get();
                foreach ($q as $r) {
                    $pk = $this->settings->pageKey((string) $r->page_name);
                    $tried[$pk] = ($tried[$pk] ?? 0) + (int) $r->n;
                    if (in_array($r->status, ['failed', 'field_mismatch'], true)) $bad[$pk] = ($bad[$pk] ?? 0) + (int) $r->n;
                }
                foreach ($unreachable as $pk => $n) $bad[$pk] = ($bad[$pk] ?? 0) - $n;

                return [$tried, $bad];
            };
            [$tried, $bad] = $count($since, $unreach);
            [$triedHour, $badHour] = $count($hour, $unreachHour);
            $failing = fn (string $pk) => (($tried[$pk] ?? 0) > 0 && ($bad[$pk] ?? 0) / $tried[$pk] >= self::FAIL_RATE)
                || (($triedHour[$pk] ?? 0) > 0 && ($badHour[$pk] ?? 0) >= $triedHour[$pk]);

            // Ang baybay ng page na ipinapakita: ang may pinakamaraming row (iba-iba ang baybay ng PAGE sa mga order).
            $names = $unreachNames;
            foreach ($rows as $r) {
                $pk = $this->settings->pageKey($r['page_name']);
                $name = $this->settings->normPage($r['page_name']);
                $names[$pk][$name] = ($names[$pk][$name] ?? 0) + $r['n'];
            }
            $names = array_map(function ($spellings) { arsort($spellings); return array_key_first($spellings); }, $names);

            $out = [];
            foreach ($rows as $r) {
                $pk = $this->settings->pageKey($r['page_name']);
                $group = self::group($r['status'], $r['reason']);
                // Ang invalid na token ay laging setup; ang ibang "Send failed" ay kapag ang setup-side na ang bagsak ng page.
                if ($group === self::SEND_FAILED && !preg_match('/^(Token invalid|Stopped: token invalid)/', $r['reason']) && !$failing($pk)) continue;
                $k = $pk . '|' . $group;
                $out[$k] ??= ['page' => $names[$pk], 'group' => $group, 'count' => 0, 'latest' => ''];
                $out[$k]['count'] += $r['n'];
                $out[$k]['latest'] = max($out[$k]['latest'], Carbon::parse($r['latest'])->timezone('Asia/Manila')->format('Y-m-d H:i'));
            }
            $out = array_values($out);
            usort($out, fn ($a, $b) => [$b['count'], $a['page'], $a['group']] <=> [$a['count'], $b['page'], $b['group']]);

            $info = [];
            foreach ($unreach as $pk => $n) $info[] = ['page' => $names[$pk], 'count' => $n];
            usort($info, fn ($a, $b) => [$b['count'], $a['page']] <=> [$a['count'], $b['page']]);

            return ['list' => $out, 'unreachable' => $info];
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
