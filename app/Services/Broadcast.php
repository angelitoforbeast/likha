<?php

namespace App\Services;

use App\Jobs\QueueBroadcast;
use App\Jobs\SendChatblastMessage;
use App\Models\BotcakeMessage;
use App\Models\BotcakePsid;
use App\Models\ChatblastBroadcast;
use App\Support\BotcakeClock;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Broadcast (spec 263): one owner-written text to a chosen audience of PSIDs (botcake_psids), sent like a chatblast
 * message (write field.message, read it back, send a flow) through the same job, send pool, lanes and rate limits.
 *
 * The definition is a ChatblastBroadcast row (chatblast_campaigns, mode broadcast); the sends are botcake_messages rows
 * (kind broadcast, campaign_id, one per page and PSID by dedupe_key). Rows are inserted as "waiting" by QueueBroadcast in
 * batches, and handed to the sender by feed() under the per-page hourly cap and an in-flight limit per page.
 */
class Broadcast
{
    public const KIND = 'broadcast';           // botcake_messages.kind
    public const WAYBILL = 'BROADCAST';        // botcake_messages.waybill (not null; a broadcast has no waybill)
    public const PSID_STATUSES = ['available', 'verified'];
    public const DEFAULT_CAP = 10000;          // sends per page per hour
    public const MAX_CAP = 20000;
    public const IN_FLIGHT = 60;               // default: broadcast rows per page in the chatblast queue or the send pool at once (services.botcake.broadcast_in_flight)
    public const FAIL_STREAK = 20;             // failures in a row on one page that pause it
    public const BATCH = 1000;                 // rows read and inserted per queueing step
    public const CANCEL_BATCH = 5000;
    public const MAX_NAME = 100;
    public const FLOWS_TTL = 600;

    public const WAITING = 'waiting';          // in the broadcast, not handed to the sender yet
    public const CANCELLED = 'cancelled';
    /** Every status of a row that was handed to the sender (queued_at set): counted by the hourly cap. */
    public const HANDED = ['queued', 'sending', 'sent', 'failed', 'field_mismatch', 'unknown', 'skipped', 'no_config', self::CANCELLED];

    public const DRAFT = 'draft';
    public const SENDING = 'sending';
    public const PAUSED = 'paused';
    public const STOPPED = 'stopped';
    public const DONE = 'done';

    public const ORDERS = ['any', 'with', 'without'];
    public const PHONES = ['any', 'has', 'none'];
    public const DATES = ['subscribed_before', 'subscribed_from', 'interaction_before', 'interaction_from'];
    public const ALL_WARNING = 'No filter: this is every PSID on the chosen pages.';
    public const STREAK_REASON = '20 failures in a row';
    /** A send answer that says the page itself may not message (Meta #2022 / subcode 1893063, "restricted" or "blocked"). */
    /** The customer cannot be messaged any more (blocked the page, left Messenger): not a page problem. */
    private const UNREACHABLE_SAID = "/\\(#551\\)|isn't available right now/i";
    public const UNREACHABLE = 'Customer not reachable (#551)';
    private const RESTRICTED = '/#2022\b|\b1893063\b|restricted from (sending|messaging)|blocked from (sending|messaging)|temporarily blocked/i';

    public function __construct(private PageSettings $settings)
    {
    }

    /** The `broadcast` column exists (the code can arrive before the migration is run by hand). */
    public static function migrated(): bool
    {
        return Schema::hasTable('chatblast_campaigns') && Schema::hasColumn('chatblast_campaigns', 'broadcast');
    }

    /** The pages that can be chosen: every page with all of Chatblast::REQUIRED set. */
    public function pages(): array
    {
        return $this->settings->pagesWith('botcake', Chatblast::REQUIRED);
    }

    // ---- Input ----

