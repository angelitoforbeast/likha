<?php

namespace App\Services\Chatbot;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Models\ChatbotSequence;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang bahagi ng site na ito sa paglipat ng page sa pagitan ng dalawang partner site (ang Forbeast ang nag-uutos, pinirmahan):
 * - adopt: ang page token at ang koneksyon (ForbeastPageSetup::connect), gaya ng Connections popup; ang take ay Off ang bot;
 * - export / import: ang values ng page (ang flow ayon sa pangalan), ang customer fields (walang "__" run state) at ang
 *   mga sequence subscription (ayon sa pangalan ng sequence), hanggang 500 kada tawag. Ang import ay idempotent at lahat o wala;
 *   ang subscription ay "held": status paused at exit_reason "transfer <id>" (ang subscribe at exit on reply ay itinuturing\n *   itong naka-enroll; ang tick at resumePaused ay hindi ito kinukuha): walang padala hangga't walang activate ng paglipat na iyon;
 * - hold (sa source, bago kopyahin ang sequences): ang mga active na subscription ng page ay held din (paused, exit_reason
 *   "hold <id>"), at ang bagong subscribe habang held ang page (transfer.hold) ay held agad; ang hold:false ay ibinabalik
 *   sila gaya ng dati (parehong step at oras ng padala), kaya walang follow-up na naipapadala ng dalawang site;
 * - catch-up (pagkatapos ng switch, bago ang release): sequences?since=<held_at> ang dala ang binago mula sa hold, bawat isa
 *   "active" o "ended" (tapos sa source, may exit_reason); sa target, ang ended ay tinatapos ang held, at ang tinapos na sa
 *   target pagkatapos ng switch (transfer.adopted) ay hindi binubuksan ulit;
 * - release: ang lumang site ay tumitigil (bot Off, mga subscription tapos na "transferred" pero dala pa ang step at oras,
 *   ang mga wait at naghihintay na AI o padala ay kinakalimutan, ang Forbeast connection ay inactive na may history, wala nang hold);
 * - activate: ang mga held ng paglipat na ito lang ay active ulit, sa parehong step at oras ng padala.
 * Ang nasa gitna ng flow (wait, __wait, AI) ay hindi kinokopya: sa Main Flow sila magsisimula sa susunod nilang mensahe.
 * Walang value, token o secret sa log (ang PageSettings ay key lang ang itinatala).
 */
