<?php

namespace App\Services;

use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Ang mga harang ng /api/botcake/chat: (1) IP allowlist ng Botcake, (2) key kada page na nakatali sa page_id (at ang
 * lumang shared key habang naka-on ang flag), (3) ang PSID ay dapat nasa page: botcake_psids muna, saka isang tawag sa
 * Botcake (customer info) na naaalala. Ang bawat pagtanggi ay binibilang kada araw (cache). Walang key o token sa log.
 */
class BotcakeChatGuard
{
    /** Dahilan => label sa Setup box. */
    public const REASONS = [
        'bad_ip' => 'IP not on the list', 'bad_key' => 'Bad key', 'unknown_page' => 'Unknown page', 'psid_not_found' => 'PSID not on the page',
        'psid_unchecked' => 'PSID could not be checked', 'bot_off' => 'Bot off',
    ];
    public const VERIFY_TIMEOUT = 5;      // segundo: naghihintay ang Botcake sa sagot
    public const NOT_ON_PAGE_SECONDS = 3600;

    public function __construct(private PageSettings $settings)
    {
    }

    public function ipListed(string $ip): bool
    {
        return IpUtils::checkIp($ip, (array) config('services.botcake.chat_ips'));
    }

    public const TEST_IPS_CACHE = 'botcake-chat:test-ips'; // [ip => unix na pag-expire], mula sa botcake:chat-test-ip
    public const TEST_IP_MAX_HOURS = 12;

    /**
     * Walang rate limit (at walang cache write ng limiter): ang IP ng Botcake (config, walang cache read), o ang
     * pansamantalang test IP na hindi pa expired. Ang REMOTE_ADDR lang ang tinitingnan, hindi ang body ng request.
     */
    public static function unlimited(string $ip): bool
    {
        if ($ip === '') return false;
        if (IpUtils::checkIp($ip, (array) config('services.botcake.chat_ips'))) return true;

        return (self::testIps()[$ip] ?? 0) > now()->getTimestamp();
    }

    /** @return array<string, int> ang mga test IP na hindi pa expired => unix na pag-expire */
    public static function testIps(): array
    {
        $now = now()->getTimestamp();

        return array_filter((array) Cache::get(self::TEST_IPS_CACHE, []), fn ($until) => (int) $until > $now);
    }

    /** Idagdag (hanggang $hours oras) o alisin ($hours = null) ang isang test IP. */
    public static function setTestIp(string $ip, ?int $hours): void
    {
        $ips = self::testIps();
        if ($hours === null) unset($ips[$ip]); else $ips[$ip] = now()->addHours($hours)->getTimestamp();
        if (!$ips) { Cache::forget(self::TEST_IPS_CACHE); return; }
        Cache::put(self::TEST_IPS_CACHE, $ips, now()->setTimestamp(max($ips)));
    }

    public function enforcesIp(): bool
    {
        return config('services.botcake.chat_ip_mode') === 'enforce';
    }

    public function count(string $reason): void
    {
        $key = $this->countKey(now('Asia/Manila')->toDateString(), $reason);
        Cache::add($key, 0, now()->addDays(3));
        Cache::increment($key);
    }

    /** @return array<string, int> dahilan => bilang ngayong araw (Manila) */
    public function counts(?string $date = null): array
    {
        $date ??= now('Asia/Manila')->toDateString();
        $out = [];
        foreach (array_keys(self::REASONS) as $reason) $out[$reason] = (int) Cache::get($this->countKey($date, $reason), 0);

        return $out;
    }

    private function countKey(string $date, string $reason): string
    {
        return 'botcake-chat:refused:' . $date . ':' . $reason;
    }

    /** Kilala ba ang key (ang shared habang naka-on, o ang chat.key ng anumang page)? Bago pa basahin ang body. */
    public function knownKey(string $given): bool
    {
        if ($given === '') return false;

        return $this->sharedKeyOk($given) || isset(BotcakeChat::keyHashes()[hash('sha256', $given)]);
    }

    /** Ang key para sa page na ito: ang sariling chat.key ng page, o ang shared key habang naka-on ang flag. */
    public function keyFitsPage(string $page, string $given): bool
    {
        if ($given === '') return false;
        $own = (string) $this->settings->get($page, 'botcake', 'chat.key');
        if ($own !== '' && hash_equals($own, $given)) return true;

        return $this->sharedKeyOk($given);
    }