    /**
     * The checked input, or the error. Pages must be among pages() (by page key); dates are Y-m-d; the text follows the
     * chatblast message rules and has no {placeholder} (nothing fills it in a broadcast). $forSend: the text is required.
     * @return array{pages: array<int, string>, filters: array, text: string, flow: ?string, cap: int, name: string}|string
     */
    public function validate(array $in, bool $forSend = false): array|string
    {
        $allowed = [];
        foreach ($this->pages() as $p) $allowed[$this->settings->pageKey($p)] = $p;
        $pages = [];
        foreach ((array) ($in['pages'] ?? []) as $p) {
            if (!is_string($p)) return 'Unknown page.';
            $key = $this->settings->pageKey($p);
            if (!isset($allowed[$key])) return 'Unknown page: ' . mb_substr($p, 0, 80) . '.';
            $pages[$key] = $allowed[$key];
        }
        if (!$pages) return 'Pick at least one page.';

        $filters = ['orders' => (string) ($in['orders'] ?? 'any'), 'phone' => (string) ($in['phone'] ?? 'any')];
        if (!in_array($filters['orders'], self::ORDERS, true)) return 'Orders: any, with or without.';
        if (!in_array($filters['phone'], self::PHONES, true)) return 'Phone: any, has or none.';
        foreach (self::DATES as $d) {
            $v = trim((string) ($in[$d] ?? ''));
            if ($v !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)))) {
                return 'Dates are YYYY-MM-DD.';
            }
            $filters[$d] = $v === '' ? null : $v;
        }

        $text = is_string($in['text'] ?? null) ? str_replace("\r\n", "\n", $in['text']) : '';
        if ($text !== '' || $forSend) {
            if (($why = app(Chatblast::class)->invalid([[$text]])) !== null) return 'Text: ' . preg_replace('/^Day 1 · A: /', '', $why);
            if (preg_match('/\{[^{}]*\}/', $text, $m)) return 'Text: ' . $m[0] . ' is not filled in a broadcast; write the text as the customer should see it.';
        }

        $flow = trim((string) ($in['flow'] ?? ''));
        if (mb_strlen($flow) > 255) return 'Flow name: at most 255 characters.';
        $cap = (int) ($in['cap'] ?? self::DEFAULT_CAP);
        if ($cap < 1 || $cap > self::MAX_CAP) return 'Cap: 1 to ' . self::MAX_CAP . ' per page per hour.';
        $name = trim((string) ($in['name'] ?? ''));

        return [
            'pages' => array_values($pages), 'filters' => $filters, 'text' => $text, 'flow' => $flow === '' ? null : $flow, 'cap' => $cap,
            'name' => mb_substr($name !== '' ? $name : 'Broadcast ' . now('Asia/Manila')->format('Y-m-d H:i'), 0, self::MAX_NAME),
        ];
    }

    public static function noFilter(array $filters): bool
    {
        if (($filters['orders'] ?? 'any') !== 'any' || ($filters['phone'] ?? 'any') !== 'any') return false;
        foreach (self::DATES as $d) if (($filters[$d] ?? null) !== null) return false;

        return true;
    }

    // ---- Audience ----

    /**
     * The audience of one page as index-ranged branches (each selects b.psid, b.fb_name): the rows with the page's
     * page_id, and the rows without a page_id yet whose page name matches (BotcakePsid::ofPage, without its OR). Only
     * available or verified rows, and the filters.
     * @return array<int, Builder>
     */
    public function branches(string $page, array $filters): array
    {
        $pageId = trim((string) $this->settings->get($page, 'botcake', 'page_id'));
        $key = $this->settings->pageKey($page);
        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        $byName = fn (Builder $q) => $q->whereRaw("LOWER($trim(b.page)) = ?", [$key]);
        $base = fn () => $this->filtered(DB::table('botcake_psids as b')->whereIn('b.status', self::PSID_STATUSES), $key, $filters);

        if ($pageId === '' || !BotcakePsid::hasPageId()) return [$byName($base())];

        return [$base()->where('b.page_id', $pageId), $byName($base()->whereNull('b.page_id'))];
    }

    private function filtered(Builder $q, string $pageKey, array $f): Builder
    {
        $day = fn (string $d) => Carbon::parse($d, 'Asia/Manila')->startOfDay()->timezone(config('app.timezone'))->format('Y-m-d H:i:s');
        if ($f['subscribed_before'] ?? null) $q->where('b.subscribed_at', '<', $day($f['subscribed_before']));
        if ($f['subscribed_from'] ?? null) $q->where('b.subscribed_at', '>=', $day($f['subscribed_from']));
        if ($f['interaction_before'] ?? null) $q->where('b.last_interactive_at', '<', $day($f['interaction_before']));
        if ($f['interaction_from'] ?? null) $q->where('b.last_interactive_at', '>=', $day($f['interaction_from']));

        $len = match (DB::getDriverName()) {
            'sqlite' => 'json_array_length(b.phone_numbers)',
            'pgsql' => 'json_array_length(b.phone_numbers::json)',
            default => 'JSON_LENGTH(b.phone_numbers)',
        };
        if (($f['phone'] ?? 'any') === 'has') $q->whereNotNull('b.phone_numbers')->whereRaw("$len > 0");
        if (($f['phone'] ?? 'any') === 'none') $q->where(fn ($w) => $w->whereNull('b.phone_numbers')->orWhereRaw("$len = 0"));

        // "With an order": the PSID is the botcake_psid of an order of the same page (macro_output.botcake_psid is indexed).
        $orders = $f['orders'] ?? 'any';
        if ($orders !== 'any') {
            $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
            $sub = fn ($s) => $s->select(DB::raw(1))->from('macro_output as mo')->whereColumn('mo.botcake_psid', 'b.psid')
                ->whereRaw("LOWER($trim(mo.PAGE)) = ?", [$pageKey]);
            $orders === 'with' ? $q->whereExists($sub) : $q->whereNotExists($sub);
        }

        return $q;
    }

    /** The audience count of one page: each PSID once (UNION of the branches). */
    public function count(string $page, array $filters): int
    {
        $branches = array_map(fn (Builder $b) => $b->select('b.psid'), $this->branches($page, $filters));
        if (count($branches) === 1) return (int) $branches[0]->distinct()->count('b.psid');
        $union = array_shift($branches);
        foreach ($branches as $b) $union->union($b);

        return (int) DB::query()->fromSub($union, 'u')->count();
    }

    // ---- Flows ----

    /**
     * The page's flows from Botcake (GET /pages/{id}/flows, names only), cached 10 minutes; the removed ones are left out.
     * @return array{flows: ?array<string, string>, error: ?string} flow id => name
     */
    public function flows(string $page, bool $refresh = false): array
    {
        $key = 'chatblast-broadcast-flows:' . sha1($this->settings->pageKey($page));
        if (!$refresh && is_array($hit = Cache::get($key))) return $hit;
        [$flows, $error] = app(BotcakeFlowNames::class)->readFlows($page, app(BotcakeClock::class)->now() + 20);
        $out = ['flows' => $flows === null ? null : array_map(fn ($f) => $f['name'], array_filter($flows, fn ($f) => !$f['removed'])), 'error' => $error];
        Cache::put($key, $out, $error === null ? self::FLOWS_TTL : 60);

        return $out;
    }

    /**
     * The flow the page sends: by name among its own flows (never another flow), or its flow.shipping when no name is
     * picked. @return array{id: ?string, name: ?string, error: ?string}
     */
    public function resolveFlow(string $page, ?string $name): array
    {
        $read = $this->flows($page);
        if ($name === null) {
            $id = trim((string) $this->settings->get($page, 'botcake', 'flow.shipping'));
            return ['id' => $id, 'name' => $read['flows'][$id] ?? 'flow.shipping (' . $id . ')', 'error' => null];
        }
        if ($read['flows'] === null) return ['id' => null, 'name' => $name, 'error' => 'The flows could not be read (' . $read['error'] . ')'];
        $want = ChatblastSetupCheck::norm($name);
        $ids = array_keys(array_filter($read['flows'], fn ($n) => ChatblastSetupCheck::norm((string) $n) === $want));
        if (!$ids) return ['id' => null, 'name' => $name, 'error' => 'No flow named "' . $name . '"'];
        if (count($ids) > 1) return ['id' => null, 'name' => $name, 'error' => count($ids) . ' flows are named "' . $name . '"'];

        return ['id' => (string) $ids[0], 'name' => (string) $read['flows'][$ids[0]], 'error' => null];
    }

    /** The flow names across the pages (for the picker), and the pages whose list could not be read. */
    public function flowNames(array $pages): array
    {
        $names = [];
        $errors = [];
        foreach ($pages as $page) {
            $read = $this->flows($page);
            if ($read['flows'] === null) {
                $errors[$page] = $read['error'];
                continue;
            }
            foreach ($read['flows'] as $n) $names[ChatblastSetupCheck::norm((string) $n)] ??= ['name' => (string) $n, 'pages' => 0];
            foreach (array_unique(array_map(fn ($n) => ChatblastSetupCheck::norm((string) $n), $read['flows'])) as $k) $names[$k]['pages']++;
        }
        ksort($names);

        return ['names' => array_values($names), 'errors' => $errors];
    }

    // ---- Draft, preview, start ----

    /** The hash of what a page's count and flow depend on: the filters and the flow name. */
    public static function hash(array $filters, ?string $flow): string
    {
        ksort($filters);

        return sha1((string) json_encode([$filters, $flow], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Save the draft (new, or the same draft). No count here: a web request counts one page at most (countPage, asked
     * per page by the browser). A page keeps its cached count when the filters and flow are the same as when it was
     * counted (a reload does not recount); otherwise its count is cleared.
     */
    public function preview(?int $id, array $input, ?int $userId): array|string
    {
        $v = $this->validate($input);
        if (is_string($v)) return $v;
        $b = $id === null ? null : ChatblastBroadcast::find($id);
        if ($id !== null && !$b) return 'No such broadcast.';
        if ($b && $b->state() !== self::DRAFT) return 'This broadcast has started; it cannot be changed.';

        $hash = self::hash($v['filters'], $v['flow']);
        $old = $b ? (array) ($b->broadcast['pages'] ?? []) : [];
        $pages = [];
        foreach ($v['pages'] as $page) {
            $prev = $old[$page] ?? null;
            $pages[$page] = is_array($prev) && ($prev['hash'] ?? null) === $hash && ($prev['count'] ?? null) !== null ? $prev : $this->blankPage($page);
        }
        $data = ['filters' => $v['filters'], 'flow' => $v['flow'], 'cap' => $v['cap'], 'state' => self::DRAFT, 'hash' => $hash, 'pages' => $pages, 'previewed_at' => now()->format('Y-m-d H:i:s')];
        if (!$b) {
            $b = ChatblastBroadcast::create(['kind' => 'broadcast-' . Str::lower(Str::random(16)), 'name' => $v['name'], 'pages' => $v['pages'], 'days' => [[$v['text']]], 'broadcast' => $data + ['created_by' => $userId]]);
            $b->forceFill(['kind' => 'broadcast-' . $b->id])->save();
        } else {
            $b->forceFill(['name' => $v['name'], 'pages' => $v['pages'], 'days' => [[$v['text']]], 'broadcast' => $data + array_intersect_key((array) $b->broadcast, ['created_by' => 1])])->save();
        }

        return $this->summary($b->fresh());
    }

    private function blankPage(string $page): array
    {
        return ['page_id' => trim((string) $this->settings->get($page, 'botcake', 'page_id')), 'count' => null, 'flow_id' => null, 'flow_name' => null,
            'error' => null, 'paused' => null, 'hash' => null];
    }

    /**
     * The count of ONE page of a draft (the only place that counts), with its flow (or why it is left out), cached on
     * the broadcast row with the filters hash. $fresh: count again even when cached. Returns the summary and the page.
     */
    public function countPage(int $id, string $page, bool $fresh = false): array|string
    {
        $b = ChatblastBroadcast::find($id);
        if (!$b) return 'No such broadcast.';
        if ($b->state() !== self::DRAFT) return 'This broadcast has started; it cannot be counted again.';
        $d = (array) $b->broadcast;
        [$name, $p] = $this->pageEntry($d, $page);
        if ($name === null) return 'This page is not in the broadcast.';
        $hash = (string) ($d['hash'] ?? '');
        if (!$fresh && ($p['count'] ?? null) !== null && ($p['hash'] ?? null) === $hash) return $this->summary($b) + ['page' => $name];

        $f = $this->resolveFlow($name, $d['flow'] ?? null);
        $entry = array_merge($this->blankPage($name), [
            'count' => $this->count($name, (array) ($d['filters'] ?? [])),
            'flow_id' => $f['id'], 'flow_name' => $f['name'], 'error' => $f['error'] ?? app(ChatblastReady::class)->reason($name), 'hash' => $hash,
        ]);
        $this->mutate($id, function (array $x) use ($name, $hash, $entry) {
            if (($x['state'] ?? null) === self::DRAFT && ($x['hash'] ?? null) === $hash && isset($x['pages'][$name])) $x['pages'][$name] = $entry;
            return $x;
        });

        return $this->summary(ChatblastBroadcast::findOrFail($id)) + ['page' => $name];
    }

    /** [stored page name, entry] of a page of the broadcast by page key, or [null, null]. */
    private function pageEntry(array $d, string $page): array
    {
        $key = $this->settings->pageKey($page);
        foreach ((array) ($d['pages'] ?? []) as $name => $p) {
            if ($this->settings->pageKey((string) $name) === $key) return [(string) $name, (array) $p];
        }

        return [null, null];
    }

    /** The broadcast for the page: definition, per-page cached count and total, state. */
    public function summary(ChatblastBroadcast $b): array
    {
        $d = (array) $b->broadcast;
        $pages = [];
        $total = 0;
        $counted = true;
        foreach ((array) ($d['pages'] ?? []) as $page => $p) {
            $count = $p['count'] ?? null;
            $pages[] = ['page' => $page, 'count' => $count === null ? null : (int) $count, 'flow_id' => $p['flow_id'], 'flow_name' => $p['flow_name'],
                'error' => $p['error'], 'paused' => $p['paused'] ?? null, 'audience' => $p['audience'] ?? null];
            if ($count === null) $counted = false;
            elseif ($p['error'] === null) $total += (int) $count;
        }

        return [
            'id' => $b->id, 'name' => $b->name, 'text' => $b->text(), 'state' => $b->state(), 'filters' => $d['filters'] ?? [], 'flow' => $d['flow'] ?? null,
            'cap' => (int) ($d['cap'] ?? self::DEFAULT_CAP), 'pages' => $pages, 'total' => $total, 'counted' => $counted,
            'warning' => self::noFilter((array) ($d['filters'] ?? [])) ? self::ALL_WARNING : null,
            'queued_all' => (bool) ($d['queued_all'] ?? false), 'started_at' => $d['started_at'] ?? null, 'stopped_at' => $d['stopped_at'] ?? null,
        ];
    }

    /**
     * Start: no count in the request. Every chosen page must have a cached count for the current filters hash, and the
     * total the owner confirmed must be the total of those counts. Draft → sending in one locked step, so a second start
     * does nothing. QueueBroadcast inserts what matches at queue time (progress shows it next to the previewed count).
     * @return array|string the summary, or the error
     */
    public function start(int $id, ?int $confirmTotal, ?int $userId): array|string
    {
        $b = ChatblastBroadcast::find($id);
        if (!$b) return 'No such broadcast.';
        if ($b->state() !== self::DRAFT) return 'This broadcast has already started.';
        $d = (array) $b->broadcast;
        $v = $this->validate(['pages' => $b->pages, 'text' => $b->text(), 'flow' => $d['flow'] ?? null, 'cap' => $d['cap'] ?? self::DEFAULT_CAP, 'name' => $b->name] + (array) ($d['filters'] ?? []), true);
        if (is_string($v)) return $v;
        $hash = self::hash($v['filters'], $v['flow']);
        if (($d['hash'] ?? null) !== $hash) return 'The filters changed since the count; preview again.';

        $total = 0;
        foreach ($v['pages'] as $page) {
            [, $p] = $this->pageEntry($d, $page);
            if ($p === null || ($p['count'] ?? null) === null || ($p['hash'] ?? null) !== $hash) return 'Count every page first (missing: ' . $page . ').';
            if ($p['error'] === null) $total += (int) $p['count'];
        }
        if ($total === 0) return 'Nothing to send: no PSID on the pages that can be sent.';
        if ($confirmTotal === null || $confirmTotal !== $total) return 'The total is ' . number_format($total) . ' now; check the preview and press Send again.';

        $started = false;
        $this->mutate($id, function (array $d) use ($userId, &$started) {
            if (($d['state'] ?? self::DRAFT) !== self::DRAFT) return $d;
            $d['pages'] = array_map(fn (array $p) => $p + ['audience' => $p['count']], (array) $d['pages']);
            $started = true;
            return array_merge($d, ['state' => self::SENDING, 'cursor' => ['i' => 0, 'branch' => 0, 'psid' => ''], 'queued_all' => false,
                'started_at' => now()->format('Y-m-d H:i:s'), 'started_by' => $userId]);
        });
        if (!$started) return 'This broadcast has already started.';
        QueueBroadcast::start($id);

        return $this->summary(ChatblastBroadcast::findOrFail($id));
    }

    /**
     * Read-modify-write of the JSON under a row lock (owner actions and sender hooks change it at the same time).
     * $change gets and returns the JSON; returns the new JSON, or null when the broadcast is gone.
     */
    public function mutate(int $id, callable $change): ?array
    {
        return DB::transaction(function () use ($id, $change) {
            $b = ChatblastBroadcast::whereKey($id)->lockForUpdate()->first();
            if (!$b) return null;
            $d = $change((array) $b->broadcast);
            $b->forceFill(['broadcast' => $d])->save();

            return $d;
        }, 3);
    }

    // ---- Queueing (QueueBroadcast) ----

    /**
     * One queueing step: up to BATCH rows of the current page and branch (keyset on the index order: psid), inserted as
     * waiting (insertOrIgnore on the dedupe key: a re-run or a duplicate PSID never makes a second row). On a stopped
     * broadcast, one cancel step instead. @return bool true when there is more to do
     */
    public function queueStep(int $id): bool
    {
        $b = ChatblastBroadcast::find($id);
        if (!$b) return false;
        $d = (array) $b->broadcast;
        if ($b->state() === self::STOPPED) return $this->cancelStep($id);
        if (!in_array($b->state(), [self::SENDING, self::PAUSED], true) || !empty($d['queued_all'])) return false;

        $pages = array_keys(array_filter((array) ($d['pages'] ?? []), fn ($p) => $p['error'] === null));
        $cur = (array) ($d['cursor'] ?? []) + ['i' => 0, 'branch' => 0, 'psid' => ''];
        if ($cur['i'] >= count($pages)) {
            $this->mutate($id, fn (array $x) => array_merge($x, ['queued_all' => true]));
            return false;
        }
        $page = $pages[$cur['i']];
        $p = $d['pages'][$page];
        $branches = $this->branches($page, (array) $d['filters']);
        $rows = ($branches[$cur['branch']] ?? null)?->where('b.psid', '>', (string) $cur['psid'])->orderBy('b.psid')->limit(self::BATCH)->get(['b.psid', 'b.fb_name']) ?? collect();

        $now = now()->format('Y-m-d H:i:s');
        $vars = json_encode(['flow_id' => (string) $p['flow_id'], 'flow_name' => (string) $p['flow_name']], JSON_UNESCAPED_UNICODE);
        $insert = [];
        foreach ($rows as $r) {
            $psid = trim((string) $r->psid);
            if ($psid === '') continue;
            $insert[] = ['page_name' => $page, 'waybill' => self::WAYBILL, 'kind' => self::KIND, 'campaign_id' => $id, 'day' => 1,
                'fb_name' => mb_substr((string) $r->fb_name, 0, 255), 'psid' => $psid, 'vars' => $vars, 'text' => $b->text(), 'status' => self::WAITING,
                'attempts' => 0, 'is_test' => false, 'dedupe_key' => self::dedupeKey($id, (string) $p['page_id'], $psid),
                'created_by' => $d['started_by'] ?? null, 'created_at' => $now, 'updated_at' => $now];
        }
        if ($insert) DB::table('botcake_messages')->insertOrIgnore($insert);

        $next = $rows->count() < self::BATCH
            ? ($cur['branch'] + 1 < count($branches) ? ['i' => $cur['i'], 'branch' => $cur['branch'] + 1, 'psid' => ''] : ['i' => $cur['i'] + 1, 'branch' => 0, 'psid' => ''])
            : ['i' => $cur['i'], 'branch' => $cur['branch'], 'psid' => (string) $rows->last()->psid];
        $this->mutate($id, fn (array $x) => array_merge($x, ['cursor' => $next]));

        return true;
    }

    public static function dedupeKey(int $id, string $pageId, string $psid): string
    {
        return 'broadcast:' . $id . ':' . $pageId . ':' . $psid;
    }

    /** One cancel step of a stopped broadcast: up to CANCEL_BATCH waiting or queued rows become cancelled. */
    private function cancelStep(int $id): bool
    {
        $ids = $this->rows($id)->whereIn('status', [self::WAITING, 'queued'])->orderBy('id')->limit(self::CANCEL_BATCH)->pluck('id')->all();
        if (!$ids) return false;
        BotcakeMessage::whereIn('id', $ids)->whereIn('status', [self::WAITING, 'queued'])->update(['status' => self::CANCELLED, 'reason' => 'Stopped', 'updated_at' => now()]);

        return count($ids) === self::CANCEL_BATCH;
    }

    private function rows(int $id)
    {
        return BotcakeMessage::where('campaign_id', $id)->where('kind', self::KIND)->where('is_test', false);
    }

    // ---- Feeding the sender ----

    /**
     * Hand waiting rows to the sender: per sending broadcast and page that is not paused, up to
     * min(cap − handed out in the last hour, IN_FLIGHT − in flight), each as a normal SendChatblastMessage job. Nothing
     * while the chatblast is paused. One feeder at a time. @return int rows handed out
     */
    public function feed(): int
    {
        if (!self::migrated() || app(ChatblastSender::class)->paused()) return 0;
        $lock = Cache::lock('chatblast-broadcast-feed', 120);
        if (!$lock->get()) return 0;
        $n = 0;
        try {
            foreach (ChatblastBroadcast::orderBy('id')->get(['id', 'broadcast']) as $b) {
                if ($b->state() !== self::SENDING) continue;
                $d = (array) $b->broadcast;
                foreach ((array) ($d['pages'] ?? []) as $page => $p) {
                    if ($p['error'] !== null || ($p['paused'] ?? null) !== null) continue;
                    $n += $this->feedPage($b->id, (string) $page, (int) ($d['cap'] ?? self::DEFAULT_CAP), $d['started_by'] ?? null);
                }
                $this->finishIfDone($b->id);
            }
        } finally {
            $lock->release();
        }

        return $n;
    }

    /** Broadcast rows per page in the sender at once: services.botcake.broadcast_in_flight (BROADCAST_IN_FLIGHT), 1 to 200. */
    public static function inFlight(): int
    {
        $v = config('services.botcake.broadcast_in_flight');

        return is_numeric($v) && (int) $v > 0 ? min(200, (int) $v) : self::IN_FLIGHT;
    }

    private function feedPage(int $id, string $page, int $cap, ?int $userId): int
    {
        $q = fn () => $this->rows($id)->where('page_name', $page);
        $inFlight = $q()->whereIn('status', ['queued', 'sending'])->count();
        $handed = $q()->whereIn('status', self::HANDED)->where('queued_at', '>=', now()->subHour())->count();
        $room = min($cap - $handed, self::inFlight() - $inFlight);
        if ($room <= 0) return 0;

        $n = 0;
        foreach ($q()->where('status', self::WAITING)->limit($room)->pluck('id') as $rowId) {
            if (!BotcakeMessage::whereKey($rowId)->where('status', self::WAITING)->update(['status' => 'queued', 'queued_at' => now(), 'sent_by' => $userId])) continue;
            SendChatblastMessage::dispatch((int) $rowId);
            $n++;
        }

        return $n;
    }

    /** All rows queued and none left to send: done. */
    private function finishIfDone(int $id): void
    {
        $b = ChatblastBroadcast::find($id);
        if (!$b || $b->state() !== self::SENDING || empty($b->broadcast['queued_all'])) return;
        if ($this->rows($id)->whereIn('status', [self::WAITING, 'queued', 'sending'])->exists()) return;
        $this->mutate($id, fn (array $d) => ($d['state'] ?? null) === self::SENDING ? array_merge($d, ['state' => self::DONE, 'done_at' => now()->format('Y-m-d H:i:s')]) : $d);
    }

    // ---- Owner controls ----

    public function pause(int $id): array|string
    {
        $d = $this->mutate($id, fn (array $d) => ($d['state'] ?? null) === self::SENDING ? array_merge($d, ['state' => self::PAUSED]) : $d);
        if ($d === null) return 'No such broadcast.';

        return ($d['state'] ?? null) === self::PAUSED ? $this->summary(ChatblastBroadcast::findOrFail($id)) : 'Only a sending broadcast can be paused.';
    }

    /** Resume the broadcast and every paused page of it; the rows that waited as queued get their job again. */
    public function resume(int $id): array|string
    {
        $d = $this->mutate($id, function (array $d) {
            if (!in_array($d['state'] ?? null, [self::SENDING, self::PAUSED], true)) return $d;
            foreach ((array) ($d['pages'] ?? []) as $page => $p) $d['pages'][$page]['paused'] = null;
            $d['state'] = self::SENDING;
            $d['resumed'] = true;
            return $d;
        });
        if ($d === null) return 'No such broadcast.';
        if (empty($d['resumed'])) return 'Only a sending or paused broadcast can be resumed.';
        $this->mutate($id, function (array $x) { unset($x['resumed']); return $x; });
        foreach (array_keys((array) $d['pages']) as $page) Cache::forget(self::streakKey($id, (string) $page));

        $this->rows($id)->where('status', 'queued')->select('id')->chunkById(500, function ($rows) {
            foreach ($rows as $r) SendChatblastMessage::dispatch((int) $r->id);
        });
        if (empty($d['queued_all'])) QueueBroadcast::start($id);
        $this->feed();

        return $this->summary(ChatblastBroadcast::findOrFail($id));
    }

    /** Stop: nothing more is sent; the queued rows are cancelled now and the waiting ones by the queue job. */
    public function stop(int $id): array|string
    {
        $d = $this->mutate($id, fn (array $d) => in_array($d['state'] ?? null, [self::SENDING, self::PAUSED], true)
            ? array_merge($d, ['state' => self::STOPPED, 'stopped_at' => now()->format('Y-m-d H:i:s')]) : $d);
        if ($d === null) return 'No such broadcast.';
        if (($d['state'] ?? null) !== self::STOPPED) return 'Only a sending or paused broadcast can be stopped.';
        $this->rows($id)->where('status', 'queued')->update(['status' => self::CANCELLED, 'reason' => 'Stopped', 'updated_at' => now()]);
        QueueBroadcast::start($id);

        return $this->summary(ChatblastBroadcast::findOrFail($id));
    }

    public function setCap(int $id, int $cap): array|string
    {
        if ($cap < 1 || $cap > self::MAX_CAP) return 'Cap: 1 to ' . self::MAX_CAP . ' per page per hour.';
        $d = $this->mutate($id, fn (array $d) => array_merge($d, ['cap' => $cap]));

        return $d === null ? 'No such broadcast.' : $this->summary(ChatblastBroadcast::findOrFail($id));
    }

    // ---- Test send (S3) ----

    /** The text with the page's flow to the page's Test PSID (botcake.test_psid), as a test row. */
    public function test(int $id, string $page, ?int $userId): BotcakeMessage|string
    {
        $b = ChatblastBroadcast::find($id);
        if (!$b) return 'No such broadcast.';
        $key = $this->settings->pageKey($page);
        $p = null;
        foreach ((array) ($b->broadcast['pages'] ?? []) as $name => $row) {
            if ($this->settings->pageKey((string) $name) === $key) [$page, $p] = [(string) $name, $row];
        }
        if ($p === null) return 'This page is not in the broadcast.';
        if (trim($b->text()) === '') return 'Write the text first.';
        if (($p['count'] ?? null) === null) return 'Count this page first (Preview count).';
        if ($p['flow_id'] === null || $p['flow_id'] === '') return $p['error'] ?? 'This page has no flow.';
        $psid = trim((string) $this->settings->get($page, 'botcake', 'test_psid'));
        if ($psid === '') return 'This page has no Test PSID. Pick one under "Test PSID" on /chatblast first.';

        $test = BotcakeMessage::create([
            'page_name' => $page, 'waybill' => 'TEST', 'kind' => self::KIND, 'campaign_id' => $b->id, 'psid' => $psid, 'text' => $b->text(),
            'vars' => ['flow_id' => (string) $p['flow_id'], 'flow_name' => (string) $p['flow_name']],
            'status' => 'queued', 'is_test' => true, 'queued_at' => now(), 'created_by' => $userId, 'sent_by' => $userId,
        ]);
        SendChatblastMessage::dispatch($test->id);

        return $test->fresh();
    }

    // ---- Progress ----

    public function progress(int $id): ?array
    {
        $b = ChatblastBroadcast::find($id);
        if (!$b) return null;
        $counts = $this->rows($id)->selectRaw('page_name, status, COUNT(*) AS n, SUM(CASE WHEN reason = ? THEN 1 ELSE 0 END) AS unreachable', [self::UNREACHABLE])
            ->groupBy('page_name', 'status')->get();
        $by = [];
        $unreachable = [];
        foreach ($counts as $c) {
            $key = mb_strtolower((string) $c->page_name);
            $by[$key][$c->status] = (int) $c->n;
            if ($c->status === 'failed') $unreachable[$key] = (int) $c->unreachable;
        }
        $sum = fn (array $s, array $keys) => array_sum(array_intersect_key($s, array_flip($keys)));

        $pages = [];
        foreach ((array) ($b->broadcast['pages'] ?? []) as $page => $p) {
            $s = $by[mb_strtolower((string) $page)] ?? [];
            $gone = $unreachable[mb_strtolower((string) $page)] ?? 0;
            $pages[] = [
                'page' => $page, 'flow_name' => $p['flow_name'], 'error' => $p['error'], 'paused' => $p['paused'] ?? null,
                // audience: the previewed count; matched: the rows the queue job inserted (what matched at queue time).
                'audience' => $p['audience'] ?? $p['count'], 'matched' => array_sum($s), 'rows' => array_sum($s),
                'queued' => $sum($s, [self::WAITING, 'queued', 'sending']), 'waiting' => $s[self::WAITING] ?? 0,
                'in_flight' => $sum($s, ['queued', 'sending']), 'sent' => $s['sent'] ?? 0, 'unreachable' => $gone,
                'failed' => $sum($s, ['failed', 'field_mismatch']) - $gone, 'unknown' => $s['unknown'] ?? 0,
                'skipped' => $sum($s, ['skipped', 'no_config']), 'cancelled' => $s[self::CANCELLED] ?? 0,
            ];
        }
        $tests = BotcakeMessage::where('campaign_id', $id)->where('kind', self::KIND)->where('is_test', true)->orderByDesc('id')->limit(10)
            ->get(['id', 'page_name', 'status', 'reason', 'last_http', 'steps', 'created_at'])
            ->map(fn ($t) => ['id' => $t->id, 'page' => $t->page_name, 'status' => $t->status, 'reason' => $t->reason, 'http' => $t->last_http,
                'steps' => array_map(fn ($s) => ['step' => $s['step'] ?? '', 'http' => $s['http'] ?? null], (array) ($t->steps ?? [])),
                'at' => $t->created_at?->timezone('Asia/Manila')->format('m-d H:i')])->all();

        return $this->summary($b) + ['progress' => $pages, 'tests' => $tests, 'chatblast_paused' => app(ChatblastSender::class)->paused()];
    }

    // ---- Sender hooks (ChatblastSender) ----

    /**
     * Before a broadcast row is sent (job or pool): null = send; ['wait', reason] = leave it queued (paused broadcast or
     * page, or the page is not Ready: the page is paused); [status, reason] = finish it without sending.
     */
    public function hold(BotcakeMessage $m): ?array
    {
        if ($m->is_test) return null;
        $b = ChatblastBroadcast::find($m->campaign_id);
        if (!$b) return ['skipped', 'The broadcast no longer exists'];
        if (in_array($b->state(), [self::STOPPED, self::DONE], true)) return [self::CANCELLED, 'Stopped'];
        if ($b->state() !== self::SENDING) return ['wait', 'Paused'];
        $p = $this->pageOf($b, (string) $m->page_name);
        if ($p === null || $p['error'] !== null) return ['skipped', 'The page is not in the broadcast'];
        if (($p['paused'] ?? null) !== null) return ['wait', $p['paused']];
        if (($why = app(ChatblastReady::class)->reason($m->page_name)) !== null) {
            $this->pausePage((int) $b->id, (string) $m->page_name, $why);
            return ['wait', $why];
        }

        return null;
    }

    private function pageOf(ChatblastBroadcast $b, string $page): ?array
    {
        $key = $this->settings->pageKey($page);
        foreach ((array) ($b->broadcast['pages'] ?? []) as $name => $p) {
            if ($this->settings->pageKey((string) $name) === $key) return $p;
        }

        return null;
    }

    /** A broadcast row finished: the fail streak of its page, and the pause on 20 in a row or a restricted page. */
    public function finished(BotcakeMessage $m, string $status, array $steps): void
    {
        if ($m->is_test || $m->campaign_id === null) return;
        $key = self::streakKey((int) $m->campaign_id, (string) $m->page_name);
        if ($status === 'sent') {
            Cache::forget($key);
            return;
        }
        if (!in_array($status, ['failed', 'field_mismatch'], true)) return;

        $said = implode("\n", array_map(fn ($s) => is_array($s) ? (string) ($s['response'] ?? '') : '', $steps));
        if ($status === 'failed' && preg_match(self::UNREACHABLE_SAID, $said)) {
            // The customer, not the page: counted on its own and never part of the streak that pauses the page.
            BotcakeMessage::whereKey($m->id)->update(['reason' => self::UNREACHABLE]);
            return;
        }
        if (preg_match(self::RESTRICTED, $said)) {
            $this->pausePage((int) $m->campaign_id, (string) $m->page_name, 'The page is restricted or blocked from messaging: ' . mb_substr(trim($said), 0, 150));
            return;
        }
        Cache::add($key, 0, 86400);
        if (Cache::increment($key) >= self::FAIL_STREAK) {
            $this->pausePage((int) $m->campaign_id, (string) $m->page_name, self::STREAK_REASON . ' (last: ' . mb_substr((string) $m->reason, 0, 120) . ')');
        }
    }

    public static function streakKey(int $id, string $page): string
    {
        return 'chatblast-broadcast-fails:' . $id . ':' . sha1(mb_strtolower(preg_replace('/\s+/u', ' ', trim($page)), 'UTF-8'));
    }

    /** Pause one page of a broadcast with the reason (the first reason stays). */
    public function pausePage(int $id, string $page, string $reason): void
    {
        $key = $this->settings->pageKey($page);
        $this->mutate($id, function (array $d) use ($key, $reason) {
            foreach ((array) ($d['pages'] ?? []) as $name => $p) {
                if ($this->settings->pageKey((string) $name) === $key && ($p['paused'] ?? null) === null) $d['pages'][$name]['paused'] = mb_substr($reason, 0, 250);
            }
            return $d;
        });
    }

    /** The token of the page was refused: the page pauses in every broadcast that is sending or paused. */
    public function pausePageEverywhere(string $page, string $reason): void
    {
        if (!self::migrated()) return;
        foreach (ChatblastBroadcast::orderBy('id')->get(['id', 'broadcast']) as $b) {
            if (in_array($b->state(), [self::SENDING, self::PAUSED], true) && $this->pageOf($b, $page) !== null) $this->pausePage((int) $b->id, $page, $reason);
        }
    }

    /** The flow a broadcast row sends (its own, never the page's flow.shipping). */
    public static function flowId(BotcakeMessage $m): int
    {
        return (int) (((array) ($m->vars ?? []))['flow_id'] ?? 0);
    }
}
