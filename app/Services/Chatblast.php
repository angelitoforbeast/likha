<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\BotcakeMessage;
use App\Models\PageSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * /chatblast: ang listahan ng mga order update na ipapadala sa customer sa Botcake, at ang text nila.
 *
 * Bawat uri (kind) ay may J&T status (from_jnts.status, walang RTS reason; kapareho ng /jnt status) at mga araw: Araw 1,
 * Araw 2, … bawat isa may isa o higit pang bersyon ng text (A, B, …), nakatala sa app_settings. Isang mensahe kada
 * waybill kada uri kada araw (Manila); ang araw = ilan na ang naipadala (sent o unknown) + 1. Humihinto kapag ubos na ang
 * araw o hindi na ganoong status ang waybill. Ang bersyon ay umiikot ayon sa numero ng waybill, kaya magkaiba ang
 * magkatabing customer at pareho ang napipili kapag binuo ulit. Ready kapag may PSID ang order at kumpleto ang Botcake
 * settings ng page; kung hindi, no_psid o no_config (kasama kung ano ang kulang).
 */
class Chatblast
{
    /**
     * Mga uri: ang J&T status, ilang araw pabalik ang order (ts_date), at ang default na mga araw (walang emoji: lumabas
     * na "�" ang isa sa naunang test).
     */
    public const KINDS = [
        'delivering' => [
            'status' => 'Delivering',
            'window' => 7,
            'days'   => [
                ["Hello Ma'am/Sir {name}, Idedeliver po ngayon ang order niyo. Maraming salamat po sa pag-order, ingat po kayo at salamat ulit sa tiwala!"],
            ],
        ],
        'in_transit' => [
            'status' => 'In Transit',
            'window' => 14,
            'days'   => [
                ["Hello Ma'am/Sir {name}, nasa biyahe na po ang order niyo ({item}). Ipapaalam po namin kapag malapit na. Salamat po!"],
                ["Hi Ma'am/Sir {name}, tuloy-tuloy po ang biyahe ng order niyo. Waybill: {waybill}. Salamat po sa paghihintay!"],
                ["Magandang araw po Ma'am/Sir {name}! Nasa daan pa rin po ang parcel niyo. Pakihanda na lang po ang PHP {cod} pagdating ng rider. Salamat po!"],
                ["Hello po Ma'am/Sir {name}, malapit na po ang order niyo mula sa {page}. Paki-abangan po ang tawag o text ng rider. Maraming salamat po!"],
                ["Hi Ma'am/Sir {name}, nasa biyahe pa rin po ang order niyo. Kung may tanong po kayo, mag-message lang po dito. Salamat po sa tiwala!"],
            ],
        ],
    ];

    /** Ang kailangan ng page para makapagpadala (page_id para sa bawat API path). */
    public const REQUIRED = ['page_id', 'token', 'flow.shipping', 'field.message'];

    /** Ang mga status na hindi pa naipapadala: dito lang nagbabago ang text kapag binago ang mga araw. */
    public const UNSENT = ['ready', 'no_psid', 'no_config', 'failed', 'field_mismatch', 'skipped'];

    public const PLACEHOLDERS = ['name', 'item', 'cod', 'waybill', 'page', 'rider', 'rider_phone'];
    public const RIDER_REASON = 'walang rider info';
    public const MAX_TEXT = 1000;
    public const MAX_DAYS = 30;
    public const MAX_VERSIONS = 10;
    public const MAX_TOTAL = 60000; // lahat ng text ng isang uri (kasya sa TEXT ng app_settings)

    /**
     * Gaano kalayo na ang J&T status. Kapag may ilang from_jnts row ang waybill (may kopya na hindi na-update), ang
     * pinakamalayo ang totoo: ang kopyang "In Transit" pa rin ay hindi nagpapanatili sa waybill sa In Transit.
     */
    private const PROGRESS = ['In Transit' => 1, 'Delivering' => 2, 'Delivered' => 3, 'For Return' => 3, 'Returned' => 3];

    public function __construct(private PageSettings $settings)
    {
    }

    /** Ang mga araw ng uri: listahan ng araw, bawat isa listahan ng text (bersyon A, B, …). */
    public function sequence(string $kind): array
    {
        $saved = json_decode((string) AppSetting::get('chatblast.sequence.' . $kind), true);

        return $this->invalid($saved) === null ? $saved : self::KINDS[$kind]['days'] ?? [];
    }

