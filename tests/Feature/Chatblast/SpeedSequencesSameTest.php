<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\BotHealth;
use App\Services\Chatbot\ChatbotConnections;
use App\Services\Chatbot\ChatbotSequences;
use App\Services\Chatbot\Gateways;
use App\Services\Chatbot\ResponseSpeed;
use App\Services\Chatbot\SendStatus;
use App\Models\ChatbotReply;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec 277-3: ang Sequences ng /chatbot/speed ay mas magaan basahin (ang kasaysayan bago ang araw ay ang huling event lang
 * ng bawat customer + sequence, hindi ang buong kasaysayan), pero pareho ang lahat ng numero. Bawat seed ay random na data
 * (ilang sequence, kasaysayan bago ang araw, mga ulit at pumalyang padala, skipped, failed, step na tumakbo pagkatapos ng
 * hatinggabi, parehong segundo, walang pangalan o sequence_id, ibang uri ng event ng parehong customer), at ang sagot ng
 * bagong code ay inihahambing sa dating algorithm (oldSequences, kopya nito bago ang spec 277).
 */
class SpeedSequencesSameTest extends ChatblastTestCase
{
    use LoadsSpeedSequences;

    private const DAY = '2026-10-21';

    /** Ang mga filter na inihahambing: lahat, isang page, at bawat bot. */
    private const FILTERS = [[], ['page_id' => '1001'], ['page_id' => '1003'], ['bot' => 'Forbeast'], ['bot' => 'Botcake']];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_06_100000_add_chat_index_to_chatbot_replies.php',
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
            'database/migrations/2026_11_11_100000_add_real_send_to_chatbot_replies.php',
        ]]);
        // Ngayon ay 15:30 ng araw: ang araw ay "ngayon" (hanggang 15:00), ang kahapon ay buo (may step pagkatapos ng hatinggabi).
        $this->travelTo(self::DAY . ' 15:30:00');
        foreach (['Page Uno' => '1001', 'Page Dos' => '1002', 'Page Tres' => '1003'] as $name => $id) $this->page($name, $id);
        DB::table('page_settings')->insert(['page_name' => 'Page Uno', 'provider' => 'chatbot', 'key' => 'provider', 'value' => 'gateway', 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00']);
    }

    /**
     * Random na data ng $customers na customer sa 12 araw hanggang ngayon. Ang ilan ay may maraming event ng ibang sequence
     * bago ang araw (mas marami sa isang basa ng kasaysayan), para masubok ang kasunod na basa.
     */
    private function seedRandom(int $seed, int $customers = 150): void
    {
        mt_srand($seed);
        $r = fn (int $a, int $b) => mt_rand($a, $b);
        $at = fn (int $t) => date('Y-m-d H:i:s', $t);
        $defs = ['Follow up' => [1 => [30, 'minutes'], 2 => [2, 'hours'], 3 => [1, 'days']], 'Promo' => [1 => [10, 'minutes'], 2 => [45, 'minutes'], 3 => [3, 'hours']],
            'Reminder' => [1 => [1, 'days'], 2 => [2, 'days']], 'Old one' => [1 => [20, 'minutes'], 2 => [1, 'hours']]];
        $ids = [];
        foreach (['Follow up', 'Promo', 'Reminder'] as $name) {
            $ids[$name] = DB::table('chatbot_sequences')->insertGetId(['name' => $name, 'active' => true, 'exit_on_reply' => true]);
            foreach ($defs[$name] as $pos => [$amount, $unit]) {
                // Ang step 3 ng Promo ay binura (walang delay: ang naitalang due ang ginagamit).
                if ($name === 'Promo' && $pos === 3) continue;
                DB::table('chatbot_sequence_steps')->insert(['sequence_id' => $ids[$name], 'position' => $pos, 'delay_amount' => $amount, 'delay_unit' => $unit, 'active' => true]);
            }
        }
        $unitSecs = ['minutes' => 60, 'hours' => 3600, 'days' => 86400];
        $day = strtotime(self::DAY . ' 00:00:00');
        $now = now()->getTimestamp();
        [$events, $replies] = [[], []];
        for ($c = 0; $c < $customers; $c++) {
            $page = [1001, 1002, 1003][$c % 3];
            $psid = 5000 + $c;
            $ev = function (int $t, array $d, string $type = 'sequence') use (&$events, $page, $psid, $at): void {
                $events[] = ['page_id' => $page, 'psid' => $psid, 'type' => $type, 'created_at' => $at($t), 'detail' => json_encode($d)];
            };
            // Ibang uri ng event ng parehong customer (kamukha ng sequence ang detail, pero hindi sequence).
            for ($i = 0, $n = $r(0, 25); $i < $n; $i++) $ev($day + $r(-12 * 86400, 15 * 3600), ['op' => 'ran', 'name' => 'Follow up', 'step' => 1], ['node', 'message', 'ai'][$i % 3]);
            $burst = $c % 11 === 0;
            // Ang burst: Reminder na ang step 1 ay dalawang araw bago, ang step 2 ay sa araw (ang naunang event ay luma).
            $names = $burst ? ['Reminder'] : array_rand($defs, $r(1, 2));
            foreach ((array) $names as $name) {
                $t = $burst ? $day - 3 * 86400 + $r(0, 10 * 3600) : $day + $r(-6 * 86400, 14 * 3600);
                $ev($t, ['op' => 'subscribed', 'name' => $name]);
                if ($r(0, 4) === 0) $ev($t + $r(0, 1200), ['op' => 'subscribed', 'name' => $name]);
                $prev = $t;
                foreach ($defs[$name] as $pos => [$amount, $unit]) {
                    $due = $prev + $amount * $unitSecs[$unit];
                    $lag = $r(0, 99) < 80 ? $r(0, 300) : $r(300, 30000);
                    $ran = $r(0, 30) === 0 ? $prev : $due + $lag; // minsan sa parehong segundo ng nauna
                    if ($ran > $now) break;
                    $roll = $r(0, 99);
                    $op = $roll < 75 ? 'ran' : ($roll < 87 ? 'skipped' : 'failed');
                    $d = ['op' => $op, 'name' => $name, 'step' => $pos];
                    if ($r(0, 9) > 0) $d['sequence_id'] = $ids[$name] ?? 999;
                    if ($r(0, 9) > 0) $d['due'] = $at($due + $r(0, 3) * 60);
                    if ($r(0, 40) === 0) unset($d['name']);
                    $ev($ran, $d);
                    if ($r(0, 30) === 0) $ev($ran, $d); // dobleng event
                    if ($op === 'ran') {
                        $k = $r(0, 9);
                        $via = $page === 1001 ? 'forbeast' : ($r(0, 5) === 0 ? 'forbeast' : 'botcake');
                        $send = ['page_id' => $page, 'psid' => $psid, 'node_id' => 'n1', 'kind' => 'send', 'sequence_id' => $r(0, 25) === 0 ? 999 : ($ids[$name] ?? 999),
                            'sequence_step' => $pos, 'via' => $via, 'confirmed_at' => null, 'confirmed_status' => null];
                        if ($k >= 1 && $k <= 3) $replies[] = ['status' => 'failed', 'sent_at' => $at($ran - $r(30, 590))] + $send; // ulit
                        if ($k >= 1) {
                            $pushed = $k === 9 ? $ran - $r(601, 1200) : $ran - $r(0, 120);
                            $status = ['sent', 'sent', 'sent', 'partial', 'failed'][$r(0, 4)];
                            $meta = $status !== 'failed' && $r(0, 4) > 0 ? $pushed + $r(0, 90) : null;
                            $replies[] = ['status' => $status, 'sent_at' => $at($pushed), 'confirmed_at' => $meta === null ? null : $at($meta),
                                'confirmed_status' => $meta === null ? null : ($r(0, 8) === 0 ? 'failed' : 'sent')] + $send;
                            if ($r(0, 6) === 0) $replies[] = ['status' => 'sent', 'sent_at' => $at($pushed + $r(1, 60)), 'confirmed_at' => $at($pushed + $r(61, 200)), 'confirmed_status' => 'sent'] + $send;
                        }
                    }
                    if ($r(0, 12) === 0) $ev($ran + $r(1, 600), ['op' => 'unsubscribed', 'name' => $name]); // hindi binibilang
                    $prev = $ran;
                }
            }
            // Maraming event ng ibang sequence bago ang araw, mas bago kaysa sa huling event ng sequence ng customer.
            if ($burst) for ($i = 0; $i < 30; $i++) $ev($day - $r(600, 86400), ['op' => $i % 3 ? 'ran' : 'subscribed', 'name' => 'Burst', 'step' => 1]);
        }
        shuffle($events); // ang ID ay hindi ayon sa oras
        foreach (array_chunk($events, 400) as $rows) DB::table('chatbot_events')->insert($rows);
        foreach (array_chunk($replies, 400) as $rows) DB::table('chatbot_replies')->insert($rows);
    }

    public static function seeds(): array
    {
        return ['seed 11' => [11], 'seed 23' => [23], 'seed 37' => [37], 'seed 41' => [41], 'seed 59' => [59]];
    }

    #[DataProvider('seeds')]
    public function test_S_277_3_the_new_sequences_give_the_same_numbers_as_the_old(int $seed): void
    {
        $this->seedRandom($seed);
        $speed = app(ResponseSpeed::class);
        $checked = 0;
        // Ang basa ng huling event bago ang araw: may unang basa, at may kasunod na basa (customer na maraming ibang event).
        $reads = ['first' => 0, 'more' => 0];
        DB::listen(function ($q) use (&$reads): void {
            if (!str_contains($q->sql, 'from "chatbot_events"') || !str_contains($q->sql, 'order by "created_at" desc')) return;
            $reads[str_contains($q->sql, '"id" <') ? 'more' : 'first']++;
        });
        foreach (['2026-10-21', '2026-10-20', '2026-10-19'] as $date) {
            foreach (self::FILTERS as $q) {
                $f = ResponseSpeed::filters($q + ['date' => $date]);
                Cache::flush();
                $new = $speed->sequencesOutput($f)['sequences'];
                $old = $this->oldBuild($f);
                $this->assertArrayNotHasKey('too_large', $new, "seed $seed $date " . json_encode($q));
                // Spec 301: due → sent is new (ResponseSpeedTest covers it); the rest is the old code's, number for number.
                $this->assertSame($old, self::withoutDueToSent($new), "seed $seed $date " . json_encode($q));
                $checked += $old['total']['due'];
            }
        }
        $this->assertGreaterThan(100, $checked, 'the seed has steps');
        $this->assertGreaterThan(0, $reads['first'], 'history before the day was read');
        $this->assertGreaterThan(0, $reads['more'], 'a customer needed a second history read');
    }

    // ── ang dating algorithm (bago ang spec 277), kopya para sa paghahambing ───────────────────────────────────────

    private function oldBuild(array $f): array
    {
        $today = now()->toDateString();
        $start = $f['date'] . ' 00:00:00';
        $end = CarbonImmutable::parse($f['date'])->addDay()->toDateString() . ' 00:00:00';

        return $this->oldSequences($f, $start, $end, $f['date'] === $today ? (int) now()->format('G') : 23);
    }

    private static function oldWindows(string $from, string $to): \Generator
    {
        for ($a = $from, $t = strtotime($from); $a < $to; $a = $b) {
            $t += 3600;
            $b = min(date('Y-m-d H:i:s', $t), $to);
            yield [$a, $b];
        }
    }

    private function oldCheckBudget(int $budget): void
    {
        if (memory_get_usage(true) > $budget) throw new \OverflowException('over budget');
    }

    private static function oldStats(array $v): array
    {
        return ['n' => count($v), 'avg' => $v ? round(array_sum($v) / count($v), 1) : null, 'median' => BotHealth::median($v),
            'p90' => BotHealth::percentile($v, 90), 'max' => $v ? max($v) : null];
    }

    private static function oldDelayStats(array $v): array
    {
        $s = self::oldStats($v);

        return ['n' => $s['n'], 'avg' => $s['avg'], 'p90' => $s['p90'], 'max' => $s['max']];
    }

    private function oldSequences(array $f, string $start, string $end, int $nowHour): array
    {
        $budget = PHP_INT_MAX;
        $blank = ['due' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'none' => 0, 'botcake' => 0, 'push' => [], 'meta' => []];
        $hours = array_fill(0, 24, $blank);
        $total = $blank;
        $late = date('Y-m-d H:i:s', strtotime($end) + ResponseSpeed::STEP_LATE_SECONDS);
        [$pages, $psids, $names, $first] = [[], [], [], []];
        $maxId = $this->oldStepEvents($f['page_id'], $start, $late, $budget, function (object $e, array $d) use (&$pages, &$psids, &$names, &$first): void {
            $pages[(int) $e->page_id] = true;
            $psids[(int) $e->psid] = true;
            $name = (string) ($d['name'] ?? '');
            $names[$name] = true;
            $k = $e->page_id . ':' . $e->psid . ':' . $name;
            if (!isset($first[$k]) || (string) $e->created_at < $first[$k]) $first[$k] = (string) $e->created_at;
        });
        $before = $this->oldStepHistory(array_keys($pages), array_keys($psids), $first, $late, $budget);
        $delays = $this->oldStepDelays(array_map('strval', array_keys($names)));
        unset($pages, $psids, $names, $first);
        $sends = $this->oldStepSends(date('Y-m-d H:i:s', strtotime($start) - ResponseSpeed::STEP_PUSH_SECONDS), $late, $f['page_id'], $budget);
        $botOf = $f['bot'] !== null ? BotHealth::botOfPages() : [];
        $slow = new \SplMinHeap();
        $n = 0;

        $this->oldStepEvents($f['page_id'], $start, $late, $budget, function (object $e, array $d) use ($f, $start, $end, $before, $delays, $sends, $botOf, $slow, &$n, &$hours, &$total): void {
            $n++;
            $due = self::oldOriginalDue($e, $d, $before, $delays);
            $at = $due ?? (string) $e->created_at;
            if ($at < $start || $at >= $end) return;
            if ($f['bot'] !== null && ($botOf[(string) $e->page_id] ?? ChatbotConnections::BOTS[0]) !== $f['bot']) return;

            $op = $d['op'];
            $row = null;
            if ($op === 'ran') {
                $row = self::oldStepSend($sends[$e->page_id . ':' . $e->psid . ':' . (int) ($d['step'] ?? 0)] ?? '', $d['sequence_id'] ?? null, strtotime((string) $e->created_at));
                $op = $row === null ? 'none' : ($row['delivered'] ? 'sent' : 'failed');
            }
            $h = &$hours[(int) substr($at, 11, 2)];
            foreach ([&$h, &$total] as &$b) {
                $b['due']++;
                if (isset($b[$op])) $b[$op]++;
                if ($op !== 'sent') continue;
                if ($due !== null) $b['push'][] = max(0, $row['pushed'] - strtotime($due));
                if ($row['meta'] !== null) $b['meta'][] = max(0, $row['meta'] - $row['pushed']);
                if ($row['botcake']) $b['botcake']++;
            }
            unset($b, $h);
            if ($op !== 'sent') return;
            $sent = $row['meta'] ?? ($row['botcake'] ? $row['pushed'] : null);
            $item = [max(0, ($sent ?? $row['pushed']) - strtotime($due ?? date('Y-m-d H:i:s', $row['pushed']))), $row['pushed'], -$n,
                [$e->page_id, (string) ($d['name'] ?? ''), (int) ($d['step'] ?? 0), $due, $row, $sent]];
            if ($slow->count() < ResponseSpeed::SLOWEST) {
                $slow->insert($item);
            } elseif ($item > $slow->top()) {
                $slow->extract();
                $slow->insert($item);
            }
        }, $maxId);

        $top = [];
        foreach ($slow as $item) $top[] = $item;
        usort($top, fn ($a, $b) => [$b[0], $b[1], $b[2]] <=> [$a[0], $a[1], $a[2]]);
        $pageNames = \App\Models\BotcakeChat::pageMap();
        $slowest = array_map(function (array $item) use ($pageNames): array {
            [$delay, , , [$page, $name, $step, $due, $row, $sent]] = $item;

            return ['page' => $pageNames[(string) $page] ?? 'Page ' . $page, 'sequence' => mb_substr($name, 0, 120), 'step' => $step,
                'scheduled' => $due, 'pushed' => date('Y-m-d H:i:s', $row['pushed']), 'sent' => $sent === null ? null : date('Y-m-d H:i:s', $sent),
                'scheduled_to_pushed' => $due === null ? null : max(0, $row['pushed'] - strtotime($due)),
                'pushed_to_meta' => $row['meta'] === null ? null : max(0, $row['meta'] - $row['pushed']),
                'delay' => $delay, 'sent_is_pushed' => $row['botcake']];
        }, $top);

        $out = fn ($b) => ['due' => $b['due'], 'sent' => $b['sent'], 'failed' => $b['failed'], 'skipped' => $b['skipped'], 'no_message' => $b['none'], 'botcake' => $b['botcake'],
            'scheduled_to_pushed' => self::oldDelayStats($b['push']), 'pushed_to_meta' => self::oldDelayStats($b['meta'])];

        return ['hours' => array_map(fn ($h) => $h > $nowHour ? null : ['hour' => $h] + $out($hours[$h]), range(0, 23)), 'total' => $out($total),
            'slowest' => $slowest];
    }

    private function oldStepEvents(?string $page, string $from, string $to, int $budget, \Closure $each, ?int $upTo = null): int
    {
        $maxId = 0;
        foreach (self::oldWindows($from, $to) as [$a, $b]) {
            $rows = DB::table('chatbot_events')->where('type', 'sequence')->where('created_at', '>=', $a)->where('created_at', '<', $b)
                ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))->when($upTo !== null, fn ($q) => $q->where('id', '<=', $upTo))
                ->orderBy('created_at')->orderBy('id')->select(['id', 'page_id', 'psid', 'detail', 'created_at'])->cursor();
            foreach ($rows as $e) {
                $maxId = max($maxId, (int) $e->id);
                $d = json_decode((string) $e->detail, true);
                if (is_array($d) && in_array($d['op'] ?? null, ['ran', 'skipped', 'failed'], true)) $each($e, $d);
            }
            $this->oldCheckBudget($budget);
        }

        return $maxId;
    }

    private function oldStepHistory(array $pages, array $psids, array $first, string $until, int $budget): array
    {
        $before = [];
        foreach (array_chunk($psids, 500) as $chunk) {
            $rows = DB::table('chatbot_events')->where('type', 'sequence')->whereIn('page_id', $pages)->whereIn('psid', $chunk)->where('created_at', '<', $until)
                ->orderBy('created_at')->orderBy('id')->select(['page_id', 'psid', 'detail', 'created_at'])->cursor();
            foreach ($rows as $r) {
                $d = json_decode((string) $r->detail, true);
                if (!is_array($d) || !is_string($d['name'] ?? null) || !in_array($d['op'] ?? null, ['subscribed', 'ran', 'skipped', 'failed'], true)) continue;
                $k = $r->page_id . ':' . $r->psid . ':' . $d['name'];
                if (!isset($first[$k])) continue;
                $t = (string) $r->created_at;
                if ($t < $first[$k] || !isset($before[$k])) $before[$k] = $t;
                else $before[$k] .= "\n" . $t;
            }
            $this->oldCheckBudget($budget);
        }

        return $before;
    }

    private function oldStepDelays(array $names): array
    {
        $delays = [];
        if ($names && Schema::hasTable('chatbot_sequence_steps')) {
            $ids = DB::table('chatbot_sequences')->whereIn('name', $names)->pluck('id', 'name');
            foreach (DB::table('chatbot_sequence_steps')->whereIn('sequence_id', $ids->values()->all())->get(['sequence_id', 'position', 'delay_amount', 'delay_unit']) as $s) {
                $delays[$s->sequence_id . ':' . $s->position] = [(int) $s->delay_amount, (string) $s->delay_unit];
            }
            foreach ($ids as $name => $id) $delays['name:' . $name] = (int) $id;
        }

        return $delays;
    }

    private static function oldOriginalDue(object $e, array $d, array $before, array $delays): ?string
    {
        $recorded = is_string($d['due'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $d['due']) ? $d['due'] : null;
        $name = (string) ($d['name'] ?? '');
        $id = is_int($d['sequence_id'] ?? null) ? $d['sequence_id'] : ($delays['name:' . $name] ?? null);
        $delay = $id !== null ? ($delays[$id . ':' . (int) ($d['step'] ?? 0)] ?? null) : null;
        $prev = null;
        $history = $before[$e->page_id . ':' . $e->psid . ':' . $name] ?? null;
        foreach ($history === null ? [] : explode("\n", $history) as $t) {
            if ($t >= (string) $e->created_at) break;
            $prev = $t;
        }
        if ($delay === null || $prev === null) return $recorded;

        return ChatbotSequences::dueAt(\Carbon\Carbon::parse($prev), $delay[0], $delay[1])->format('Y-m-d H:i:s');
    }

    private function oldStepSends(string $startAt, string $endAt, ?string $page, int $budget): array
    {
        if (!Schema::hasColumn('chatbot_replies', 'sequence_id')) return [];
        $confirm = ChatbotReply::hasConfirm();
        $via = ChatbotReply::hasVia();
        $cols = array_merge(['page_id', 'psid', 'sequence_id', 'sequence_step', 'status', 'sent_at'], $confirm ? ['confirmed_at', 'confirmed_status'] : [], $via ? ['via'] : []);
        $out = [];
        foreach (self::oldWindows($startAt, $endAt) as [$a, $b]) {
            $rows = DB::table('chatbot_replies')->where('kind', 'send')->whereNotNull('sequence_id')->where('sent_at', '>=', $a)->where('sent_at', '<', $b)
                ->when($page !== null, fn ($q) => $q->where('page_id', (int) $page))->orderBy('sent_at')->orderBy('id')->select($cols)->cursor();
            foreach ($rows as $r) {
                $real = $confirm && in_array($r->confirmed_status, SendStatus::DELIVERED, true) && $r->confirmed_at !== null;
                $botcake = !Gateways::valid($via ? $r->via : null);
                $meta = $real && !$botcake;
                $k = $r->page_id . ':' . $r->psid . ':' . (int) $r->sequence_step;
                $out[$k] ??= '';
                $out[$k] .= pack('JJJC', strtotime($r->sent_at), (int) $r->sequence_id, $meta ? strtotime($r->confirmed_at) : 0,
                    (in_array($r->status, SendStatus::DELIVERED, true) ? 1 : 0) | ($botcake ? 2 : 0) | ($meta ? 4 : 0));
            }
            $this->oldCheckBudget($budget);
        }

        return $out;
    }

    private static function oldStepSend(string $sends, mixed $sequenceId, int $ranAt): ?array
    {
        $pushed = $first = null;
        for ($o = 0, $len = strlen($sends); $o < $len; $o += 25) {
            $s = unpack('Jat/Jseq/Jmeta/Cflags', $sends, $o);
            if ($s['at'] > $ranAt || $s['at'] < $ranAt - ResponseSpeed::STEP_PUSH_SECONDS || (is_int($sequenceId) && $s['seq'] !== $sequenceId)) continue;
            $pushed ??= $s;
            if ($s['flags'] & 1) {
                $first = $s;
                break;
            }
        }
        if ($pushed === null) return null;

        return ['pushed' => $pushed['at'], 'delivered' => $first !== null, 'meta' => $first !== null && ($first['flags'] & 4) ? $first['meta'] : null,
            'botcake' => (bool) (($first ?? $pushed)['flags'] & 2)];
    }
}
