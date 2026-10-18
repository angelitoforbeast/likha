<?php

namespace App\Services;

use App\Models\BotcakeMessage;
use App\Models\ChatblastCampaign;
use App\Models\ChatblastMessage;
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
    public const CROSS = 'already sent today by another sequence';
    public const CAMPAIGN_CAP = 'Sequence daily cap reached for the page';
    // Ang dating (Tagalog) na dahilan ng mga row bago ang English: tinatanggap pa rin ng mga paghahambing.
    public const LEGACY_CROSS = 'may naipadala na ngayong araw sa ibang campaign';
    public const LEGACY_CAMPAIGN_CAP = 'Naabot ang daily cap ng campaign sa page';
    public const LEGACY_RIDER_REASON = 'walang rider info';
    public const LEGACY_NO_PSID = 'walang PSID ang order (hindi pa tugma sa Botcake customer)';
    public const NO_PSID = 'the order has no PSID (not matched to a Botcake customer yet)';

    /** Ang kailangan ng page para makapagpadala (page_id para sa bawat API path). */
    public const REQUIRED = ['page_id', 'token', 'flow.shipping', 'field.message'];

    /** Ang mga status na hindi pa naipapadala: dito lang nagbabago ang text kapag binago ang mga araw. */
    public const UNSENT = ['ready', 'no_psid', 'no_config', 'failed', 'field_mismatch', 'skipped'];

    public const PLACEHOLDERS = ['name', 'item', 'cod', 'waybill', 'page', 'rider', 'rider_phone'];
    public const RIDER_REASON = 'no rider info';
    public const TRACK_BUDGET = 60;        // segundo ng J&T tracking ng chatblast:build (ang web build: 20, tingnan ang controller)
    public const SEND_TRACK_BUDGET = 10;   // segundo ng J&T bago ipadala: isang tawag
    public const BUILD_LOCK = 'chatblast-build';
    public const BUILT_AT = 'chatblast-built-at';           // oras (timestamp) ng huling buong pagkuha
    public const REFRESH_SLOT = 'chatblast-refresh-slot';   // ang oras ("Y-m-d H", Manila) na nagawa na ang kusang refresh
    public const REFRESH_FIRST_HOUR = 6;                    // kusang refresh: 06:15 hanggang 23:15
    public const REFRESH_MINUTE = 15;
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
        if (!is_array($days) || !array_is_list($days) || !$days) return 'At least one day.';
        if (count($days) > self::MAX_DAYS) return 'At most ' . self::MAX_DAYS . ' days.';
        foreach ($days as $i => $versions) {
            $n = $i + 1;
            if (!is_array($versions) || !array_is_list($versions) || !$versions) return 'Day ' . $n . ': at least one version.';
            if (count($versions) > self::MAX_VERSIONS) return 'Day ' . $n . ': at most ' . self::MAX_VERSIONS . ' versions.';
            foreach ($versions as $j => $text) {
                $label = 'Day ' . $n . ' · ' . self::label($j);
                if (!is_string($text) || trim($text) === '') return $label . ': the text is empty.';
                if (mb_strlen($text) > self::MAX_TEXT) return $label . ': at most ' . self::MAX_TEXT . ' characters.';
                preg_match_all('/\{([^{}]*)\}/', $text, $m); // anumang {…}: ang may maling baybay ay hindi lalabas nang ganoon sa customer
                $unknown = array_diff($m[1], self::PLACEHOLDERS);
                if ($unknown) return $label . ': unknown placeholder {' . reset($unknown) . '}.';
            }
        }
        if (strlen((string) json_encode($days, JSON_UNESCAPED_UNICODE)) > self::MAX_TOTAL) { // bytes: ang TEXT ay 65,535 bytes
            return 'All texts together are too long (at most ' . number_format(self::MAX_TOTAL) . ' bytes).';
        }

        return null;
    }

    /**
     * Bagong mga araw ng campaign (buo), sa mga mensahe ng mga step nito: ang mensahe ng step N ay pinapalitan ang mga
     * bersyon (o bagong mensahe "<campaign> · Day N" kapag wala pa), at ang ibang sequence na gumagamit nito ay binubuo
     * ulit. Para sa mga patakaran ng araw at bersyon (ang page ay nag-e-edit kada mensahe). @return int nabagong row
     */
    public function setSequence(string $kind, mixed $days, ?int $userId = null): int
    {
        $c = $this->campaign($kind);
        if (!$c) throw new InvalidArgumentException('Unknown sequence.');
        if (($error = $this->invalid($days)) !== null) throw new InvalidArgumentException($error);

        $ids = DB::transaction(function () use ($c, $days, $userId) {
            $ids = [];
            foreach ($days as $i => $versions) {
                $m = isset(($c->step_messages ?? [])[$i]) ? ChatblastMessage::whereKey($c->step_messages[$i])->lockForUpdate()->first() : null;
                $m ??= new ChatblastMessage(['name' => $this->freeName($c->name . ' · Day ' . ($i + 1))]);
                $m->versions = array_map('trim', $versions);
                self::log($m, $userId, ['what' => 'versions']);
                $m->save();
                $ids[] = $m->id;
            }
            return $ids;
        });
        $changed = $this->editSteps($c->id, fn () => [$ids, []], $userId, ['what' => 'days']);

        return $changed + $this->resync($ids, $c->id);
    }

    /** Isang pangalan ng mensahe na wala pa ("X", o "X (2)", …). */
    public function freeName(string $base): string
    {
        $name = mb_substr(trim($base), 0, 140);
        for ($n = 2; ChatblastMessage::where('name', $name)->exists(); $n++) $name = mb_substr(trim($base), 0, 140) . ' (' . $n . ')';

        return $name;
    }

    /**
     * Baguhin ang mga bersyon ng isang mensahe: $change(lumang bersyon) → bagong bersyon (InvalidArgumentException kapag
     * hindi puwede). Naka-lock ang mensahe habang binabago; tinatanggal ang espasyo sa dulo; itinatala sa log. Pagkatapos,
     * ang bawat sequence na gumagamit nito ay binubuo ulit (ang days nito at ang text ng hindi pa naipapadala).
     * @return int bilang ng nabagong row
     */
    public function editMessage(int $messageId, callable $change, ?int $userId, array $entry): int
    {
        DB::transaction(function () use ($messageId, $change, $userId, $entry) {
            $m = ChatblastMessage::whereKey($messageId)->lockForUpdate()->first();
            if (!$m) throw new InvalidArgumentException('No such message.');
            $versions = $change((array) $m->versions);
            if (($error = $this->invalid([$versions])) !== null) throw new InvalidArgumentException(str_replace('Day 1', 'Message', $error));
            // Ang bawat sequence na gagamit nito ay dapat kasya pa rin (hal. ang laki ng lahat ng text).
            foreach ($this->usersOf([$messageId]) as $c) {
                $days = $this->resolve((array) $c->step_messages, [$messageId => array_map('trim', $versions)]);
                if (($error = $this->invalid($days)) !== null) throw new InvalidArgumentException($c->name . ': ' . $error);
            }
            $m->versions = array_map('trim', $versions);
            self::log($m, $userId, $entry);
            $m->save();
        });

        return $this->resync([$messageId]);
    }

    /** Ang mga sequence na gumagamit ng alinman sa mga mensaheng ito (maliit ang table: sinasala dito). */
    public function usersOf(array $messageIds): \Illuminate\Support\Collection
    {
        return ChatblastCampaign::whereNotNull('step_messages')->orderBy('id')->get(ChatblastCampaign::COLUMNS)
            ->filter(fn ($c) => array_intersect(array_map('intval', (array) $c->step_messages), array_map('intval', $messageIds)))->values();
    }

    /**
     * Ang mga araw ng mga step: ang mga bersyon ng mensahe ng bawat step ($override: mensahe => bersyon na hindi pa
     * naka-save). InvalidArgumentException kapag wala ang mensahe.
     */
    public function resolve(array $messageIds, array $override = [], bool $lock = false): array
    {
        $q = ChatblastMessage::whereIn('id', $messageIds);
        if ($lock) $q->lockForUpdate(); // hindi mababago o maaalis habang binubuo ang days ng sequence
        $found = $q->get(['id', 'versions'])->keyBy('id');
        return array_map(function ($id) use ($found, $override) {
            if (array_key_exists((int) $id, $override)) return $override[(int) $id];
            if (!isset($found[(int) $id])) throw new InvalidArgumentException('No such message (#' . (int) $id . ').');
            return (array) $found[(int) $id]->versions;
        }, array_values($messageIds));
    }

    /** Buuin ulit ang days ng mga sequence na gumagamit ng mga mensaheng ito, at ang text ng hindi pa naipapadala. */
    private function resync(array $messageIds, ?int $exceptCampaign = null): int
    {
        $changed = 0;
        foreach ($this->usersOf($messageIds) as $c) {
            if ($c->id === $exceptCampaign) continue;
            $changed += $this->editSteps($c->id, fn (array $ids, array $times) => [$ids, $times], null, ['what' => 'message_changed', 'messages' => array_values($messageIds)], false);
        }

        return $changed;
    }

    /**
     * Baguhin ang mga step ng sequence: $change(mga id ng mensahe, mga sariling oras) → [bagong mga id, bagong oras]; ang
     * oras ay listahan na kasinghaba ng mga step, bawat isa null (ang oras ng sequence) o mga "HH:MM". Naka-lock ang
     * sequence; ang days ay binubuo mula sa mga mensahe; ang text ng hindi pa naipapadala ay binubuo ulit. Kapag auto at
     * may bagong oras na lumipas na ngayong araw, itinatala itong tumakbo na (hindi ito tatakbo). @return int nabagong row
     */
    public function editSteps(int $campaignId, callable $change, ?int $userId, array $entry, bool $log = true): int
    {
        $c = DB::transaction(function () use ($campaignId, $change, $userId, $entry, $log) {
            $c = ChatblastCampaign::whereKey($campaignId)->lockForUpdate()->first();
            if (!$c) throw new InvalidArgumentException('No such sequence.');
            $before = clone $c;
            $ids = array_values((array) ($c->step_messages ?? []));
            $times = array_pad(array_slice((array) ($c->step_times ?? []), 0, count($ids)), count($ids), null);
            [$ids, $times] = $change($ids, $times);
            $ids = array_map('intval', array_values((array) $ids));
            if (!$ids) throw new InvalidArgumentException('At least one step.');
            if (count($ids) > self::MAX_DAYS) throw new InvalidArgumentException('At most ' . self::MAX_DAYS . ' steps.');
            $days = $this->resolve($ids, [], true);
            if (($error = $this->invalid($days)) !== null) throw new InvalidArgumentException($error);
            $times = array_pad(array_slice(array_values((array) $times), 0, count($ids)), count($ids), null);
            foreach ($times as $i => $t) {
                if (($error = self::invalidTimes($t)) !== null) throw new InvalidArgumentException('Day ' . ($i + 1) . ': ' . $error);
                if (is_array($t)) { $t = array_values(array_unique($t)); sort($t); }
                $times[$i] = $t ?: null;
            }

            $c->step_messages = $ids;
            $c->days = $days;
            $c->step_times = array_filter($times) ? $times : null;
            app(ChatblastSchedule::class)->stampNewPastTimes($before, $c);
            if ($log) self::log($c, $userId, $entry);
            $c->save();

            return $c;
        });

        return $this->rerender($c->kind, $c->days);
    }

    /** Ang mali sa listahan ng oras (null = wala, ang oras ng campaign), o null kapag tama: hanggang 6, "HH:MM". */
    public static function invalidTimes(mixed $times): ?string
    {
        if ($times === null) return null;
        if (!is_array($times) || !array_is_list($times) || count($times) > 6) return 'at most 6 times.';
        foreach ($times as $t) {
            if (!is_string($t) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) return 'a time is HH:MM (00:00 to 23:59).';
        }
        return null;
    }

    /** Itala ang pagbabago sa log ng sequence o mensahe (hindi pa sine-save): sino, kailan, ano; ang huling LOG_MAX lang. */
    public static function log(\Illuminate\Database\Eloquent\Model $c, ?int $userId, array $entry): void
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
            if (!self::isOnDelivery($scan)) return ['skipped', 'The latest J&T scan is no longer On Delivery (' . ($scan ?: 'no scantype') . ')'];
            [$rider, $phone] = $track['riders'][$m->waybill] ?? ['', ''];
            $vars = array_merge((array) $m->vars, ['rider' => self::riderName($rider), 'rider_phone' => self::riderPhone($phone), 'tracked_at' => now()->toIso8601String()]);
        }

        $days = $this->sequence($m->kind);
        if ($m->day > count($days)) return ['skipped', 'Day ' . $m->day . ' no longer exists in the sequence'];
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

        return $other ? 'missing {' . $other[0] . '}' : self::RIDER_REASON;
    }

    /** Dahilan na galing sa kulang na laman ng text (hindi sa PSID o settings): hindi ito binabago ng recheck. */
    public static function isTextReason(?string $reason): bool
    {
        if (in_array($reason, [self::RIDER_REASON, self::LEGACY_RIDER_REASON], true)) return true;
        foreach (['missing {', 'incomplete text', 'walang {', 'hindi buo ang text'] as $prefix) { // English, at ang dating Tagalog
            if (str_starts_with((string) $reason, $prefix)) return true;
        }
        return false;
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
    public function build(?string $kind = null, ?int $userId = null, float $trackBudget = self::TRACK_BUDGET, ?array $steps = null, ?string $lockOwner = null): array
    {
        // Isang pagkuha lang sabay (button o command): ang pangalawa ay sinasabihang may tumatakbo. $lockOwner: para
        // mabitawan ng job ang sarili nitong lock kapag pinatay ito (timeout), at hindi ang lock ng iba.
        $lock = Cache::lock(self::BUILD_LOCK, 300, $lockOwner);
        if (!$lock->get()) return ['busy' => true];

        try {
            $out = $this->buildLocked($kind, $userId, $trackBudget, $steps);
            if ($kind === null) Cache::forever(self::BUILT_AT, now()->timestamp); // buong pagkuha: "Updated HH:MM" ng To send
            return $out;
        } finally {
            $lock->release();
        }
    }

    /**
     * Ang kusang refresh ng To send (tinatawag ng chatblast:auto kada 5 minuto, Likha at Incepxion): isang buong pagkuha
     * kada oras sa :15 (mga 10 minuto pagkatapos ng hourly J&T upload), 06:15–23:15 Manila. Hindi nagpapadala. May
     * tumatakbong pagkuha: laktaw, at susubok ulit sa susunod na takbo sa parehong oras. Null kapag hindi pa oras.
     */
    public function refreshIfDue(): ?array
    {
        $now = Carbon::now('Asia/Manila');
        if ($now->hour < self::REFRESH_FIRST_HOUR || $now->minute < self::REFRESH_MINUTE) return null;
        $slot = $now->format('Y-m-d H');
        if (Cache::get(self::REFRESH_SLOT) === $slot) return null;

        $r = $this->build(null, null, self::TRACK_BUDGET);
        if (empty($r['busy'])) Cache::put(self::REFRESH_SLOT, $slot, 7200);

        return $r;
    }

    /** Para sa To send: kailan ang huling buong pagkuha at ang susunod na kusang refresh (HH:MM, Manila). */
    public function refreshState(): array
    {
        $now  = Carbon::now('Asia/Manila');
        $next = $now->copy()->setTime($now->hour, self::REFRESH_MINUTE);
        if ($now->minute >= self::REFRESH_MINUTE) $next->addHour();
        if ($next->hour < self::REFRESH_FIRST_HOUR || !$next->isSameDay($now)) {
            $next = ($next->isSameDay($now) ? $next : $next->startOfDay())->setTime(self::REFRESH_FIRST_HOUR, self::REFRESH_MINUTE);
        }
        $at = Cache::get(self::BUILT_AT);

        return ['updated' => $at ? Carbon::createFromTimestamp($at, 'Asia/Manila')->format('H:i') : null, 'next' => $next->format('H:i')];
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
            // Ang pinakabagong order muna (stable ang asort sa ibaba): kapag ubos ang oras, ang pinakaluma ang hindi naabot.
            foreach ($this->candidates($c)->reorder()->orderByDesc('mo.id')->get(['mo.waybill', 'mo.botcake_psid', 'mo.PAGE as page']) as $o) {
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
                        'reason' => 'The latest J&T scan is no longer On Delivery (' . ($tracked[$row->waybill] ?: 'no scantype') . ')',
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
        if ($missingKeys) return ['no_config', 'missing ' . implode(', ', $missingKeys)];
        if (trim((string) $psid) === '') return ['no_psid', self::NO_PSID];

        return ['ready', null];
    }

    /** Ang dahilan ng readiness() sa dating anyo nito (Tagalog, bago ang English), o null kapag walang dating anyo. */
    private static function legacyReadiness(?string $reason): ?string
    {
        if ($reason === self::NO_PSID) return self::LEGACY_NO_PSID;
        if (str_starts_with((string) $reason, 'missing ') && !str_starts_with((string) $reason, 'missing {')) return 'walang ' . substr($reason, 8);

        return null;
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
                    // Ang dating (Tagalog) na anyo ng parehong dahilan ay hindi pagbabago: hindi isinusulat ulit, hindi bilang.
                    $sameReason = $reason === $row->reason || self::legacyReadiness($reason) === $row->reason;
                    if ($status === $row->status && $sameReason && $psid === $row->psid) continue;
                    $changed += BotcakeMessage::whereKey($row->id)->whereIn('status', ['no_psid', 'no_config'])
                        ->update(['status' => $status, 'reason' => $reason, 'psid' => $psid]);
                }
            });

        return $changed;
    }

    /**
     * Sinusuri ulit sa bawat pagkuha ang LAHAT ng hindi pa naipapadala ng campaign, anuman ang petsa ng pagbuo: ang wala
     * na sa page o sa mga status ng campaign (hal. In Transit → Delivered) ay skipped at binibitawan ang key; ang ready na
     * pasado pa ay tinatatakan (updated_at = ngayon) bilang nasuri. Ang auto ay nagpapadala lang ng nabuo o nasuri
     * pagkatapos i-auto (ang updated_at ay binabasa lang ng ibang patakaran sa unknown at sending, hindi sa ready).
     * @return int ilan ang na-skip
     */
    private function skipStale(ChatblastCampaign $c): int
    {
        $skipped = 0;
        // Ang window ng campaign ayon sa petsa ng order (ts_date), gaya ng candidates(): ang lumang order ay hindi na
        // pinapadalhan, kahit In Transit pa sa J&T (ang lumang row ay may pinakamatagal nang hindi na-track na status).
        $since = now('Asia/Manila')->subDays($c->window_days)->toDateString();
        BotcakeMessage::where('kind', $c->kind)->where('is_test', false)->whereIn('status', array_diff(self::UNSENT, ['skipped']))
            ->select('id', 'waybill', 'page_name', 'status', 'macro_output_id', 'created_at')->chunkById(500, function ($rows) use (&$skipped, $c, $since) {
                $now = $this->currentStatuses($rows->pluck('waybill')->unique()->values()->all());
                $orderDates = DB::table('macro_output')->whereIn('id', $rows->pluck('macro_output_id')->filter()->unique()->values()->all())->pluck('ts_date', 'id');
                $valid = [];
                foreach ($rows as $row) {
                    if (!$this->onPage($c, (string) $row->page_name)) {
                        // Maaaring ibalik ang page sa campaign: binibitawan ang key para may bagong row noon.
                        $skipped += BotcakeMessage::whereKey($row->id)->whereIn('status', self::UNSENT)
                            ->update(['status' => 'skipped', 'reason' => self::pageReason($row->page_name), 'dedupe_key' => null]);
                        continue;
                    }
                    // Walang order (hal. nabura): ang petsa ng pagbuo ang batayan.
                    $orderDate = substr((string) ($orderDates[$row->macro_output_id] ?? $row->created_at?->timezone('Asia/Manila')->toDateString()), 0, 10);
                    if ($orderDate !== '' && $orderDate < $since) {
                        $skipped += BotcakeMessage::whereKey($row->id)->whereIn('status', self::UNSENT)
                            ->update(['status' => 'skipped', 'reason' => 'The order is older than the sequence\'s ' . $c->window_days . '-day window', 'dedupe_key' => null]);
                        continue;
                    }
                    if ($this->matches($c, $now[$row->waybill] ?? [])) {
                        if ($row->status === 'ready') $valid[] = $row->id;
                        continue;
                    }
                    // Binibitawan din ang key: kapag bumalik ang waybill sa status, may sariling bagong row ito.
                    $skipped += BotcakeMessage::whereKey($row->id)->whereIn('status', self::UNSENT)
                        ->update(['status' => 'skipped', 'reason' => $this->staleReason($c, $now[$row->waybill] ?? []), 'dedupe_key' => null]);
                }
                if ($valid) DB::table('botcake_messages')->whereIn('id', $valid)->where('status', 'ready')->update(['updated_at' => now()]);
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
        return 'Page "' . $page . '" is no longer in the sequence';
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
        return 'The waybill is no longer ' . implode('/', (array) $c->statuses) . ' (now: ' . ($top['status'] ?? 'not in J&T') . ')';
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
        if ($m->day > count($this->sequence($m->kind))) return 'Day ' . $m->day . ' no longer exists in the sequence';

        $today = Carbon::now('Asia/Manila')->startOfDay()->timezone(config('app.timezone'));
        $other = BotcakeMessage::where('waybill', $m->waybill)->where('kind', $m->kind)->where('is_test', false)->whereKeyNot($m->id)
            ->where(fn ($w) => $w->where(fn ($s) => $s->where('status', 'sent')->where('sent_at', '>=', $today))
                ->orWhere(fn ($u) => $u->where('status', 'unknown')->where('updated_at', '>=', $today))
                ->orWhere(fn ($x) => $x->where('status', 'sending')->where('updated_at', '>=', now()->subMinutes(10))))
            ->exists();

        // Kada waybill ang patakaran, hindi kada customer: ang customer na may dalawang waybill ay may tig-isang mensahe
        // ang bawat waybill, kahit parehong uri sa parehong araw (sunod-sunod, dahil sa lock ng customer sa sender).
        return $other ? 'This sequence already sent a message to the waybill today; send it tomorrow' : null;
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
                        $set = ['status' => 'skipped', 'reason' => 'Day ' . $row->day . ' no longer exists in the sequence'];
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
                ->where(fn ($k) => $k->whereNotNull('m.dedupe_key')->whereIn('m.reason', [self::CROSS, self::LEGACY_CROSS], 'or')
                    ->orWhere('m.reason', 'like', self::CAMPAIGN_CAP . '%')->orWhere('m.reason', 'like', self::LEGACY_CAMPAIGN_CAP . '%'))
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