    /** Ang mali sa mga araw, o null kapag tama: kahit isang araw, bawat araw kahit isang bersyon, walang blangkong text. */
    public function invalid(mixed $days): ?string
    {
        if (!is_array($days) || !array_is_list($days) || !$days) return 'Kahit isang araw.';
        if (count($days) > self::MAX_DAYS) return 'Hanggang ' . self::MAX_DAYS . ' na araw lang.';
        foreach ($days as $i => $versions) {
            $n = $i + 1;
            if (!is_array($versions) || !array_is_list($versions) || !$versions) return 'Araw ' . $n . ': kahit isang bersyon ng text.';
            if (count($versions) > self::MAX_VERSIONS) return 'Araw ' . $n . ': hanggang ' . self::MAX_VERSIONS . ' na bersyon lang.';
            foreach ($versions as $j => $text) {
                $label = 'Araw ' . $n . ' · ' . self::label($j);
                if (!is_string($text) || trim($text) === '') return $label . ': walang laman ang text.';
                if (mb_strlen($text) > self::MAX_TEXT) return $label . ': hanggang ' . self::MAX_TEXT . ' na character lang.';
                preg_match_all('/\{([^{}]*)\}/', $text, $m); // anumang {…}: ang may maling baybay ay hindi lalabas nang ganoon sa customer
                $unknown = array_diff($m[1], self::PLACEHOLDERS);
                if ($unknown) return $label . ': hindi kilalang placeholder {' . reset($unknown) . '}.';
            }
        }
        if (strlen((string) json_encode($days, JSON_UNESCAPED_UNICODE)) > self::MAX_TOTAL) { // bytes: ang TEXT ay 65,535 bytes
            return 'Masyadong mahaba ang lahat ng text ng uri (hanggang ' . number_format(self::MAX_TOTAL) . ' bytes).';
        }

        return null;
    }

    /**
     * Bagong mga araw ng uri (tinatanggal ang espasyo sa dulo ng bawat text). Binubuo ulit ang text ng mga hindi pa
     * naipapadala, sa parehong araw at sa parehong pagpili ng bersyon. @return int bilang ng nabagong row
     */
    public function setSequence(string $kind, mixed $days): int
    {
        if (!isset(self::KINDS[$kind])) throw new InvalidArgumentException('Hindi kilalang uri.');
        if (($error = $this->invalid($days)) !== null) throw new InvalidArgumentException($error);

        $days = array_map(fn ($versions) => array_map('trim', $versions), $days);
        AppSetting::set('chatblast.sequence.' . $kind, json_encode($days, JSON_UNESCAPED_UNICODE));

        return $this->rerender($kind, $days);
    }

    /** Ang text: {name}, {item}, {cod}, {waybill}, {page}; {rider} at {rider_phone} ay walang laman hangga't wala pa. */
    public function render(string $template, array $vars): string
    {
        $map = [];
        foreach (self::PLACEHOLDERS as $k) $map['{' . $k . '}'] = trim((string) ($vars[$k] ?? ''));

        return trim(strtr($template, $map));
    }

    public static function label(int $index): string
    {
        return chr(65 + $index);
    }

    /** Ang mga placeholder ng text na walang laman para sa order (ang COD na 0 ay walang laman: walang babayaran). */
    public function missingIn(string $text, array $vars): array
    {
        preg_match_all('/\{(' . implode('|', self::PLACEHOLDERS) . ')\}/', $text, $m);
        return array_values(array_unique(array_filter($m[1], function ($k) use ($vars) {
            $v = trim((string) ($vars[$k] ?? ''));
            return $v === '' || ($k === 'cod' && is_numeric($v) && (float) $v == 0.0);
        })));
    }

    /** Ang dahilan kapag walang bersyon ng araw na buo para sa order: "walang rider info", o "walang {x}". */
    public function missingReason(array $versions, array $vars): string
    {
        $missing = array_unique(array_merge(...array_map(fn ($t) => $this->missingIn($t, $vars), $versions)));
        $other = array_values(array_diff($missing, ['rider', 'rider_phone']));

        return $other ? 'walang {' . $other[0] . '}' : self::RIDER_REASON;
    }

