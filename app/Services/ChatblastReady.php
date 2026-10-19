<?php

namespace App\Services;

use App\Models\BotcakeMessage;
use App\Models\PageSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kung Ready ang page para sa chatblast. Hindi Ready kapag ang huling setup check (ChatblastSetupCheck) ay may X (walang
 * o maraming flow o field na may pangalan ng Setup box, o mali ang template), o kapag ang delivered check ay "Sent N,
 * none delivered". Kung wala, Ready kapag:
 * - may kumpirmadong padala pagkatapos ng huling pagbabago ng setup nito (Botcake ID, token, Chatblast flow o message
 *   field): isang matagumpay na Send test (botcake.test_ok, isinusulat ng sender kapag ang test ay naging sent) o isang
 *   totoong naipadalang chatblast (mula sa botcake_messages; walang itinatabi para rito); o
 * - ang setup check ang sumulat ng flow at field (system) at pumasa ito pagkatapos ng huling pagbabago: walang Send test.
 * Ang pagbabago ng setup ng tao (hal. ibang token) ay ginagawang mas luma ang dating test, kaya kailangan ulit ng test;
 * walang binubura. Pagkatapos ng pumasang check, ang flow o field na iba sa nahanap nito ay hindi Ready kahit may test
 * (ang page ay sa flow ng Setup box lang nagpapadala). Ang check na hindi naabot ang Botcake ay hindi binibilang (naiiwan ang dating resulta).
 */
class ChatblastReady
{
    public const NOT_TESTED = 'Page not ready: needs a test send';
    public const SAMPLE_TEXT = 'Test message from Chatblast: if you see this, the page is set up.';

    /** Ang setup na kapag nagbago ay kailangan ulit ng test. */
    public const CONFIG = ['page_id', 'token', 'flow.shipping', 'field.message'];

    /** Ang naitala ng setup check at ng delivered check (botcake.setup.check, botcake.setup.delivery). */
    public const CHECK_KEYS = [ChatblastSetupCheck::CHECK_KEY, ChatblastSetupCheck::DELIVERY_KEY];

    /** Segundo ng cache ng tested() kada page (tinatanong ng sender sa bawat totoong padala). */
    private const CACHE_SECONDS = 60;

    public function __construct(private PageSettings $settings)
    {
    }

    /**
     * Ang kalagayan ng bawat page na may alinman sa setup: tatlong query lang (settings, test_ok, huling totoong padala).
     * @return array<string, array{changed: ?string, test_ok: ?string, last_send: ?string, tested: bool, blocker: ?string}> pageKey => ...
     */
    public function states(): array
    {
        $out = [];
        PageSetting::where('active', true)->where('provider', 'botcake')->whereIn('key', [...self::CONFIG, 'test_ok', ...self::CHECK_KEYS])
            ->where('page_name', '!=', ChatblastSetupCheck::ALL_PAGE)
            ->orderBy('id')->get(['page_name', 'key', 'value', 'created_at', 'created_by'])
            ->each(function ($s) use (&$out) {
                $pk = $this->settings->pageKey($s->page_name);
                $out[$pk] ??= self::blank();
                self::add($out[$pk], $s);
            });
        if (!$out) return [];

        // Wala pang botcake_messages (hal. bago ang migrate ng chatblast): walang totoong padala. Ang oras ng totoong padala
        // ay ang pagka-queue nito (bago basahin ng sender ang setup), hindi ang pagkatapos: ang ipinadala habang nagbabago
        // ang setup ay hindi bilang para sa bagong setup.
        $sent = !Schema::hasTable('botcake_messages') ? collect() : BotcakeMessage::where('status', 'sent')->where('is_test', false)->whereNotNull('queued_at')
            ->selectRaw('page_name, MAX(queued_at) AS last')->groupBy('page_name')->get();
        foreach ($sent as $row) {
            $pk = $this->settings->pageKey((string) $row->page_name);
            if (!isset($out[$pk])) continue;
            $last = Carbon::parse($row->last)->format('Y-m-d H:i:s');
            $out[$pk]['last_send'] = max($out[$pk]['last_send'] ?? '', $last);
        }

        foreach ($out as $pk => $s) {
            $out[$pk]['blocker'] = self::blocker($s);
            $out[$pk]['tested'] = $out[$pk]['blocker'] === null;
            unset($out[$pk]['check'], $out[$pk]['delivery'], $out[$pk]['system'], $out[$pk]['ids']);
        }

        return $out;
    }

    /**
     * Para sa isang page (sender, Send test). Naka-cache nang 60 s kada page: ang MAX(queued_at) ay sumusuri sa mga
     * naipadalang row sa bawat padala. Binubura ng PageSettings ang cache kapag nagbago ang setup, ang test_ok o ang check.
     */
    public function tested(string $page): bool
    {
        return $this->reason($page) === null;
    }

    /** Bakit hindi Ready ang page (ang ipinapakita sa nilaktawang padala), o null kapag Ready. */
    public function reason(string $page): ?string
    {
        $v = Cache::remember(self::cacheKey($this->settings->pageKey($page)), self::CACHE_SECONDS, fn () => ['reason' => $this->fresh($page)]);

        return is_array($v) ? $v['reason'] : ($v ? null : self::NOT_TESTED);
    }

