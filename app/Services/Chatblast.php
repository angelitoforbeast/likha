<?php

namespace App\Services;

use App\Models\BotcakeMessage;
use App\Models\ChatblastCampaign;
use App\Models\PageSetting;
use App\Services\Jnt\JntTracker;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * /chatblast: ang listahan ng mga order update na ipapadala sa customer sa Botcake, at ang text nila.
 *
 * Bawat campaign (chatblast_campaigns; ang "kind" nito ang susi sa botcake_messages.kind) ay may mga J&T status na
 * nagti-trigger (from_jnts.status), mga page, window, at mga araw: Araw 1, Araw 2, … bawat isa may isa o higit pang
 * bersyon ng text (A, B, …). Isang mensahe kada waybill kada campaign kada araw (Manila); ang araw = ilan na ang
 * naipadala (sent o unknown) + 1. Humihinto kapag ubos na ang araw o wala na sa mga status ng campaign ang waybill. Ang
 * bersyon ay umiikot ayon sa numero ng waybill, kaya magkaiba ang magkatabing customer at pareho ang napipili kapag
 * binuo ulit. Ready kapag may PSID ang order at kumpleto ang Botcake settings ng page; kung hindi, no_psid o no_config
 * (kasama kung ano ang kulang). Ang naka-off na campaign ay walang binubuo at walang ipinapadala.
 */
class Chatblast
{
    /** Ang status na ito na may RTS reason ay pabalik na sa seller (gaya ng /jnt status): hindi ito ang biyahe papunta. */
    private const RTS_EXCLUDED = ['In Transit', 'Delivering'];
    public const LOG_MAX = 200; // ilang pagbabago ang itinatago sa log ng campaign
    public const CROSS = 'may naipadala na ngayong araw sa ibang campaign';

    /** Ang kailangan ng page para makapagpadala (page_id para sa bawat API path). */
    public const REQUIRED = ['page_id', 'token', 'flow.shipping', 'field.message'];

    /** Ang mga status na hindi pa naipapadala: dito lang nagbabago ang text kapag binago ang mga araw. */
    public const UNSENT = ['ready', 'no_psid', 'no_config', 'failed', 'field_mismatch', 'skipped'];

    public const PLACEHOLDERS = ['name', 'item', 'cod', 'waybill', 'page', 'rider', 'rider_phone'];
    public const RIDER_REASON = 'walang rider info';
    public const TRACK_BUDGET = 60;        // segundo ng J&T tracking ng chatblast:build (ang web build: 20, tingnan ang controller)
    public const SEND_TRACK_BUDGET = 10;   // segundo ng J&T bago ipadala: isang tawag
    public const BUILD_LOCK = 'chatblast-build';
    public const RIDER_MAX_AGE = 30 * 60;  // bago ipadala ang delivering: i-track ulit kapag mas luma rito
    public const MAX_TEXT = 1000;
    public const MAX_DAYS = 30;
    public const MAX_VERSIONS = 10;
    public const MAX_TOTAL = 60000; // lahat ng text ng isang campaign (bytes ng JSON)

    /**
     * Gaano kalayo na ang J&T status. Kapag may ilang from_jnts row ang waybill (may kopya na hindi na-update), ang
     * pinakamalayo ang totoo: ang kopyang "In Transit" pa rin ay hindi nagpapanatili sa waybill sa In Transit.
     */
    private const PROGRESS = ['In Transit' => 1, 'Delivering' => 2, 'Delivered' => 3, 'For Return' => 3, 'Returned' => 3];

    public function __construct(private PageSettings $settings)
    {
    }

    /** Ang campaign ng kind (laging bago mula sa database: ang on/off at text ay maaaring nagbago). */
    public function campaign(string $kind): ?ChatblastCampaign
    {
        return ChatblastCampaign::where('kind', $kind)->first(ChatblastCampaign::COLUMNS);
    }