    /** Dahilan na galing sa kulang na laman ng text (hindi sa PSID o settings): hindi ito binabago ng recheck. */
    public static function isTextReason(?string $reason): bool
    {
        return $reason === self::RIDER_REASON || str_starts_with((string) $reason, 'walang {') || str_starts_with((string) $reason, 'hindi buo ang text');
    }

    /**
     * Ang bersyon para sa waybill: umiikot ayon sa huling 9 na digit ng waybill (kaya magkaiba ang magkasunod na waybill),
     * kung walang digit ay ayon sa hash. Ang mga bersyong may placeholder na walang laman para sa order ay hindi ginagamit
     * (hal. walang COD, walang pangalan, wala pang rider info), para walang "Hello Ma'am/Sir , …" na lumalabas.
     * @return array{0:int, 1:string}|null [index ng bersyon sa araw, text] o null kapag walang buong bersyon
     */
    public function pick(array $versions, string $waybill, array $vars): ?array
    {
        $usable = [];
        foreach ($versions as $i => $text) {
            if (!$this->missingIn($text, $vars)) $usable[$i] = $text;
        }
        if (!$usable) return null;

        $digits = preg_replace('/\D+/', '', $waybill);
        $base   = $digits !== '' ? (int) substr($digits, -9) : crc32($waybill);
        $index  = array_keys($usable)[$base % count($usable)];

        return [$index, $this->render($usable[$index], $vars)];
    }

    /**
     * "Kunin ang mga bago" para sa isang uri, o lahat (null): isang row kada waybill na nasa status ng uri at wala pang row
     * ng uring iyon ngayong araw; sinusuri ulit ang no_psid / no_config, at nilalaktawan ang hindi pa naipapadala na wala
     * na sa status ng uri.
     * @return array{added:int, ready:int, no_psid:int, no_config:int, updated:int, skipped:int}
     */
    public function build(?string $kind = null, ?int $userId = null): array
    {
        $out = ['added' => 0, 'ready' => 0, 'no_psid' => 0, 'no_config' => 0, 'updated' => 0, 'skipped' => 0];
        $missing = $this->missingByPage();

        foreach ($kind === null ? array_keys(self::KINDS) : [$kind] as $k) {
            $out['skipped'] += $this->skipStale($k);
            foreach ($this->buildKind($k, $userId, $missing) as $key => $n) $out[$key] += $n;
            $out['updated'] += $this->recheck($k, $missing);
        }

        return $out;
    }