    /** Ang cache ng page: binubura kapag nagbago ang alinman sa CONFIG, ang test_ok o ang check nito. */
    public static function forget(string $pageKey): void
    {
        Cache::forget(self::cacheKey($pageKey));
    }

    public static function watches(string $provider, string $key): bool
    {
        return $provider === 'botcake' && in_array($key, [...self::CONFIG, 'test_ok', ...self::CHECK_KEYS], true);
    }

    private static function cacheKey(string $pageKey): string
    {
        return 'chatblast:ready:' . sha1($pageKey);
    }

    private function fresh(string $page): ?string
    {
        if ($this->settings->pageKey($page) === ChatblastSetupCheck::ALL_PAGE) return self::NOT_TESTED;
        $rows = $this->settings->pageQuery($page)->where('active', true)->where('provider', 'botcake')
            ->whereIn('key', [...self::CONFIG, 'test_ok', ...self::CHECK_KEYS])->orderBy('id')->get(['key', 'value', 'created_at', 'created_by']);
        $s = self::blank();
        foreach ($rows as $r) self::add($s, $r);
        if ($s['changed'] === null) return self::NOT_TESTED;

        // Ayon sa pageKey (gaya ng states() at ng build): ang page_name ng row ay ang baybay ng PAGE sa order, na maaaring
        // iba-iba (hal. "Page Uno" at "PAGE UNO"); ang eksaktong tugma ay magpapaikot sa row (ready → tinanggihan → ready).
        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        $last = !Schema::hasTable('botcake_messages') ? null : BotcakeMessage::where('status', 'sent')->where('is_test', false)->whereNotNull('queued_at')
            ->whereRaw("LOWER($trim(page_name)) = ?", [$this->settings->pageKey($page)])->max('queued_at');
        $s['last_send'] = $last ? Carbon::parse($last)->format('Y-m-d H:i:s') : null;

        return self::blocker($s);
    }

    private static function blank(): array
    {
        return ['changed' => null, 'test_ok' => null, 'last_send' => null, 'tested' => false, 'blocker' => null, 'check' => null, 'delivery' => null, 'system' => [], 'ids' => []];
    }

    /** Isang active na row sa kalagayan ng page. */
    private static function add(array &$s, $row): void
    {
        $at = (string) $row->created_at?->format('Y-m-d H:i:s');
        if ($row->key === 'test_ok') { $s['test_ok'] = max($s['test_ok'] ?? '', $at); return; }
        if ($row->key === ChatblastSetupCheck::CHECK_KEY) { $s['check'] = json_decode((string) $row->value, true); return; }
        if ($row->key === ChatblastSetupCheck::DELIVERY_KEY) { $s['delivery'] = json_decode((string) $row->value, true); return; }
        $s['changed'] = max($s['changed'] ?? '', $at);
        // Ang flow at field: kung system (ang setup check) ang sumulat, at ang kasalukuyang ID.
        if (in_array($row->key, ['flow.shipping', 'field.message'], true)) {
            $s['system'][$row->key] = $row->created_by === null;
            $s['ids'][$row->key] = trim((string) $row->value);
        }
    }

    /** Ang dahilan kung bakit hindi Ready (X ng check, hindi delivered, o kailangan ng test), o null. */
    private static function blocker(array $s): ?string
    {
        $check = is_array($s['check']) && isset($s['check']['ok']) ? $s['check'] : null;
        if ($check && !$check['ok']) return 'Page not ready: ' . (((array) ($check['x'] ?? []))[0] ?? 'the setup check failed');
        // Pagkatapos ng pumasang check: ang flow at field ay dapat ang nahanap nito (hindi ibang flow ng tao, kahit may test).
        if ($check && $check['ok']) {
            $names = (array) ($check['names'] ?? []);
            foreach (['flow.shipping' => ['flow', 'flow', 'flow'], 'field.message' => ['field', 'field', 'custom field']] as $key => [$what, $label, $kind]) {
                $found = (string) ($check[$what]['id'] ?? '');
                if ($found !== '' && ($s['ids'][$key] ?? '') !== $found) {
                    return 'Page not ready: the ' . $label . ' ID is not the ' . $kind . ' named ' . ($names[$what] ?? '') . ' (press Test to set it)';
                }
            }
        }
        $d = is_array($s['delivery']) ? $s['delivery'] : null;
        if ($d && ($d['status'] ?? '') === 'not_delivered' && (string) ($d['flow_id'] ?? '') === ($s['ids']['flow.shipping'] ?? '')) return 'Page not ready: Sent ' . (int) ($d['sent'] ?? 0) . ', none delivered (Botcake)';
        if ($s['changed'] === null) return self::NOT_TESTED;

        $tested = ($s['test_ok'] !== null && $s['test_ok'] >= $s['changed']) || ($s['last_send'] !== null && $s['last_send'] >= $s['changed']);
        $byCheck = $check && $check['ok'] && (string) ($check['at'] ?? '') >= $s['changed']
            && ($s['system']['flow.shipping'] ?? false) && ($s['system']['field.message'] ?? false);

        return $tested || $byCheck ? null : self::NOT_TESTED;
    }
}