    /** Naka-Off ba ang bot ng page sa /chatbot (chatbot.active = 0)? Walang row = On. */
    public function botOff(string $page): bool
    {
        return $this->settings->get($page, 'chatbot', 'active') === '0';
    }

    private function sharedKeyOk(string $given): bool
    {
        $shared = (string) config('services.botcake.chat_key');

        return (bool) config('services.botcake.chat_shared_key_ok') && $shared !== '' && hash_equals($shared, $given);
    }

    /**
     * Nasa page ba ang PSID? 'ok' (nasa botcake_psids, o pinatunayan ngayon ng Botcake at itinala bilang chat_verify);
     * 'not_on_page' (sinabi ng Botcake na wala; naaalala nang 1 oras); 'unverified' (walang koneksyon, timeout o 5xx:
     * tinatanggap, itinatala sa log, susubukan ulit sa susunod na chat); 'unchecked' (walang token, lampas sa cap ng
     * pagsuri kada page, o ibang sagot ng Botcake gaya ng token o 429: hindi iniimbak, para hindi mapilit ng umaatake
     * ang pagtanggap). Ang ad_id ng chat ay itinatala sa row ng PSID: sa bagong chat_verify, at sa row na wala pang
     * ad_id (hindi pinapalitan ang mayroon na).
     */
    public function checkPsid(string $page, string $pageId, string $psid, ?string $name, ?string $adId = null): string
    {
        $pageKey = $this->settings->pageKey($page);
        // Ang psid ay naka-index; iilan lang ang row ng isang PSID, kaya ang page ay inihahambing dito (trim, case).
        $rows = BotcakePsid::where('psid', $psid)->get(['id', 'page', 'ad_id'])
            ->filter(fn ($r) => $this->settings->pageKey((string) $r->page) === $pageKey);
        if ($rows->isNotEmpty()) {
            $empty = $rows->filter(fn ($r) => trim((string) $r->ad_id) === '')->pluck('id');
            if ($adId !== null && $empty->isNotEmpty()) BotcakePsid::whereIn('id', $empty)->update(['ad_id' => $adId]);
            return 'ok';
        }

        $notOnPage = 'botcake-chat:not-on-page:' . sha1($pageId . '|' . $psid);
        if (Cache::has($notOnPage)) return 'not_on_page';

        $token = trim((string) $this->settings->get($page, 'botcake', 'token'));
        if ($token === '') {
            Log::warning('botcake chat: PSID not checked, chat not kept', ['page' => $page, 'reason' => 'no token']);
            return 'unchecked';
        }
        // Ang bawat bagong PSID ay isang tawag gamit ang token ng page (kahati ng chatblast sa 2 kada segundo): may cap kada page.
        if (!RateLimiter::attempt('botcake-chat-verify:' . $pageId, max(1, (int) config('services.botcake.chat_verify_per_minute')), fn () => true, 60)) {
            return 'unchecked';
        }

        $answer = app(BotcakeClient::class)->shared(sha1($token), self::VERIFY_TIMEOUT)->once(self::VERIFY_TIMEOUT)->customerInfo($pageId, $psid, $token);
        if ($answer === 'not_found') {
            Cache::put($notOnPage, true, self::NOT_ON_PAGE_SECONDS);
            return 'not_on_page';
        }
        if ($answer === 'down') {
            Log::warning('botcake chat: PSID not verified, chat kept', ['page' => $page, 'reason' => 'Botcake did not answer']);
            return 'unverified';
        }
        if ($answer !== 'found') {
            Log::warning('botcake chat: PSID not checked, chat not kept', ['page' => $page, 'reason' => 'Botcake refused the check']);
            return 'unchecked';
        }

        BotcakePsid::create([
            'page' => $page, 'psid' => $psid, 'fb_name' => mb_substr((string) $name, 0, 255), 'status' => 'verified',
            'source' => 'chat_verify', 'first_seen_at' => now(), 'ad_id' => $adId,
        ]);

        return 'ok';
    }
}
