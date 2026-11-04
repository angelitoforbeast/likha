<?php

namespace App\Services\Chatbot;

use App\Models\BotcakeChat;
use App\Models\PageSetting;
use App\Services\PageSettings;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Ang page na pinagbawalan ng Meta na mag-message (#2022, "temporarily restricted from messaging users"). Habang may
 * restriction, ang sequence (follow-up) ng page ay hindi nagpapadala; ang sagot sa customer na nag-chat ay sinusubukan pa.
 *
 * Nakatala sa page settings ng page (chatbot.restricted_until at chatbot.restricted_by), para makita sa listahan:
 * - "date": sinabi ng error kung hanggang kailan ("… until December 1 at 3:10 AM"); hawak hanggang sa oras na iyon.
 * - "probe": walang petsa (ang error ng Forbeast ay code lang); hawak nang PROBE_MINUTES, saka susubok ulit. Ang bagong
 *   #2022 ay hawak ulit; ang OK_TO_CLEAR na sunod-sunod na naipadala ay nag-aalis ng restriction.
 * Paputol-putol ang #2022 (may ilang padalang lumulusot habang restricted), kaya ang isang naipadala ay hindi sapat.
 */
class PageRestriction
{
    public const PROVIDER = 'chatbot';
    public const UNTIL_KEY = 'restricted_until';
    public const BY_KEY = 'restricted_by';
    public const BY_DATE = 'date';
    public const BY_PROBE = 'probe';
    public const PROBE_MINUTES = 30;
    public const OK_TO_CLEAR = 3;
    private const STREAK = 'page-restriction-ok:';

    public function __construct(private PageSettings $settings)
    {
    }

    /** Ang pinagbawalan ng Meta na page sa error ng padala (Botcake: "(#2022) …"; Forbeast: "… (#2022) subcode …"). */
    public static function isRestriction(?string $error): bool
    {
        return $error !== null && str_contains($error, '#2022');
    }

    /** Ang petsa sa error ("until December 1 at 3:10 AM"), sa oras ng app; lumipas na ang petsa ng taong ito → sa susunod na taon. */
    public static function untilFrom(?string $error, ?CarbonInterface $now = null): ?Carbon
    {
        // Ang Meta ay naglalagay ng makitid na espasyo (U+202F) bago ang AM/PM: ginagawang karaniwang espasyo muna.
        $error = $error === null ? null : (string) preg_replace('/[\x{00A0}\x{2007}\x{2009}\x{202F}]/u', ' ', $error);
        if ($error === null || !preg_match('/until\s+([A-Za-z]+\s+\d{1,2})(?:,?\s+(\d{4}))?\s+at\s+(\d{1,2}:\d{2}\s*[AP]M)/i', $error, $m)) return null;
        $now = Carbon::instance($now ?? now());
        try {
            $at = Carbon::parse($m[1] . ' ' . ($m[2] !== '' ? $m[2] : $now->year) . ' ' . $m[3], config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
        if ($m[2] === '' && $at->lt($now->copy()->subDay())) $at->addYear();

        return $at;
    }

    /** Hanggang kailan hawak ang page; null kapag walang restriction o lumipas na. */
    public function until(int|string $pageId): ?Carbon
    {
        $page = BotcakeChat::pageMap()[(string) $pageId] ?? null;
        if ($page === null) return null;
        $v = $this->settings->get($page, self::PROVIDER, self::UNTIL_KEY);
        if ($v === null || $v === '') return null;
        try {
            $at = Carbon::parse($v, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }

        return $at->gt(now()) ? $at : null;
    }

    /** Ang resulta ng isang padala ng page. Hindi pumipigil sa padala ang pagkabigo rito. */
    public function note(int|string $pageId, string $status, ?string $error): void
    {
        try {
            if ($status === 'failed' && self::isRestriction($error)) $this->restrict($pageId, $error);
            elseif ($status === 'sent') $this->delivered($pageId);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function restrict(int|string $pageId, ?string $error): void
    {
        $page = BotcakeChat::pageMap()[(string) $pageId] ?? null;
        if ($page === null) return;
        Cache::forget(self::STREAK . $pageId);
        $date = self::untilFrom($error);
        $by = (string) $this->settings->get($page, self::PROVIDER, self::BY_KEY);
        $current = $this->until($pageId);
        if ($date === null) {
            // Hawak pa: walang bago. Ang alam na petsa ay hindi pinapalitan ng hula.
            if ($current !== null) return;
            [$until, $by] = [now()->addMinutes(self::PROBE_MINUTES), self::BY_PROBE];
        } else {
            if ($current !== null && $by === self::BY_DATE && abs($current->diffInMinutes($date)) < 1) return;
            [$until, $by] = [$date, self::BY_DATE];
        }
        $this->settings->set($page, self::PROVIDER, self::UNTIL_KEY, $until->format('Y-m-d H:i:s'), system: true);
        $this->settings->set($page, self::PROVIDER, self::BY_KEY, $by, system: true);
    }

    private function delivered(int|string $pageId): void
    {
        $page = BotcakeChat::pageMap()[(string) $pageId] ?? null;
        if ($page === null) return;
        $v = $this->settings->get($page, self::PROVIDER, self::UNTIL_KEY);
        if ($v === null || $v === '') return;
        $by = (string) $this->settings->get($page, self::PROVIDER, self::BY_KEY);
        // May petsa at hindi pa lumilipas: ang lumusot na padala ay hindi patunay na tapos na.
        if ($by === self::BY_DATE && $this->until($pageId) !== null) return;
        $key = self::STREAK . $pageId;
        Cache::add($key, 0, now()->addDay());
        if ($by === self::BY_DATE || Cache::increment($key) >= self::OK_TO_CLEAR) $this->clear($page, $pageId);
    }

    private function clear(string $page, int|string $pageId): void
    {
        Cache::forget(self::STREAK . $pageId);
        foreach ($this->settings->pageQuery($page)->where('provider', self::PROVIDER)->whereIn('key', [self::UNTIL_KEY, self::BY_KEY])->where('active', true)->get() as $row) {
            /** @var PageSetting $row */
            $this->settings->deactivate($row);
        }
    }
}