    /** Ang mga araw ng campaign: listahan ng araw, bawat isa listahan ng text (bersyon A, B, …). */
    public function sequence(string $kind): array
    {
        $days = $this->campaign($kind)?->days;

        return $this->invalid($days) === null ? $days : [];
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

    /** Bagong mga araw ng campaign (buo). @return int bilang ng nabagong row */
    public function setSequence(string $kind, mixed $days, ?int $userId = null): int
    {
        $c = $this->campaign($kind);
        if (!$c) throw new InvalidArgumentException('Hindi kilalang campaign.');

        return $this->editDays($c->id, fn () => $days, $userId, ['what' => 'days']);
    }

    /**
     * Baguhin ang mga araw ng campaign: $change(lumang mga araw) → bagong mga araw (InvalidArgumentException kapag
     * hindi puwede). Naka-lock ang row habang binabago, kaya ang dalawang sabay na cell ay parehong naiiwan. Tinatanggal
     * ang espasyo sa dulo ng bawat text, itinatala sa log, at binubuo ulit ang text ng mga hindi pa naipapadala (parehong
     * araw at parehong pagpili ng bersyon). @return int bilang ng nabagong row
     */
    public function editDays(int $campaignId, callable $change, ?int $userId, array $entry): int
    {
        $c = DB::transaction(function () use ($campaignId, $change, $userId, $entry) {
            $c = ChatblastCampaign::whereKey($campaignId)->lockForUpdate()->first();
            if (!$c) throw new InvalidArgumentException('Walang ganitong campaign.');
            $days = $change($c->days);
            if (($error = $this->invalid($days)) !== null) throw new InvalidArgumentException($error);

            $c->days = array_map(fn ($versions) => array_map('trim', $versions), $days);
            self::log($c, $userId, $entry);
            $c->save();

            return $c;
        });

        return $this->rerender($c->kind, $c->days);
    }

    /** Itala ang pagbabago sa log ng campaign (hindi pa sine-save): sino, kailan, ano; ang huling LOG_MAX lang. */
    public static function log(ChatblastCampaign $c, ?int $userId, array $entry): void
    {
        $log = (array) ($c->log ?? []);
        $log[] = ['at' => now()->toIso8601String(), 'by' => $userId] + $entry;
        $c->log = array_slice($log, -self::LOG_MAX);
    }

    /** Ang text: {name}, {item}, {cod}, {waybill}, {page}; {rider} at {rider_phone} ay walang laman hangga't wala pa. */
    public function render(string $template, array $vars): string
    {
        $map = [];
        foreach (self::PLACEHOLDERS as $k) $map['{' . $k . '}'] = trim((string) ($vars[$k] ?? ''));

        return trim(strtr($template, $map));
    }

    /** Ang pangalan ng rider para sa mensahe: walang code sa unahan ("OCW_Juan D. Cruz" → "Juan D. Cruz"). */
    public static function riderName(?string $raw): string
    {
        return trim((string) preg_replace('/^[A-Z0-9_]+_/', '', trim((string) $raw))); // kapareho ng JntScanStatusMapper
    }

    /** Ang phone ng rider para sa mensahe: 09XXXXXXXXX (mula sa 639…, 9…), o walang laman kapag hindi PH mobile. */
    public static function riderPhone(?string $raw): string
    {
        $d = preg_replace('/\D+/', '', (string) $raw);
        if (strlen($d) === 12 && str_starts_with($d, '639')) $d = '0' . substr($d, 2);
        if (strlen($d) === 10 && str_starts_with($d, '9')) $d = '0' . $d;

        return preg_match('/^09\d{9}$/', $d) ? $d : '';
    }


    /**
     * Bago ipadala ang delivering: i-track ulit ang waybill kapag luma na (o wala pa) ang rider data, saka buuin ulit ang
     * text gamit ang bagong rider (maaaring ibang bersyon na). Walang ginagawa kapag bago pa o walang J&T config.
     * Ang status ay nasa from_jnts na rin, kaya ang kasunod na staleFor() ang nagsasabi kung lumipat na ang parcel; ang
     * bigong subok (Problematic, Delivery Fail…) ay hindi isinusulat doon, kaya ibinabalik dito ang dahilan para laktawan.
     * Ang rider ay mula lang sa sagot na ito: kapag walang sagot o hindi mabasa ang rider, walang rider (hindi kailanman ang
     * rider ng mas lumang kuha, na maaaring ibang araw). Kapag walang bersyong magagamit, no_config.
     * @return array{0:string, 1:string}|null [status, dahilan] kapag hindi dapat ipadala; null kapag puwede
     */
    public function refreshRider(BotcakeMessage $m): ?array
    {
        $at = $m->vars['tracked_at'] ?? null;
        if ($at && Carbon::parse($at)->gt(now()->subSeconds(self::RIDER_MAX_AGE))) return null;
        $track = app(JntTracker::class)->refresh([$m->waybill], self::SEND_TRACK_BUDGET, 'chatblast', false);

        if (!$track || !isset($track['latest'][$m->waybill])) {
            if (trim((string) ($m->vars['rider'] ?? '')) === '' && trim((string) ($m->vars['rider_phone'] ?? '')) === '') return null; // walang rider na aalisin
            $vars = array_merge((array) $m->vars, ['rider' => '', 'rider_phone' => '']);
        } else {
            $scan = $track['latest'][$m->waybill];
            if (!self::isOnDelivery($scan)) return ['skipped', 'Hindi na On Delivery ang huling scan ng J&T (' . ($scan ?: 'walang scantype') . ')'];
            [$rider, $phone] = $track['riders'][$m->waybill] ?? ['', ''];
            $vars = array_merge((array) $m->vars, ['rider' => self::riderName($rider), 'rider_phone' => self::riderPhone($phone), 'tracked_at' => now()->toIso8601String()]);
        }

        $days = $this->sequence($m->kind);
        if ($m->day > count($days)) return ['skipped', 'Wala nang Araw ' . $m->day . ' sa mga araw ng uri'];
        $pick = $this->pick($days[$m->day - 1], $m->waybill, $vars);
        if ($pick === null) {
            // Walang bersyong buo kung wala ang rider: hindi ang lumang text (na may lumang rider) ang ipapadala.
            $m->forceFill(['vars' => $vars, 'version' => null, 'text' => null])->save();
            return ['no_config', $this->missingReason($days[$m->day - 1], $vars)];
        }
        $m->forceFill(['vars' => $vars, 'version' => self::label($pick[0]), 'text' => $pick[1]])->save();

        return null;
    }

    private static function isOnDelivery(string $scantype): bool
    {
        return strtolower(trim($scantype)) === 'on delivery';
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
     * "Kunin ang mga bago" para sa isang campaign (kind), o lahat ng naka-on (null): isang row kada waybill na nasa mga
     * status ng campaign at wala pang row ng campaign ngayong araw; sinusuri ulit ang no_psid / no_config, at nilalaktawan
     * ang hindi pa naipapadala na wala na sa mga status ng campaign. Ang naka-off na campaign ay walang binubuo.
     * @return array{added:int, ready:int, no_psid:int, no_config:int, updated:int, skipped:int}
     */
    public function build(?string $kind = null, ?int $userId = null, float $trackBudget = self::TRACK_BUDGET, ?array $steps = null): array
    {
        // Isang pagkuha lang sabay (button o command): ang pangalawa ay sinasabihang may tumatakbo.
        $lock = Cache::lock(self::BUILD_LOCK, 300);
        if (!$lock->get()) return ['busy' => true];

        try {
            return $this->buildLocked($kind, $userId, $trackBudget, $steps);
        } finally {
            $lock->release();
        }
    }

    /** $steps: ang mga step (1 = Day 1) lang na bubuuin (auto: ang may oras ngayon); null = lahat. */
    private function buildLocked(?string $kind, ?int $userId, float $trackBudget, ?array $steps = null): array
    {
        $out = ['added' => 0, 'ready' => 0, 'no_psid' => 0, 'no_config' => 0, 'updated' => 0, 'skipped' => 0, 'tracked' => 0, 'rider_pending' => 0];
        $missing = $this->missingByPage();
        // Ang may "requires On Delivery" muna (ang tracking nila ang pinakamahalaga), saka ayon sa id.
        $all = ChatblastCampaign::where('active', true)->orderByDesc('requires_on_delivery')->orderBy('id')->get(ChatblastCampaign::COLUMNS);
        $campaigns = $kind === null ? $all : $all->where('kind', $kind)->values();
        if ($campaigns->isEmpty()) return $out; // walang ganoon o naka-off: walang binubuo

        // J&T: ang status at rider ng mga waybill na bubuuin at ng hindi pa naipapadala ng campaign na "requires On
        // Delivery", hanggang $trackBudget segundo. Ayos: ang mga campaign na iyon muna; sa bawat campaign, ang
        // pinakamatagal nang hindi na-track muna (ang bago ay hindi pa kailanman), kaya naaabot ang lahat sa ilang pindot.
        // Ang walang PSID, kulang ang settings o ibang page ay hindi tinatawagan. Ang hindi naabot ay "rider pending".
        $waybills = [];
        foreach ($campaigns as $c) {
            $order = [];
            foreach ($this->candidates($c)->reorder()->get(['mo.waybill', 'mo.botcake_psid', 'mo.PAGE as page']) as $o) {
                if (trim((string) $o->botcake_psid) !== '' && $this->onPage($c, (string) $o->page) && !isset($order[$o->waybill])) $order[$o->waybill] = '';
            }
            BotcakeMessage::where('kind', $c->kind)->where('is_test', false)->whereIn('status', ['ready', 'failed', 'field_mismatch'])
                ->where('created_at', '>=', now()->subDays($c->window_days))->get(['waybill', 'vars'])
                ->each(function ($m) use (&$order) { $order[$m->waybill] ??= (string) ($m->vars['tracked_at'] ?? ''); });
            asort($order); // '' (hindi pa na-track) muna, saka ang pinakaluma
            $waybills = array_merge($waybills, array_keys($order));
        }
        $track = app(JntTracker::class)->refresh($waybills, $trackBudget);
        $tracked = $track['latest'] ?? []; // waybill => pinakabagong scan ng J&T (ang mga sinagot lang)
        $riders  = $track['riders'] ?? [];
        $out['tracked'] = count($tracked);
        $out['rider_pending'] = count($track['pending'] ?? []);

        // Ang hindi pa naipapadala ng campaign na "requires On Delivery" na ang bagong sagot ng J&T ay hindi na On Delivery
        // (hal. Problematic, Delivery Fail): nilalaktawan at binibitawan ang key, para may sariling row ang susunod na subok.
        $failed = array_keys(array_filter($tracked, fn ($scan) => !self::isOnDelivery($scan)));
        $onDelivery = $all->where('requires_on_delivery', true)->pluck('kind')->all();
        foreach ($onDelivery ? array_chunk($failed, 500) : [] as $chunk) {
            BotcakeMessage::whereIn('kind', $onDelivery)->where('is_test', false)->whereIn('waybill', $chunk)
                ->whereIn('status', array_diff(self::UNSENT, ['skipped']))->get(['id', 'waybill'])
                ->each(function ($row) use ($tracked, &$out) {
                    $out['skipped'] += BotcakeMessage::whereKey($row->id)->whereIn('status', self::UNSENT)->update([
                        'status' => 'skipped', 'dedupe_key' => null,
                        'reason' => 'Hindi na On Delivery ang huling scan ng J&T (' . ($tracked[$row->waybill] ?: 'walang scantype') . ')',
                    ]);
                });
        }

        foreach ($campaigns as $c) {
            $out['skipped'] += $this->skipStale($c);
            foreach ($this->buildKind($c, $userId, $missing, $tracked, $riders, $steps) as $key => $n) $out[$key] += $n;
            $out['updated'] += $this->recheck($c->kind, $missing);
        }

        return $out;
    }

    private function buildKind(ChatblastCampaign $c, ?int $userId, array $missing, array $tracked = [], array $riders = [], ?array $steps = null): array
    {
        $out   = ['added' => 0, 'ready' => 0, 'no_psid' => 0, 'no_config' => 0];
        $kind  = $c->kind;
        $days  = $this->invalid($c->days) === null ? $c->days : [];
        $now   = now();
        $seen  = [];

        $this->candidates($c)->chunkById(500, function ($orders) use (&$out, &$seen, $days, $missing, $c, $kind, $userId, $now, $tracked, $riders, $steps) {
            $rows = [];
            $jnt  = $this->currentStatuses($orders->pluck('waybill')->unique()->values()->all());
            foreach ($orders as $o) {
                $waybill = trim((string) $o->waybill);
                if ($waybill === '' || isset($seen[$waybill])) continue; // isang row kada waybill (kahit may kopya ang order)
                $seen[$waybill] = true;
                if (!$this->matches($c, $jnt[$o->waybill] ?? [])) continue; // may kopyang mas malayo na ang status

                $day = (int) $o->sent_n + 1;
                if ($day > count($days)) continue; // ubos na ang mga araw ng uri
                if ($steps !== null && !in_array($day, $steps, true)) continue; // ang step na ito ay may ibang oras

                $page = $this->settings->normPage((string) $o->page);
                if (!$this->onPage($c, $page)) continue; // hindi kasama ang page sa campaign
                // Requires On Delivery: ang pinakabagong scan ay dapat On Delivery pa (hindi Problematic, Delivery Fail…):
                // ang "Idedeliver po ngayon" ay hindi totoo pagkatapos ng bigong subok. Susubukan ulit sa susunod na kuha.
                if ($c->requires_on_delivery && isset($tracked[$waybill]) && !self::isOnDelivery($tracked[$waybill])) continue;

                // Ang rider ay mula lang sa sagot ng J&T sa kuhang ito (hindi ang nakatala noon, na maaaring ibang araw).
                [$rider, $riderPhone] = $riders[$waybill] ?? ['', ''];
                $rider = self::riderName($rider);
                $riderPhone = self::riderPhone($riderPhone);
                $vars = ['name' => trim((string) $o->fb_name) ?: trim((string) $o->receiver), 'item' => $o->item, 'cod' => $o->cod, 'waybill' => $waybill, 'page' => $page,
                    'rider' => $rider, 'rider_phone' => $riderPhone, 'tracked_at' => isset($tracked[$waybill]) ? $now->toIso8601String() : null];
                $pick = $this->pick($days[$day - 1], $waybill, $vars);
                [$status, $reason] = $pick === null ? ['no_config', $this->missingReason($days[$day - 1], $vars)]
                    : $this->readiness($o->botcake_psid, $missing[$this->settings->pageKey($page)] ?? self::REQUIRED);

                $rows[] = [
                    'page_name' => $page, 'macro_output_id' => $o->id, 'waybill' => $waybill, 'kind' => $kind, 'campaign_id' => $c->id, 'day' => $day,
                    'version' => $pick ? self::label($pick[0]) : null, 'fb_name' => $o->fb_name, 'psid' => trim((string) $o->botcake_psid) ?: null,
                    'vars' => json_encode($vars, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE), 'text' => $pick[1] ?? null,
                    'jnt_status' => $this->latest($jnt[$o->waybill] ?? [])['status'] ?? null, 'status' => $status, 'reason' => $reason, 'attempts' => 0, 'is_test' => false,
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

    /** Ang hindi pa naipapadala na wala na sa mga status ng campaign (hal. In Transit → Delivered): skipped. @return int */
    private function skipStale(ChatblastCampaign $c): int
    {
        $skipped = 0;
        BotcakeMessage::where('kind', $c->kind)->where('is_test', false)->whereIn('status', array_diff(self::UNSENT, ['skipped']))
            ->select('id', 'waybill', 'page_name')->chunkById(500, function ($rows) use (&$skipped, $c) {
                $now = $this->currentStatuses($rows->pluck('waybill')->unique()->values()->all());
                foreach ($rows as $row) {
                    if (!$this->onPage($c, (string) $row->page_name)) {
                        // Maaaring ibalik ang page sa campaign: binibitawan ang key para may bagong row noon.
                        $skipped += BotcakeMessage::whereKey($row->id)->whereIn('status', self::UNSENT)
                            ->update(['status' => 'skipped', 'reason' => self::pageReason($row->page_name), 'dedupe_key' => null]);
                        continue;
                    }
                    if ($this->matches($c, $now[$row->waybill] ?? [])) continue;
                    $skipped += BotcakeMessage::whereKey($row->id)->whereIn('status', self::UNSENT)
                        ->update(['status' => 'skipped', 'reason' => $this->staleReason($c, $now[$row->waybill] ?? [])]);
                }
            });

        return $skipped;
    }

    /**
     * Ang dahilan kapag hindi na dapat ipadala ng campaign (para sa sender): wala na ang page sa campaign, o ang waybill
     * ay wala na sa mga status nito; null kapag puwede pa.
     */
    public function staleFor(ChatblastCampaign $c, string $waybill, string $page): ?string
    {
        if (!$this->onPage($c, $page)) return self::pageReason($page);
        $rows = $this->currentStatuses([$waybill])[$waybill] ?? [];

        return $this->matches($c, $rows) ? null : $this->staleReason($c, $rows);
    }

    public static function pageReason(string $page): string
    {
        return 'Wala na ang page na ' . $page . ' sa campaign';
    }

    /**
     * Isang mensahe kada waybill kada araw (Manila) para sa parehong J&T status, kahit magkaibang campaign: kapag may
     * mensahe ng ibang campaign sa waybill ngayong araw na naipadala (sent, o unknown: maaaring naipadala) para sa
     * kasalukuyang status ng waybill, ang dahilan; null kapag puwede. Tinatawag sa loob ng lock ng customer, kaya ang
     * naka-queue o nagpapadala pa ay hindi bilang (maaaring mabigo). Iisang page ang mga row ng isang waybill.
     */
    /** Ang kasalukuyang J&T status ng waybill (ang pinakamalayong from_jnts row), o null. */
    public function currentStatus(string $waybill): ?string
    {
        return $this->latest($this->currentStatuses([$waybill])[$waybill] ?? [])['status'] ?? null;
    }

    public function sentByOtherCampaign(BotcakeMessage $m): ?string
    {
        $status = $this->currentStatus($m->waybill);
        if ($status === null) return null;
        $today = Carbon::now('Asia/Manila')->startOfDay()->timezone(config('app.timezone'));
        $sent = BotcakeMessage::where('waybill', $m->waybill)->where('kind', '<>', $m->kind)->where('is_test', false)->where('jnt_status', $status)
            ->where(fn ($w) => $w->where(fn ($s) => $s->where('status', 'sent')->where('sent_at', '>=', $today))
                ->orWhere(fn ($u) => $u->where('status', 'unknown')->where('updated_at', '>=', $today)))
            ->exists();

        return $sent ? self::CROSS : null;
    }

    private function staleReason(ChatblastCampaign $c, array $rows): string
    {
        $top = $this->latest($rows);
        return 'Hindi na ' . implode('/', (array) $c->statuses) . ' ang waybill (ngayon: ' . ($top['status'] ?? 'wala sa J&T') . ')';
    }

    /**
     * Nasa mga status ng campaign ba ang waybill: ang pinakamalayong from_jnts row nito ay isa sa mga status ng campaign
     * (ang In Transit at Delivering ay walang RTS reason).
     */
    private function matches(ChatblastCampaign $c, array $rows): bool
    {
        $top = $this->latest($rows);
        return $top !== null && in_array($top['status'], (array) $c->statuses, true)
            && (!in_array($top['status'], self::RTS_EXCLUDED, true) || trim((string) $top['rts_reason']) === '');
    }

    /** Kasama ba ang page sa campaign (walang listahan = lahat ng page). */
    public function onPage(ChatblastCampaign $c, string $page): bool
    {
        if (!$c->pages) return true;
        $key = $this->settings->pageKey($page);
        foreach ($c->pages as $p) {
            if ($this->settings->pageKey((string) $p) === $key) return true;
        }
        return false;
    }

    /** Ang pinakamalayong J&T row (PROGRESS; kapag pantay, ang pinakahuling naitala). */
    private function latest(array $rows): ?array
    {
        $top = null;
        foreach ($rows as $r) {
            if ($top === null || self::rank($r['status']) >= self::rank($top['status'])) $top = $r;
        }
        return $top;
    }

    /**
     * Ang status na wala sa PROGRESS (hal. Problematic, o anumang isinulat ng upload) ay kapantay ng In Transit: sa
     * pagitan nila, ang pinakabagong kopya ang nananaig (hindi ang lumang "In Transit", at hindi rin ang lumang status na
     * mas maaga pa). Walang status: pinakamababa.
     */
    private static function rank(?string $status): int
    {
        if (trim((string) $status) === '') return 0;
        return self::PROGRESS[$status] ?? self::PROGRESS['In Transit'];
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
     * Ang mga order na nasa mga status ng campaign sa loob ng window, na wala pang row ng campaign ngayong araw (Manila),
     * kasama kung ilan na ang naipadala (sent o unknown: maaaring naipadala) para sa waybill.
     */
    private function candidates(ChatblastCampaign $c)
    {
        $kind  = $c->kind;
        $since = now('Asia/Manila')->subDays($c->window_days)->toDateString();
        $col   = fn (string $c) => DB::getDriverName() === 'pgsql' ? 'mo."' . $c . '"' : 'mo.`' . $c . '`';
        $today = Carbon::now('Asia/Manila')->startOfDay()->timezone(config('app.timezone'));

        // Pinakamababang id ng window (index ng ts_date), para hindi magsimula sa id 1 ang paglakad ng chunk.
        $fromId = (int) DB::table('macro_output')->where('ts_date', '>=', $since)->min('id');

        return DB::table('macro_output as mo')
            ->where('mo.id', '>=', $fromId)
            ->where('mo.ts_date', '>=', $since)
            ->whereNotNull('mo.waybill')->where('mo.waybill', '<>', '')
            ->whereExists(fn ($e) => $e->from('from_jnts as j')->whereColumn('j.waybill_number', 'mo.waybill')->whereIn('j.status', (array) $c->statuses)
                ->where(fn ($r) => $r->whereNotIn('j.status', self::RTS_EXCLUDED)->orWhereNull('j.rts_reason')->orWhereRaw("TRIM(j.rts_reason) = ''"))->selectRaw('1'))
            ->whereNotExists(fn ($e) => $e->from('botcake_messages as m')->whereColumn('m.waybill', 'mo.waybill')->where('m.kind', $kind)
                ->where('m.is_test', false)
                // Ang itinabi dahil nagbago ang PSID/page ay hindi bilang; ang nilaktawan dahil may naipadala na ang ibang
                // campaign ngayong araw ay bilang (walang bagong row hanggang bukas).
                ->where(fn ($k) => $k->whereNotNull('m.dedupe_key')->orWhere('m.reason', self::CROSS))
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