class PageTransfers
{
    public const MAX_ITEMS = 500;
    public const PARTS = ['values', 'fields', 'sequences'];
    /** Ang held na subscription: naka-pause na may exit_reason "transfer <id>" (mga dating column at status). */
    public const HELD = 'paused';
    public const HELD_REASON = 'transfer ';
    /** Ang held sa source habang kinokopya (ang hold): exit_reason "hold <id>"; ang page ay may transfer.hold = <id>. */
    public const HOLD_REASON = 'hold ';
    public const HOLD_PROVIDER = 'transfer';
    public const HOLD_KEY = 'hold';
    /** Ang paglipat na huling na-adopt dito: transfer.adopted = <id> (ang created_at ang oras ng switch). */
    public const ADOPTED_KEY = 'adopted';
    /** Ang item ng sequences: tumatakbo pa (active, pati held) o tapos na sa source mula sa hold (ended, may exit_reason). */
    private const STATUSES = ['active', 'ended'];
    /** Ang pagtatapos ng release o ng nabigong paglipat dito: hindi exit ng customer, kaya hindi kailanman ended. */
    private const MOVE_ENDINGS = ['transferred', 'transfer not activated'];
    /** Ang mga item ng values na hindi botfield: ang pangalan ng page, ang On/Off at ang Main Flow (ayon sa pangalan). */
    private const META_KEYS = ['name', 'active', 'flow'];
    private const VIAS = ['flow', 'inbox', 'other'];
    private const ISO = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})?$/';

    public function __construct(private PageSettings $settings, private PageValues $values, private ForbeastPageSetup $forbeast)
    {
    }

    /** Ang pangalan ng page (gaya ng naitala) ng Meta page ID, o null. */
    public function page(string $metaPageId): ?string
    {
        return BotcakeChat::pageMap()[$metaPageId] ?? null;
    }

    /** Ang pangalan ay gamit na ng ibang Meta page ID dito. */
    private function clash(string $name, string $metaPageId): bool
    {
        $owner = trim((string) $this->settings->get($name, 'botcake', 'page_id'));

        return $owner !== '' && $owner !== $metaPageId;
    }

    // ---- adopt ----

    /** @return array{http: int, body: array} */
    public function adopt(string $metaPageId, string $name, string $token, string $mode, string $transferId, ?string $actor): array
    {
        $page = $this->page($metaPageId);
        if ($page === null) {
            if ($this->clash($name, $metaPageId)) return self::error(409, 'name_taken', 'Another page here already uses this name.');
            $page = ForbeastSite::squash($name);
        }
        $source = $mode === 'take' ? 'taken by ' . ($actor ?? 'Forbeast (transfer ' . $transferId . ')') : $this->from($transferId);

        DB::transaction(function () use ($page, $metaPageId, $token, $mode, $source, $transferId) {
            $this->settings->set($page, 'botcake', 'page_id', $metaPageId, false, null, true, $source);
            $this->settings->set($page, ChatbotFields::PROVIDER, 'provider', ChatbotProvider::GATEWAY, false, null, true, $source);
            $this->settings->set($page, ForbeastPageSetup::PROVIDER, 'token', $token, true, null, true, $source);
            // Ang take: Off hanggang ayusin at i-On ng CEO. Ang transfer: ang On/Off na kinopya sa values.
            if ($mode === 'take') $this->settings->set($page, ChatbotFields::PROVIDER, 'active', '0', false, null, true, $source);
            // Ang switch ng paglipat na ito (kailan): ang row na tinapos dito pagkatapos nito ay hindi binubuksan ulit ng catch-up.
            $this->settings->set($page, self::HOLD_PROVIDER, self::ADOPTED_KEY, $transferId, false, null, true, $source);
        });
        $res = $this->forbeast->connect($page);
        if ($res['status'] !== 'ok') return self::error(502, 'connect_failed', 'The page was saved but not connected: ' . ($res['error'] ?? $res['status']));

        return ['http' => 200, 'body' => ['ok' => true, 'page' => $page, 'bot' => $this->settings->get($page, ChatbotFields::PROVIDER, 'active') === '0' ? 'off' : 'on']];
    }

    /** Ang source ng sulat ng paglipat: "transfer <id> from <site>". */
    private function from(string $transferId): string
    {
        return 'transfer ' . $transferId . ' from ' . ForbeastSite::other(ForbeastSite::self());
    }

    // ---- export ----

    /** @return array{http: int, body: array} */
    public function export(string $page, string $metaPageId, string $part, int $cursor, ?string $since): array
    {
        return match ($part) {
            'values' => ['http' => 200, 'body' => ['items' => $this->exportValues($page), 'next' => null]],
            'fields' => $this->exportFields((int) $metaPageId, $cursor, $since),
            default => $this->exportSequences((int) $metaPageId, $cursor, $since),
        };
    }

    /** @return array<int, array{key: string, value: ?string}> */
    private function exportValues(string $page): array
    {
        $cb = $this->settings->all($page, ChatbotFields::PROVIDER);
        $flowId = trim((string) ($cb['flow_id'] ?? ''));
        $flow = ctype_digit($flowId) && Schema::hasTable('chatbot_flows') ? ChatbotFlow::whereKey((int) $flowId)->value('name') : null;
        $items = [['key' => 'name', 'value' => $page], ['key' => 'active', 'value' => ($cb['active'] ?? null) === '0' ? '0' : '1'], ['key' => 'flow', 'value' => $flow]];
        foreach (array_keys($this->values->editable()) as $key) {
            $v = $cb[$key] ?? null;
            $items[] = ['key' => (string) $key, 'value' => $v === null || $v === '' ? null : $v];
        }

        return $items;
    }

    /** @return array{http: int, body: array} */
    private function exportFields(int $pageId, int $cursor, ?string $since): array
    {
        $rows = DB::table('chatbot_customer_values')->where('page_id', $pageId)->where('psid', '>', 0)->where('id', '>', $cursor)
            ->when($since !== null, fn ($q) => $q->where('updated_at', '>', $since))
            ->orderBy('id')->limit(self::MAX_ITEMS)->get(['id', 'psid', 'key', 'value', 'updated_at']);
        $items = [];
        foreach ($rows as $r) {
            // "__" = run state (wait, AI, huling chat): hindi kinokopya. Sa PHP sinasala (ang "_" ay wildcard ng LIKE).
            if (str_starts_with((string) $r->key, '__')) continue;
            $items[] = ['psid' => (int) $r->psid, 'key' => (string) $r->key, 'value' => $r->value, 'updated_at' => self::iso($r->updated_at)];
        }

        return ['http' => 200, 'body' => ['items' => $items, 'next' => $rows->count() === self::MAX_ITEMS ? (string) $rows->last()->id : null]];
    }

    /** @return array{http: int, body: array} */
    private function exportSequences(int $pageId, int $cursor, ?string $since): array
    {
        // Pati ang naka-pause dahil Off ang page o dahil sa hold (babalik sa bagong site), pero hindi ang held pa ng isang
        // paglipat papunta rito. Ang catch-up (since = held_at) ay pagkatapos ng switch at bago ang release: ang tinapos sa
        // source mula sa hold (sumagot, inalis, natapos) ay "ended" kasama ang dahilan; ang tinapos ng release o ng nabigong
        // paglipat dito ay hindi kailanman ended (ang transferred, kung tatanungin pa pagkatapos ng release, ay active gaya ng dati).
        // ">=": ang ginawa sa mismong segundo ng hold ay hindi naiiwan (ang import ay idempotent).
        $rows = DB::table('chatbot_sequence_subscriptions as s')->join('chatbot_sequences as q', 'q.id', '=', 's.sequence_id')
            ->where('s.page_id', $pageId)->where('s.id', '>', $cursor)
            ->when($since !== null, fn ($q) => $q->where('s.updated_at', '>=', $since))
            ->where(fn ($q) => $q->where('s.status', 'active')->orWhere(fn ($w) => $w->where('s.status', 'paused')
                ->where(fn ($x) => $x->whereNull('s.exit_reason')->orWhere('s.exit_reason', 'not like', self::HELD_REASON . '%')))
                ->when($since !== null, fn ($w) => $w->orWhere(fn ($x) => $x->where('s.status', 'unsubscribed')->where('s.exit_reason', 'transferred'))
                    ->orWhere(fn ($x) => $x->whereIn('s.status', ['unsubscribed', 'completed', 'error'])
                        ->where(fn ($y) => $y->whereNull('s.exit_reason')->orWhereNotIn('s.exit_reason', self::MOVE_ENDINGS)))))
            ->orderBy('s.id')->limit(self::MAX_ITEMS)->get(['s.id', 's.psid', 'q.name', 's.current_step', 's.next_send_at', 's.enrolled_via', 's.status', 's.exit_reason']);
        $last = $this->lastMessages($pageId, $rows->pluck('psid')->map(fn ($p) => (int) $p)->unique()->values()->all());
        $items = $rows->map(fn ($r) => ['psid' => (int) $r->psid, 'sequence' => (string) $r->name, 'current_step' => (int) $r->current_step,
            'next_send_at' => self::iso($r->next_send_at), 'enrolled_via' => (string) $r->enrolled_via, 'last_message_at' => self::iso($last[(int) $r->psid] ?? null)]
            + (in_array($r->status, ['active', 'paused'], true) || $r->exit_reason === 'transferred' ? ['status' => 'active'] : ['status' => 'ended', 'exit_reason' => (string) ($r->exit_reason ?? $r->status)]))->all();

        return ['http' => 200, 'body' => ['items' => $items, 'next' => $rows->count() === self::MAX_ITEMS ? (string) $rows->last()->id : null]];
    }

    /**
     * Ang huling mensahe ng bawat customer, gaya ng sa 24 oras ng sequence (ChatbotSequences::runStep): ang pinakabagong
     * chat dito o ang dala ng naunang paglipat (__last_message_at), alin ang mas bago.
     * @param array<int, int> $psids
     * @return array<int, string> psid => Y-m-d H:i:s
     */
    private function lastMessages(int $pageId, array $psids): array
    {
        if (!$psids) return [];
        $last = [];
        $later = function (int $psid, ?string $at) use (&$last) {
            if ($at === null || $at === '') return;
            $at = Carbon::parse($at, config('app.timezone'))->format('Y-m-d H:i:s');
            if (!isset($last[$psid]) || $at > $last[$psid]) $last[$psid] = $at;
        };
        foreach (array_chunk($psids, 200) as $chunk) {
            BotcakeChat::active()->where('page_id', (string) $pageId)->whereIn('psid', array_map('strval', $chunk))->groupBy('psid')
                ->selectRaw('psid, max(received_at) as at')->get()->each(fn ($r) => $later((int) $r->psid, $r->getRawOriginal('at')));
            DB::table('chatbot_customer_values')->where('page_id', $pageId)->whereIn('psid', $chunk)->where('key', CustomerFields::LAST_MESSAGE_AT)
                ->get(['psid', 'value'])->each(fn ($r) => $later((int) $r->psid, preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $r->value) ? $r->value : null));
        }

        return $last;
    }

    private static function iso(?string $at): ?string
    {
        return $at === null || $at === '' ? null : Carbon::parse($at, config('app.timezone'))->toIso8601String();
    }

    /** Ang ISO na oras bilang oras ng app (Y-m-d H:i:s), o null kapag hindi ISO. */
    public static function localTime(mixed $iso): ?string
    {
        if (!is_string($iso) || !preg_match(self::ISO, $iso)) return null;
        try {
            return Carbon::parse($iso)->timezone(config('app.timezone'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    // ---- import ----

    /**
     * @param array<int, mixed> $items
     * @return array{http: int, body: array}
     */
    public function import(string $metaPageId, string $part, array $items, string $transferId): array
    {
        return match ($part) {
            'values' => $this->importValues($metaPageId, $items, $this->from($transferId)),
            'fields' => $this->importFields((int) $metaPageId, $items),
            default => $this->importSequences((int) $metaPageId, $items, $transferId),
        };
    }

    /** @return array{http: int, body: array} */
    private function importValues(string $metaPageId, array $items, string $source): array
    {
        $values = [];
        $errors = [];
        foreach ($items as $i => $it) {
            $key = is_array($it) && is_string($it['key'] ?? null) ? $it['key'] : null;
            $value = is_array($it) ? ($it['value'] ?? null) : null;
            if ($key === null || ($value !== null && !is_string($value))) {
                $errors["items.$i"] = 'Send {"key": "<text>", "value": "<text or null>"}.';
                continue;
            }
            $values[$key] = $value === null ? null : trim($value);
        }
        if ($errors) return self::invalid($errors);

        $page = $this->page($metaPageId);
        if ($page === null) {
            $name = ForbeastSite::squash((string) ($values['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 255 || $this->settings->pageKey($name) === \App\Services\ChatblastSetupCheck::ALL_PAGE) return self::invalid(['name' => 'The page name is required (at most 255 characters) for a page that is not here yet.']);
            if ($this->clash($name, $metaPageId)) return self::error(409, 'name_taken', 'Another page here already uses this name.');
            $page = $name;
        }

        $flow = null;
        if (($values['flow'] ?? null) !== null && $values['flow'] !== '') {
            $flow = Schema::hasTable('chatbot_flows') ? ChatbotFlow::where('name', $values['flow'])->first() : null;
            if ($flow === null) return self::missing([$values['flow']]);
        }
        if (array_key_exists('active', $values) && !in_array($values['active'], ['0', '1'], true)) $errors['active'] = 'The value must be "0" or "1".';

        // Ang mga botfield na wala sa site na ito (hal. mas maraming seq column ang kabila) ay nilalaktawan at sinasabi.
        $editable = $this->values->editable();
        $fields = [];
        $skipped = [];
        foreach ($values as $key => $value) {
            if (in_array($key, self::META_KEYS, true)) continue;
            if (!isset($editable[$key])) {
                $skipped[] = (string) $key;
                continue;
            }
            if ($error = ChatbotFields::valueError($key, (string) $value, ['https'])) $errors[$key] = $error;
            $fields[$key] = $value;
        }
        if ($errors) return self::invalid($errors);

        DB::transaction(function () use ($page, $metaPageId, $values, $flow, $fields, $source) {
            $this->settings->set($page, 'botcake', 'page_id', $metaPageId, false, null, true, $source);
            if (array_key_exists('active', $values)) $this->settings->set($page, ChatbotFields::PROVIDER, 'active', $values['active'], false, null, true, $source);
            if (array_key_exists('flow', $values)) {
                if ($flow !== null) {
                    $this->settings->set($page, ChatbotFields::PROVIDER, 'flow', $flow->name, false, null, true, $source);
                    $this->settings->set($page, ChatbotFields::PROVIDER, 'flow_id', (string) $flow->id, false, null, true, $source);
                } else {
                    $this->deactivate($page, ChatbotFields::PROVIDER, ['flow', 'flow_id'], $source);
                }
            }
            if ($fields) $this->values->save($page, $fields, $source);
        });

        return ['http' => 200, 'body' => ['saved' => count($fields) + count(array_intersect(array_keys($values), self::META_KEYS)), 'skipped' => $skipped]];
    }

    /** @return array{http: int, body: array} */
    private function importFields(int $pageId, array $items): array
    {
        $rows = [];
        $errors = [];
        foreach ($items as $i => $it) {
            $psid = is_array($it) ? self::psid($it['psid'] ?? null) : null;
            $key = is_array($it) && is_string($it['key'] ?? null) ? trim($it['key']) : '';
            $value = is_array($it) ? ($it['value'] ?? null) : null;
            $at = is_array($it) && ($it['updated_at'] ?? null) !== null ? self::localTime($it['updated_at']) : null;
            if ($psid === null) $errors["items.$i.psid"] = 'A customer PSID (digits) is required.';
            elseif ($key === '' || mb_strlen($key) > 100 || str_starts_with($key, '__')) $errors["items.$i.key"] = 'A field name (at most 100 characters, not starting with "__") is required.';
            elseif ($value !== null && !is_string($value)) $errors["items.$i.value"] = 'The value must be text or null.';
            elseif (is_array($it) && ($it['updated_at'] ?? null) !== null && $at === null) $errors["items.$i.updated_at"] = 'Use an ISO 8601 time.';
            else $rows[] = ['psid' => $psid, 'key' => $key, 'value' => $value, 'updated_at' => $at];
        }
        if ($errors) return self::invalid($errors);

        DB::transaction(function () use ($pageId, $rows) {
            $have = [];
            foreach (DB::table('chatbot_customer_values')->where('page_id', $pageId)->whereIn('psid', array_unique(array_column($rows, 'psid')))
                ->get(['id', 'psid', 'key', 'updated_at']) as $r) {
                $have[$r->psid . '|' . $r->key] = ['id' => $r->id, 'at' => $r->updated_at];
            }
            foreach ($rows as $row) {
                $k = $row['psid'] . '|' . $row['key'];
                $old = $have[$k] ?? null;
                if ($old === null) {
                    $id = DB::table('chatbot_customer_values')->insertGetId(['page_id' => $pageId] + $row);
                    $have[$k] = ['id' => $id, 'at' => $row['updated_at']];
                    continue;
                }
                // Ang mas bago ang nananaig: ang value na binago na rito pagkatapos ng lipat ay hindi pinapalitan ng luma.
                if ($row['updated_at'] === null || ($old['at'] !== null && (string) $old['at'] > $row['updated_at'])) continue;
                DB::table('chatbot_customer_values')->where('id', $old['id'])->update(['value' => $row['value'], 'updated_at' => $row['updated_at']]);
                $have[$k]['at'] = $row['updated_at'];
            }
        });

        return ['http' => 200, 'body' => ['saved' => count($rows)]];
    }

    /** @return array{http: int, body: array} */
    private function importSequences(int $pageId, array $items, string $transferId): array
    {
        $rows = [];
        $errors = [];
        foreach ($items as $i => $it) {
            $psid = is_array($it) ? self::psid($it['psid'] ?? null) : null;
            $name = is_array($it) && is_string($it['sequence'] ?? null) ? trim($it['sequence']) : '';
            $step = is_array($it) ? ($it['current_step'] ?? null) : null;
            $next = is_array($it) ? ($it['next_send_at'] ?? null) : null;
            $at = $next === null ? null : self::localTime($next);
            $msg = is_array($it) ? ($it['last_message_at'] ?? null) : null;
            $msgAt = $msg === null ? null : self::localTime($msg);
            // Ang oras na lampas sa orasan dito ay ngayon: hindi nito mabubuksan ang 24 oras nang walang hangganan.
            if ($msgAt !== null && $msgAt > now()->format('Y-m-d H:i:s')) $msgAt = now()->format('Y-m-d H:i:s');
            // Walang status: active (gaya ng dati). Ang dahilan ng ended ay text lang, hanggang sa haba ng column.
            $status = is_array($it) ? ($it['status'] ?? 'active') : null;
            $reason = is_array($it) && is_string($it['exit_reason'] ?? null) && trim($it['exit_reason']) !== '' ? mb_substr(trim($it['exit_reason']), 0, 60) : 'ended on the old site';
            if ($psid === null) $errors["items.$i.psid"] = 'A customer PSID (digits) is required.';
            elseif ($name === '' || mb_strlen($name) > 120) $errors["items.$i.sequence"] = 'A sequence name is required.';
            elseif (!is_int($step) || $step < 1 || $step > 65535) $errors["items.$i.current_step"] = 'A step position (1 or more) is required.';
            elseif ($next !== null && $at === null) $errors["items.$i.next_send_at"] = 'Use an ISO 8601 time or null.';
            elseif ($msg !== null && $msgAt === null) $errors["items.$i.last_message_at"] = 'Use an ISO 8601 time or null.';
            elseif (!in_array($status, self::STATUSES, true)) $errors["items.$i.status"] = 'Use "active" or "ended".';
            else {
                $via = is_array($it) && in_array($it['enrolled_via'] ?? null, self::VIAS, true) ? $it['enrolled_via'] : 'other';
                $rows[] = ['psid' => $psid, 'name' => $name, 'current_step' => $step, 'next_send_at' => $at, 'enrolled_via' => $via, 'last_message_at' => $msgAt,
                    'ended' => $status === 'ended' ? $reason : null];
            }
        }
        if ($errors) return self::invalid($errors);

        $names = array_values(array_unique(array_column($rows, 'name')));
        $ids = $names ? ChatbotSequence::whereIn('name', $names)->pluck('id', 'name')->all() : [];
        $missing = array_values(array_filter($names, fn ($n) => !isset($ids[$n])));
        if ($missing) return self::missing($missing);

        $switched = $this->switchedAt($pageId, $transferId);
        DB::transaction(function () use ($pageId, $rows, $ids, $transferId, $switched) {
            foreach ($rows as $row) {
                $values = ['status' => self::HELD, 'exit_reason' => self::HELD_REASON . $transferId, 'current_step' => $row['current_step'], 'next_send_at' => $row['next_send_at'],
                    'dispatched_at' => null, 'enrolled_via' => $row['enrolled_via'], 'updated_at' => now()];
                $key = ['sequence_id' => $ids[$row['name']], 'page_id' => $pageId, 'psid' => $row['psid']];
                $existing = DB::table('chatbot_sequence_subscriptions')->where($key)->first(['id', 'status', 'exit_reason', 'updated_at']);
                $held = $existing !== null && $existing->status === self::HELD && str_starts_with((string) $existing->exit_reason, self::HELD_REASON);
                if ($row['ended'] !== null) {
                    // Tapos na sa source mula sa hold: ang held ng paglipat na ito ay tapos din dito, walang padala; wala nang iba.
                    if ($existing !== null && $existing->status === self::HELD && $existing->exit_reason === self::HELD_REASON . $transferId) {
                        DB::table('chatbot_sequence_subscriptions')->where('id', $existing->id)->where('status', self::HELD)->where('exit_reason', $existing->exit_reason)
                            ->update(['status' => $row['ended'] === 'completed' ? 'completed' : 'unsubscribed', 'exit_reason' => $row['ended'],
                                'next_send_at' => null, 'dispatched_at' => null, 'updated_at' => now()]);
                    }
                } elseif ($existing !== null && !$held && $switched !== null && !in_array($existing->status, ['active', 'paused'], true) && (string) $existing->updated_at >= $switched) {
                    // Tinapos dito pagkatapos ng switch (hal. sumagot sa bagong site): hindi binubuksan ulit ng catch-up.
                } elseif ($existing === null) {
                    DB::table('chatbot_sequence_subscriptions')->insert($key + $values + ['created_at' => now()]);
                } elseif ($held || !in_array($existing->status, ['active', 'paused'], true)) {
                    // Ang tumatakbo na rito (active o naka-pause, hindi held) ay hindi ginagalaw: walang dobleng follow-up.
                    DB::table('chatbot_sequence_subscriptions')->where('id', $existing->id)->where('status', $existing->status)->update($values);
                }
            }
            // Ang huling mensahe ng customer sa kabilang site (para sa 24 oras ng sequence dito): ang mas bago lang ang itinatala.
            $times = [];
            foreach ($rows as $row) {
                if ($row['last_message_at'] !== null && ($times[$row['psid']] ?? '') < $row['last_message_at']) $times[$row['psid']] = $row['last_message_at'];
            }
            foreach ($times as $psid => $at) {
                $old = DB::table('chatbot_customer_values')->where('page_id', $pageId)->where('psid', $psid)->where('key', CustomerFields::LAST_MESSAGE_AT)->first(['id', 'value']);
                if ($old === null) {
                    DB::table('chatbot_customer_values')->insert(['page_id' => $pageId, 'psid' => $psid, 'key' => CustomerFields::LAST_MESSAGE_AT, 'value' => $at, 'updated_at' => now()]);
                } elseif ((string) $old->value < $at) {
                    DB::table('chatbot_customer_values')->where('id', $old->id)->update(['value' => $at, 'updated_at' => now()]);
                }
            }
        });

        return ['http' => 200, 'body' => ['saved' => count($rows)]];
    }

    private static function psid(mixed $v): ?int
    {
        if (is_int($v) && $v > 0) return $v;
        if (is_string($v) && preg_match('/^[1-9]\d{0,19}$/', $v)) return (int) $v;

        return null;
    }

    /** Ang oras ng switch ng paglipat na ito dito (ang adopt), o null kapag wala pa (ang unang kopya ay bago ang switch). */
    private function switchedAt(int $pageId, string $transferId): ?string
    {
        $page = $this->page((string) $pageId);
        if ($page === null || $this->settings->get($page, self::HOLD_PROVIDER, self::ADOPTED_KEY) !== $transferId) return null;
        $at = $this->settings->pageQuery($page)->where('provider', self::HOLD_PROVIDER)->where('key', self::ADOPTED_KEY)->where('active', true)->orderByDesc('id')->value('created_at');

        return $at === null ? null : Carbon::parse($at)->format('Y-m-d H:i:s');
    }

    // ---- hold ----

    /**
     * Ang source bago kopyahin ang sequences: walang padala para sa page hanggang release o hold:false. Hindi binabago ang
     * updated_at (hindi ito pagbabago ng subscription), kaya ang catch-up na sequences?since= ay ang bago lang ang dala.
     * @return array{http: int, body: array}
     */
    public function hold(string $page, int $pageId, bool $hold, string $transferId): array
    {
        $source = 'transfer ' . $transferId . ($hold ? ' hold' : ' hold released');
        // held_at: ang oras ng site na ito bago ang hold, para sa since ng catch-up (hindi naaapektuhan ng layo ng orasan ng Forbeast).
        $heldAt = now()->toIso8601String();
        $heldRows = fn () => DB::table('chatbot_sequence_subscriptions')->where('page_id', $pageId)->where('status', self::HELD)->where('exit_reason', 'like', self::HOLD_REASON . '%');
        DB::transaction(function () use ($page, $pageId, $hold, $transferId, $source, $heldRows) {
            $owner = $this->settings->get($page, self::HOLD_PROVIDER, self::HOLD_KEY);
            if ($hold) {
                // Una ang tanda ng page, saka ang mga row: ang subscribe na sabay ay held na rin. Ang mas bagong paglipat ang
                // may-ari ng hold (pati ang mga row ng nauna), kaya ang huling hold:false ng nakanselang paglipat ay walang epekto.
                if ($owner !== $transferId) $this->settings->set($page, self::HOLD_PROVIDER, self::HOLD_KEY, $transferId, false, null, true, $source);
                DB::table('chatbot_sequence_subscriptions')->where('page_id', $pageId)
                    ->where(fn ($q) => $q->where('status', 'active')->orWhere(fn ($w) => $w->where('status', self::HELD)->where('exit_reason', 'like', self::HOLD_REASON . '%')))
                    ->update(['status' => self::HELD, 'exit_reason' => self::HOLD_REASON . $transferId, 'dispatched_at' => null]);

                return;
            }
            if ($owner !== $transferId) return;
            $this->deactivate($page, self::HOLD_PROVIDER, [self::HOLD_KEY], $source);
            $heldRows()->update(['status' => 'active', 'exit_reason' => null, 'dispatched_at' => null]);
        });
        PageSettings::forget($page);

        return ['http' => 200, 'body' => ['held' => $heldRows()->count()] + ($hold ? ['held_at' => $heldAt] : [])];
    }

    // ---- release / activate ----

    /** @return array{http: int, body: array} */
    public function release(string $page, string $metaPageId, string $to): array
    {
        $pageId = (int) $metaPageId;
        $reason = 'transferred to ' . $to;
        $flowId = trim((string) $this->settings->get($page, ChatbotFields::PROVIDER, 'flow_id'));

        $ended = DB::transaction(function () use ($page, $pageId, $reason) {
            $this->settings->set($page, ChatbotFields::PROVIDER, 'active', '0', false, null, true, $reason);
            // Ang naiwang held ng nabigong paglipat papunta rito ay hindi naging totoo dito: hindi ito dala ng catch-up.
            DB::table('chatbot_sequence_subscriptions')->where('page_id', $pageId)->where('status', self::HELD)->where('exit_reason', 'like', self::HELD_REASON . '%')
                ->update(['status' => 'unsubscribed', 'exit_reason' => 'transfer not activated', 'next_send_at' => null, 'dispatched_at' => null, 'updated_at' => now()]);
            $ended = DB::table('chatbot_sequence_subscriptions')->where('page_id', $pageId)->whereIn('status', ['active', 'paused'])
                // Ang step at oras ng padala ay naiiwan, at ang updated_at ay hindi binabago: ang catch-up (sequences?since=) ay
                // ang binago lang mula sa hold ang dala, kaya ang tinapos na sa bagong site (hal. sumagot) ay hindi bumabalik.
                ->update(['status' => 'unsubscribed', 'exit_reason' => 'transferred', 'dispatched_at' => null]);
            // Ang nasa gitna ng flow: walang wait, walang naghihintay na AI o padala (sa bagong site sila magsisimula ulit).
            if (Schema::hasTable('chatbot_waits')) DB::table('chatbot_waits')->where('page_id', $pageId)->delete();
            DB::table('chatbot_customer_values')->where('page_id', $pageId)->whereIn('key', [CustomerFields::WAIT, CustomerFields::AI])->delete();
            if (Schema::hasTable(ChatbotAiQueue::TABLE)) {
                DB::table(ChatbotAiQueue::TABLE)->where('page_id', $pageId)->whereIn('status', ['pending', 'sending', 'answered', 'failed'])
                    ->update(['status' => 'resumed', 'error' => 'page transferred', 'updated_at' => now()]);
            }
            if (Schema::hasTable(BotcakeSendQueue::TABLE)) {
                DB::table(BotcakeSendQueue::TABLE)->where('page_id', $pageId)->where('kind', 'chatbot')->whereIn('status', ['pending', 'sending', 'done', 'failed'])
                    ->update(['status' => 'resumed', 'error' => 'page transferred', 'updated_at' => now()]);
            }
            // Ang Forbeast connection ng page: inactive, naiiwan bilang history (walang DELETE: nailipat na ng Forbeast ang webhook).
            $this->deactivate($page, ForbeastPageSetup::PROVIDER, ['token', 'webhook_secret', 'token_status', 'permissions'], $reason);
            // Wala nang hold: kapag bumalik ang page dito balang araw, ang bagong subscribe ay hindi held.
            $this->deactivate($page, self::HOLD_PROVIDER, [self::HOLD_KEY], $reason);

            return $ended;
        });
        PageSettings::forget($page);
        if (ctype_digit($flowId)) BotConfigCache::forgetFlow((int) $flowId);

        return ['http' => 200, 'body' => ['ok' => true, 'ended' => $ended]];
    }

    /** @return array{http: int, body: array} */
    public function activate(int $pageId, string $transferId): array
    {
        // Ang held lang ng paglipat na ito: ang naiwan ng nabigong paglipat noon ay hindi ipinapadala.
        $n = DB::table('chatbot_sequence_subscriptions')->where('page_id', $pageId)->where('status', self::HELD)->where('exit_reason', self::HELD_REASON . $transferId)
            ->update(['status' => 'active', 'exit_reason' => null, 'dispatched_at' => null, 'updated_at' => now()]);

        return ['http' => 200, 'body' => ['ok' => true, 'activated' => $n]];
    }

    /** @param array<int, string> $keys */
    private function deactivate(string $page, string $provider, array $keys, string $source): void
    {
        $rows = $this->settings->pageQuery($page)->where('provider', $provider)->whereIn('key', $keys)->where('active', true)->get();
        foreach ($rows as $s) $this->settings->deactivate($s, null, $source);
    }

    // ---- sagot ----

    /** @return array{http: int, body: array} */
    public static function error(int $http, string $code, string $message): array
    {
        return ['http' => $http, 'body' => ['error' => $code, 'message' => $message]];
    }

    /** @param array<string, string> $errors @return array{http: int, body: array} */
    public static function invalid(array $errors): array
    {
        return ['http' => 422, 'body' => ['error' => 'invalid_value', 'message' => 'Some items cannot be saved, so nothing was saved.', 'errors' => $errors]];
    }

    /** @param array<int, string> $names @return array{http: int, body: array} */
    private static function missing(array $names): array
    {
        return ['http' => 422, 'body' => ['error' => 'missing_names', 'message' => 'This site has no flow or sequence with these names, so nothing was saved.',
            'names' => array_values($names)]];
    }
}