    private function buildKind(string $kind, ?int $userId, array $missing): array
    {
        $out   = ['added' => 0, 'ready' => 0, 'no_psid' => 0, 'no_config' => 0];
        $days  = $this->sequence($kind);
        $since = now('Asia/Manila')->subDays(self::KINDS[$kind]['window'])->toDateString();
        $now   = now();
        $seen  = [];

        $this->candidates($kind, $since)->chunkById(500, function ($orders) use (&$out, &$seen, $days, $missing, $kind, $userId, $now) {
            $rows = [];
            $jnt  = $this->currentStatuses($orders->pluck('waybill')->unique()->values()->all());
            foreach ($orders as $o) {
                $waybill = trim((string) $o->waybill);
                if ($waybill === '' || isset($seen[$waybill])) continue; // isang row kada waybill (kahit may kopya ang order)
                $seen[$waybill] = true;
                if (!$this->matches($kind, $jnt[$o->waybill] ?? [])) continue; // may kopyang mas malayo na ang status

                $day = (int) $o->sent_n + 1;
                if ($day > count($days)) continue; // ubos na ang mga araw ng uri

                $page = $this->settings->normPage((string) $o->page);
                $vars = ['name' => trim((string) $o->fb_name) ?: trim((string) $o->receiver), 'item' => $o->item, 'cod' => $o->cod, 'waybill' => $waybill, 'page' => $page];
                $pick = $this->pick($days[$day - 1], $waybill, $vars);
                [$status, $reason] = $pick === null ? ['no_config', $this->missingReason($days[$day - 1], $vars)]
                    : $this->readiness($o->botcake_psid, $missing[$this->settings->pageKey($page)] ?? self::REQUIRED);

                $rows[] = [
                    'page_name' => $page, 'macro_output_id' => $o->id, 'waybill' => $waybill, 'kind' => $kind, 'day' => $day,
                    'version' => $pick ? self::label($pick[0]) : null, 'fb_name' => $o->fb_name, 'psid' => trim((string) $o->botcake_psid) ?: null,
                    'vars' => json_encode($vars, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE), 'text' => $pick[1] ?? null,
                    'jnt_status' => self::KINDS[$kind]['status'], 'status' => $status, 'reason' => $reason, 'attempts' => 0, 'is_test' => false,
                    'dedupe_key' => $waybill . '|' . $kind . '|' . $day, 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            if (!$rows) return;

            // insertOrIgnore: ang unique dedupe_key (waybill|kind|day) ang humaharang sa doble, kasama ang hindi pa
            // naipapadalang araw mula kahapon (hindi ito inuulit) at ang sabay na kuha.
            $added = DB::table('botcake_messages')->insertOrIgnore($rows);
            $out['added'] += $added;
            if ($added === count($rows)) {
                foreach ($rows as $r) $out[$r['status']]++;
            } elseif ($added > 0) {
                $mine = DB::table('botcake_messages')->whereIn('dedupe_key', array_column($rows, 'dedupe_key'))->where('created_at', $now)
                    ->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
                foreach ($mine as $status => $n) $out[$status] += (int) $n;
            }
        }, 'mo.id', 'id');

        return $out;
    }

    /** @return array{0:string, 1:?string} [status, dahilan] */
    private function readiness(?string $psid, array $missingKeys): array
    {
        if ($missingKeys) return ['no_config', 'walang ' . implode(', ', $missingKeys)];
        if (trim((string) $psid) === '') return ['no_psid', 'walang PSID ang order (hindi pa tugma sa Botcake customer)'];

        return ['ready', null];
    }

    /** Ang mga no_psid / no_config na maaaring ready na (hindi ang kulang sa rider info: ang pagbago ng araw ang bahala). */
    private function recheck(string $kind, array $missing): int
    {
        $changed = 0;
        BotcakeMessage::where('kind', $kind)->where('is_test', false)->whereIn('status', ['no_psid', 'no_config'])
            ->chunkById(500, function ($rows) use (&$changed, $missing) {
                $psids = DB::table('macro_output')->whereIn('id', $rows->pluck('macro_output_id')->filter()->all())->pluck('botcake_psid', 'id');
                foreach ($rows as $row) {
                    if (self::isTextReason($row->reason)) continue; // ang pagbago ng mga araw ang bahala rito
                    $psid = trim((string) ($psids[$row->macro_output_id] ?? $row->psid)) ?: null;
                    [$status, $reason] = $this->readiness($psid, $missing[$this->settings->pageKey($row->page_name)] ?? self::REQUIRED);
                    if ($status === $row->status && $reason === $row->reason && $psid === $row->psid) continue;
                    $changed += BotcakeMessage::whereKey($row->id)->whereIn('status', ['no_psid', 'no_config'])
                        ->update(['status' => $status, 'reason' => $reason, 'psid' => $psid]);
                }
            });

        return $changed;
    }

    /** Ang hindi pa naipapadala na wala na sa status ng uri (hal. In Transit → Delivered): skipped. @return int bilang */
    private function skipStale(string $kind): int
    {
        $skipped = 0;
        BotcakeMessage::where('kind', $kind)->where('is_test', false)->whereIn('status', array_diff(self::UNSENT, ['skipped']))
            ->select('id', 'waybill')->chunkById(500, function ($rows) use (&$skipped, $kind) {
                $now = $this->currentStatuses($rows->pluck('waybill')->unique()->values()->all());
                foreach ($rows as $row) {
                    if ($this->matches($kind, $now[$row->waybill] ?? [])) continue;
                    $skipped += BotcakeMessage::whereKey($row->id)->whereIn('status', self::UNSENT)
                        ->update(['status' => 'skipped', 'reason' => $this->staleReason($kind, $now[$row->waybill] ?? [])]);
                }
            });

        return $skipped;
    }

    /** Ang dahilan kapag ang waybill ay wala na sa status ng uri, o null kapag nasa status pa (para sa sender). */
    public function staleFor(string $kind, string $waybill): ?string
    {
        $rows = $this->currentStatuses([$waybill])[$waybill] ?? [];

        return $this->matches($kind, $rows) ? null : $this->staleReason($kind, $rows);
    }

    private function staleReason(string $kind, array $rows): string
    {
        $top = $this->latest($rows);
        return 'Hindi na ' . self::KINDS[$kind]['status'] . ' ang waybill (ngayon: ' . ($top['status'] ?? 'wala sa J&T') . ')';
    }

    /** Nasa status ng uri ba ang waybill: ang pinakamalayong from_jnts row nito ang status ng uri, walang RTS reason. */
    private function matches(string $kind, array $rows): bool
    {
        $top = $this->latest($rows);
        return $top !== null && $top['status'] === self::KINDS[$kind]['status'] && trim((string) $top['rts_reason']) === '';
    }

    /** Ang pinakamalayong J&T row (PROGRESS; kapag pantay, ang pinakahuling naitala). */
    private function latest(array $rows): ?array
    {
        $top = null;
        foreach ($rows as $r) {
            if ($top === null || (self::PROGRESS[$r['status']] ?? 0) >= (self::PROGRESS[$top['status']] ?? 0)) $top = $r;
        }
        return $top;
    }

    /** @return array<string, array<int, array{status:?string, rts_reason:?string}>> waybill => mga J&T row, ayon sa id */
    private function currentStatuses(array $waybills): array
    {
        $out = [];
        if (!$waybills) return $out;
        DB::table('from_jnts')->whereIn('waybill_number', $waybills)->orderBy('id')->get(['waybill_number', 'status', 'rts_reason'])
            ->each(function ($r) use (&$out) { $out[$r->waybill_number][] = ['status' => $r->status, 'rts_reason' => $r->rts_reason]; });

        return $out;
    }

    /**
     * Ang dahilan kapag hindi dapat ipadala ngayong araw ang totoong mensahe (null kapag puwede): may naipadala na (o
     * maaaring naipadala, unknown) o ipinapadala na ang ibang mensahe ng parehong waybill at uri ngayong araw (Manila),
     * o wala na ang araw nito sa mga araw ng uri. Ang "sending" ay bilang lang sa loob ng 10 minuto (mas mahaba sa
     * pinakamatagal na job): ang naiwan dahil pinatay ang worker ay hindi humaharang habambuhay.
     */
    public function blockedToday(BotcakeMessage $m): ?string
    {
        if ($m->day > count($this->sequence($m->kind))) return 'Wala nang Araw ' . $m->day . ' sa mga araw ng uri';

        $today = Carbon::now('Asia/Manila')->startOfDay()->timezone(config('app.timezone'));
        $other = BotcakeMessage::where('waybill', $m->waybill)->where('kind', $m->kind)->where('is_test', false)->whereKeyNot($m->id)
            ->where(fn ($w) => $w->where(fn ($s) => $s->where('status', 'sent')->where('sent_at', '>=', $today))
                ->orWhere(fn ($u) => $u->where('status', 'unknown')->where('updated_at', '>=', $today))
                ->orWhere(fn ($x) => $x->where('status', 'sending')->where('updated_at', '>=', now()->subMinutes(10))))
            ->exists();

        // Kada waybill ang patakaran, hindi kada customer: ang customer na may dalawang waybill ay may tig-isang mensahe
        // ang bawat waybill, kahit parehong uri sa parehong araw (sunod-sunod, dahil sa lock ng customer sa sender).
        return $other ? 'May naipadala nang mensahe ng uring ito sa waybill ngayong araw; ipadala bukas' : null;
    }

    /** Binubuo ulit ang text ng hindi pa naipapadala ayon sa bagong mga araw. @return int bilang ng nabago */
    private function rerender(string $kind, array $days): int
    {
        $missing = $this->missingByPage();
        $changed = 0;
        BotcakeMessage::where('kind', $kind)->where('is_test', false)->whereIn('status', self::UNSENT)
            ->chunkById(500, function ($rows) use ($days, $missing, &$changed) {
                foreach ($rows as $row) {
                    if ($row->day > count($days)) {
                        $set = ['status' => 'skipped', 'reason' => 'Wala nang Araw ' . $row->day . ' sa mga araw ng uri'];
                    } elseif (($pick = $this->pick($days[$row->day - 1], $row->waybill, (array) $row->vars)) === null) {
                        $set = ['status' => 'no_config', 'reason' => $this->missingReason($days[$row->day - 1], (array) $row->vars), 'version' => null, 'text' => null];
                    } else {
                        $set = ['version' => self::label($pick[0]), 'text' => $pick[1]];
                        if (self::isTextReason($row->reason)) {
                            [$set['status'], $set['reason']] = $this->readiness($row->psid, $missing[$this->settings->pageKey($row->page_name)] ?? self::REQUIRED);
                        }
                    }
                    $dirty = array_filter($set, fn ($v, $k) => $row->{$k} !== $v, ARRAY_FILTER_USE_BOTH);
                    if ($dirty) $changed += BotcakeMessage::whereKey($row->id)->whereIn('status', self::UNSENT)->update($dirty);
                }
            });

        return $changed;
    }

    /**
     * Ang kulang na Botcake settings ng bawat page na may kahit isa (isang query). Ang page na wala rito: lahat kulang.
     * @return array<string, array<int, string>> pageKey => kulang na key
     */
    public function missingByPage(): array
    {
        $have = [];
        PageSetting::where('active', true)->where('provider', 'botcake')->whereIn('key', self::REQUIRED)->get(['page_name', 'key'])
            ->each(function ($s) use (&$have) { $have[$this->settings->pageKey($s->page_name)][$s->key] = true; });

        return array_map(fn ($keys) => array_values(array_filter(self::REQUIRED, fn ($k) => !isset($keys[$k]))), $have);
    }

    /**
     * Ang mga order na nasa status ng uri sa loob ng window, na wala pang row ng uring ito ngayong araw (Manila), kasama
     * kung ilan na ang naipadala (sent o unknown: maaaring naipadala) para sa waybill.
     */
    private function candidates(string $kind, string $since)
    {
        $col   = fn (string $c) => DB::getDriverName() === 'pgsql' ? 'mo."' . $c . '"' : 'mo.`' . $c . '`';
        $today = Carbon::now('Asia/Manila')->startOfDay()->timezone(config('app.timezone'));

        // Pinakamababang id ng window (index ng ts_date), para hindi magsimula sa id 1 ang paglakad ng chunk.
        $fromId = (int) DB::table('macro_output')->where('ts_date', '>=', $since)->min('id');

        return DB::table('macro_output as mo')
            ->where('mo.id', '>=', $fromId)
            ->where('mo.ts_date', '>=', $since)
            ->whereNotNull('mo.waybill')->where('mo.waybill', '<>', '')
            ->whereExists(fn ($e) => $e->from('from_jnts as j')->whereColumn('j.waybill_number', 'mo.waybill')->where('j.status', self::KINDS[$kind]['status'])
                ->where(fn ($r) => $r->whereNull('j.rts_reason')->orWhereRaw("TRIM(j.rts_reason) = ''"))->selectRaw('1'))
            ->whereNotExists(fn ($e) => $e->from('botcake_messages as m')->whereColumn('m.waybill', 'mo.waybill')->where('m.kind', $kind)
                ->where('m.is_test', false)->whereNotNull('m.dedupe_key') // ang itinabi dahil nagbago ang PSID/page ay hindi bilang
                ->where(fn ($t) => $t->where('m.created_at', '>=', $today)->orWhere('m.sent_at', '>=', $today)->orWhere('m.queued_at', '>=', $today)
                    ->orWhere(fn ($u) => $u->where('m.status', 'unknown')->where('m.updated_at', '>=', $today)))
                ->selectRaw('1'))
            ->select('mo.id', 'mo.fb_name', 'mo.waybill', 'mo.botcake_psid')
            ->selectRaw($col('PAGE') . ' AS page, ' . $col('ITEM_NAME') . ' AS item, ' . $col('COD') . ' AS cod')
            ->selectSub(fn ($s) => $s->from('from_jnts as r')->whereColumn('r.waybill_number', 'mo.waybill')->selectRaw('MAX(r.receiver)'), 'receiver')
            ->selectSub(fn ($s) => $s->from('botcake_messages as s')->whereColumn('s.waybill', 'mo.waybill')->where('s.kind', $kind)
                ->where('s.is_test', false)->whereIn('s.status', ['sent', 'unknown'])->selectRaw('COUNT(*)'), 'sent_n');
    }
}
