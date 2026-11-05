<?php

namespace App\Services\Chatbot;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ang customer na sinabi ng Meta na hindi maabot sa isang page: #551 ("This person isn't available right now": nag-block,
 * binura ang usapan, wala na ang account) o #10 na labas sa messaging window. Habang may marka, ang padalang hindi sagot
 * sa bagong mensahe ng customer (sequence, follow-up, wait na lumampas, Chatblast) ay nilalaktawan: ang paulit-ulit na
 * padala sa hindi maabot ay sayang at mukhang spam sa Meta. Nawawala ang marka sa unang bagong mensahe ng customer sa page,
 * o pagkalipas ng EXPIRE_DAYS (ang page na walang chat na dumarating sa Likha ay walang ibang paraan para maalis ito).
 *
 * Nakatala bilang run state (__unreachable) sa chatbot_customer_values: {"code": 551, "at": "Y-m-d H:i:s"}. Ang pagsusuri
 * ay isang basa sa unique index (page_id, psid, key), sariwa sa bawat padala (hindi ang nabasa ng takbo, na luma na
 * habang naghihintay ang takbo sa AI o sa pool).
 */
class CustomerReach
{
    public const KEY = '__unreachable';
    public const EXPIRE_DAYS = 30;
    public const REASON = "Customer can't be reached";
    /** Ang #10 ng page na pansamantalang pinagbawalan ng Meta: sa page ang dahilan, hindi kailanman sa customer. */
    private const RESTRICTED_SUBCODE = '1893063';
    private const WINDOW_SUBCODE = '2018278';
    private const MAX_TEXT = 4000;

    public static function enabled(): bool
    {
        return (bool) config('services.chatbot.stop_unreachable', true);
    }

    /**
     * Ang code ng Meta na nagmamarka mula sa error (at sa buong sagot, $raw) ng pumalyang padala: 551, 10 (ang sa window
     * lang), o null. Hindi pinagkakatiwalaan ang text: pinaikli, at ang code ay buong numero lang (hindi #100 o #5510).
     */
    public static function code(?string $error, ?string $raw = null): ?int
    {
        $text = mb_substr((string) $error, 0, self::MAX_TEXT) . "\n" . mb_substr((string) $raw, 0, self::MAX_TEXT);
        if (preg_match('/\(#551\)|\bcode 551\b|"code":551\b|person isn[\'\x{2019}]t available/iu', $text)) return 551;
        // Ang #10 ay pareho rin para sa permiso ng page at sa restriction (1893063): ang sa window lang ang sa customer.
        $ten = preg_match('/\(#10\)|\bcode 10\b|"code":10\b/', $text);
        if ($ten && !str_contains($text, self::RESTRICTED_SUBCODE)
            && (stripos($text, 'outside of allowed window') !== false || preg_match('/\b' . self::WINDOW_SUBCODE . '\b/', $text))) return 10;

        return null;
    }

    /** Ang pumalyang padala sa customer: may marka kapag ang code ay sa hindi maabot. Hindi pumipigil sa talaan ang pagkabigo rito. */
    public static function note(int|string $pageId, int|string $psid, ?string $error, ?string $raw = null): void
    {
        try {
            if (!self::enabled() || !self::valid($pageId, $psid) || ($code = self::code($error, $raw)) === null) return;
            DB::table('chatbot_customer_values')->upsert(
                [['page_id' => (int) $pageId, 'psid' => (int) $psid, 'key' => self::KEY,
                    'value' => json_encode(['code' => $code, 'at' => now()->format('Y-m-d H:i:s')]), 'updated_at' => now()]],
                ['page_id', 'psid', 'key'], ['value', 'updated_at'],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Ang dahilan ng laktaw ("Customer can't be reached (#551) since 2026-11-05 14:03"), o null kapag ipapadala. Para lang
     * sa padalang hindi sagot sa bagong mensahe ng customer: ang sagot ay hindi kailanman tinatawag dito.
     */
    public static function skipReason(int|string $pageId, int|string $psid): ?string
    {
        if (!self::enabled() || !self::valid($pageId, $psid)) return null;
        $v = DB::table('chatbot_customer_values')->where('page_id', (int) $pageId)->where('psid', (int) $psid)->where('key', self::KEY)->value('value');
        $mark = is_string($v) ? json_decode($v, true) : null;
        if (!is_array($mark) || !is_int($mark['code'] ?? null) || !is_string($mark['at'] ?? null)) return null;
        try {
            $at = Carbon::parse($mark['at']);
        } catch (Throwable) {
            return null;
        }
        if ($at->lt(now()->subDays(self::EXPIRE_DAYS))) return null;

        return self::REASON . ' (#' . $mark['code'] . ') since ' . $at->format('Y-m-d H:i');
    }

    /** Bagong mensahe ng customer sa page: naaabot na ulit (isang DELETE sa index). */
    public static function clear(int|string $pageId, int|string $psid): void
    {
        try {
            if (!self::valid($pageId, $psid)) return;
            DB::table('chatbot_customer_values')->where('page_id', (int) $pageId)->where('psid', (int) $psid)->where('key', self::KEY)->delete();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Totoong page at customer lang (ang test customer ay PSID 0; ang ID ay mga digit). */
    private static function valid(int|string $pageId, int|string $psid): bool
    {
        return ctype_digit((string) $pageId) && ctype_digit((string) $psid) && (int) $psid > 0 && strlen((string) $psid) <= 20 && strlen((string) $pageId) <= 20;
    }
}
