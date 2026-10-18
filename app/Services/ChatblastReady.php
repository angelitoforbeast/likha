<?php

namespace App\Services;

use App\Models\BotcakeMessage;
use App\Models\PageSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kung "nasubukan" na ang page para sa chatblast: may kumpirmadong padala pagkatapos ng huling pagbabago ng setup nito
 * (Botcake ID, token, Chatblast flow o message field). Ang kumpirmadong padala ay alinman sa: isang matagumpay na Send
 * test (botcake.test_ok, isinusulat ng sender kapag ang test ay naging sent) o isang totoong naipadalang chatblast
 * (mula sa botcake_messages; walang itinatabi para rito). Ang pagbabago ng setup ay ginagawang mas luma ang dating
 * test, kaya kailangan ulit ng test; walang binubura.
 */
class ChatblastReady
{
    public const NOT_TESTED = 'Page not ready: needs a test send';
    public const SAMPLE_TEXT = 'Test message from Chatblast: if you see this, the page is set up.';

    /** Ang setup na kapag nagbago ay kailangan ulit ng test. */
    public const CONFIG = ['page_id', 'token', 'flow.shipping', 'field.message'];

    /** Segundo ng cache ng tested() kada page (tinatanong ng sender sa bawat totoong padala). */
    private const CACHE_SECONDS = 60;

    public function __construct(private PageSettings $settings)
    {
    }

    /**
     * Ang kalagayan ng bawat page na may alinman sa setup: tatlong query lang (settings, test_ok, huling totoong padala).
     * @return array<string, array{changed: ?string, test_ok: ?string, last_send: ?string, tested: bool}> pageKey => ...
     */
    public function states(): array
    {
        $out = [];
        PageSetting::where('active', true)->where('provider', 'botcake')->whereIn('key', [...self::CONFIG, 'test_ok'])
            ->get(['page_name', 'key', 'created_at'])
            ->each(function ($s) use (&$out) {
                $pk = $this->settings->pageKey($s->page_name);
                $out[$pk] ??= ['changed' => null, 'test_ok' => null, 'last_send' => null, 'tested' => false];
                $at = $s->created_at?->format('Y-m-d H:i:s');
                if ($s->key === 'test_ok') $out[$pk]['test_ok'] = max($out[$pk]['test_ok'] ?? '', (string) $at);
                else $out[$pk]['changed'] = max($out[$pk]['changed'] ?? '', (string) $at);
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

        foreach ($out as $pk => $s) $out[$pk]['tested'] = self::isTested($s);

        return $out;
    }

    /**
     * Para sa isang page (sender, Send test). Naka-cache nang 60 s kada page: ang MAX(queued_at) ay sumusuri sa mga
     * naipadalang row sa bawat padala. Binubura ng PageSettings ang cache kapag nagbago ang setup o ang test_ok.
     */
    public function tested(string $page): bool
    {
        return Cache::remember(self::cacheKey($this->settings->pageKey($page)), self::CACHE_SECONDS, fn () => $this->fresh($page));
    }

    /** Ang cache ng page: binubura kapag nagbago ang alinman sa CONFIG o ang test_ok nito. */
    public static function forget(string $pageKey): void
    {
        Cache::forget(self::cacheKey($pageKey));
    }

    public static function watches(string $provider, string $key): bool
    {
        return $provider === 'botcake' && in_array($key, [...self::CONFIG, 'test_ok'], true);
    }

    private static function cacheKey(string $pageKey): string
    {
        return 'chatblast:ready:' . sha1($pageKey);
    }

    private function fresh(string $page): bool
    {
        $rows = $this->settings->pageQuery($page)->where('active', true)->where('provider', 'botcake')
            ->whereIn('key', [...self::CONFIG, 'test_ok'])->get(['key', 'created_at']);
        $s = ['changed' => null, 'test_ok' => null, 'last_send' => null];
        foreach ($rows as $r) {
            $at = (string) $r->created_at?->format('Y-m-d H:i:s');
            if ($r->key === 'test_ok') $s['test_ok'] = max($s['test_ok'] ?? '', $at); else $s['changed'] = max($s['changed'] ?? '', $at);
        }
        if ($s['changed'] === null) return false;

        // Ayon sa pageKey (gaya ng states() at ng build): ang page_name ng row ay ang baybay ng PAGE sa order, na maaaring
        // iba-iba (hal. "Page Uno" at "PAGE UNO"); ang eksaktong tugma ay magpapaikot sa row (ready → tinanggihan → ready).
        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        $last = !Schema::hasTable('botcake_messages') ? null : BotcakeMessage::where('status', 'sent')->where('is_test', false)->whereNotNull('queued_at')
            ->whereRaw("LOWER($trim(page_name)) = ?", [$this->settings->pageKey($page)])->max('queued_at');
        $s['last_send'] = $last ? Carbon::parse($last)->format('Y-m-d H:i:s') : null;

        return self::isTested($s);
    }

    /** May test o totoong padala na hindi mas luma sa huling pagbabago ng setup. */
    private static function isTested(array $s): bool
    {
        if ($s['changed'] === null) return false;
        return ($s['test_ok'] !== null && $s['test_ok'] >= $s['changed']) || ($s['last_send'] !== null && $s['last_send'] >= $s['changed']);
    }
}
