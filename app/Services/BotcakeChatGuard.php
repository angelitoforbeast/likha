<?php

namespace App\Services;

use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

    private function sharedKeyOk(string $given): bool
    {
        $shared = (string) config('services.botcake.chat_key');

        return (bool) config('services.botcake.chat_shared_key_ok') && $shared !== '' && hash_equals($shared, $given);
    }

    /**
     * Nasa page ba ang PSID? 'ok' (nasa botcake_psids, o pinatunayan ngayon ng Botcake at itinala bilang chat_verify),
     * 'not_on_page' (sinabi ng Botcake na wala; naaalala nang 1 oras), o 'unverified' (hindi sumagot ang Botcake o walang
     * token: tinatanggap, itinatala sa log, at susubukan ulit sa susunod na chat).
     */
    public function checkPsid(string $page, string $pageId, string $psid, ?string $name): string
    {
        $pageKey = $this->settings->pageKey($page);
        // Ang psid ay naka-index; iilan lang ang row ng isang PSID, kaya ang page ay inihahambing dito (trim, case).
        $pages = BotcakePsid::where('psid', $psid)->pluck('page');
        if ($pages->contains(fn ($p) => $this->settings->pageKey((string) $p) === $pageKey)) return 'ok';

        $notOnPage = 'botcake-chat:not-on-page:' . sha1($pageId . '|' . $psid);
        if (Cache::has($notOnPage)) return 'not_on_page';

        $token = trim((string) $this->settings->get($page, 'botcake', 'token'));
        $found = $token === '' ? null : app(BotcakeClient::class)->shared(sha1($token), self::VERIFY_TIMEOUT)->once(self::VERIFY_TIMEOUT)
            ->customerInfo($pageId, $psid, $token);

        if ($found === false) {
            Cache::put($notOnPage, true, self::NOT_ON_PAGE_SECONDS);
            return 'not_on_page';
        }
        if ($found === null) {
            Log::warning('botcake chat: PSID not verified, chat kept', ['page' => $page, 'reason' => $token === '' ? 'no token' : 'Botcake did not answer']);
            return 'unverified';
        }

        BotcakePsid::create([
            'page' => $page, 'psid' => $psid, 'fb_name' => mb_substr((string) $name, 0, 255), 'status' => 'verified',
            'source' => 'chat_verify', 'first_seen_at' => now(),
        ]);

        return 'ok';
    }
}
