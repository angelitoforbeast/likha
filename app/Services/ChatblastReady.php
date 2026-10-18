<?php

namespace App\Services;

use App\Models\BotcakeMessage;
use App\Models\PageSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

        $sent = BotcakeMessage::where('status', 'sent')->where('is_test', false)->whereNotNull('sent_at')
            ->selectRaw('page_name, MAX(sent_at) AS last')->groupBy('page_name')->get();
        foreach ($sent as $row) {
            $pk = $this->settings->pageKey((string) $row->page_name);
            if (!isset($out[$pk])) continue;
            $last = Carbon::parse($row->last)->format('Y-m-d H:i:s');
            $out[$pk]['last_send'] = max($out[$pk]['last_send'] ?? '', $last);
        }

        foreach ($out as $pk => $s) $out[$pk]['tested'] = self::isTested($s);

        return $out;
    }

    /** Para sa isang page (sender, Send test). */
    public function tested(string $page): bool
    {
        $rows = $this->settings->pageQuery($page)->where('active', true)->where('provider', 'botcake')
            ->whereIn('key', [...self::CONFIG, 'test_ok'])->get(['key', 'created_at']);
        $s = ['changed' => null, 'test_ok' => null, 'last_send' => null];
        foreach ($rows as $r) {
            $at = (string) $r->created_at?->format('Y-m-d H:i:s');
            if ($r->key === 'test_ok') $s['test_ok'] = max($s['test_ok'] ?? '', $at); else $s['changed'] = max($s['changed'] ?? '', $at);
        }
        if ($s['changed'] === null) return false;

        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        $last = BotcakeMessage::where('status', 'sent')->where('is_test', false)->whereNotNull('sent_at')
            ->whereRaw("LOWER($trim(page_name)) = ?", [$this->settings->pageKey($page)])->max('sent_at');
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
