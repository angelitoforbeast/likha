<?php

namespace App\Services;

use App\Models\BotcakeChat;
use App\Models\PageSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Basa at sulat ng page_settings. Isang ACTIVE row kada page + provider + key: ang bagong value ay pumapalit
 * (ang luma ay active = false + replaced_at, naiiwan bilang history). Ang page ay inihahambing trim at
 * case-insensitive, gaya ng macro_output.PAGE.
 */
class PageSettings
{
    /**
     * Mga key na system lang ang sumusulat (hindi tinatanggap mula sa page). Kasama rin ang mga tugma sa
     * DETAIL_PATTERN: ang pangalan ng bawat flow (botcake.flow.<x>.name) at ang body ng shipping template.
     */
    public const SYSTEM_KEYS = ['botcake.token_status', 'botcake.template.shipping.text', 'botcake.test_ok',
        'botcake.flow.shipping.options', 'botcake.field.message.options', 'botcake.chat.key', 'chatbot.active'];

    /**
     * Mga system key na ipinapakita sa loob ng cell ng ibang key (flow ID, template, message field), hindi bilang sariling
     * column: ang pangalan ng flow, ang body ng template, at ang mga kandidato para sa dropdown (JSON na [{id, name}]).
     */
    public const DETAIL_PATTERN = '/^botcake\.flow\.[a-z0-9_]+\.name$|^botcake\.template\.shipping\.text$|^botcake\.(flow\.shipping|field\.message)\.options$/';

    /** Mga key na laging secret. */
    public const SECRET_KEYS = ['botcake.token', 'botcake.chat.key'];

    public static function isSystemKey(string $full): bool
    {
        return in_array($full, self::SYSTEM_KEYS, true) || self::isDetailKey($full);
    }

    public static function isDetailKey(string $full): bool
    {
        return (bool) preg_match(self::DETAIL_PATTERN, $full);
    }

    public function normPage(?string $page): string
    {
        return preg_replace('/\s+/u', ' ', trim((string) $page));
    }

    public function pageKey(string $page): string
    {
        return mb_strtolower($this->normPage($page), 'UTF-8');
    }

    public function pageQuery(string $page)
    {
        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        return PageSetting::whereRaw("LOWER($trim(page_name)) = ?", [$this->pageKey($page)]);
    }

    /**
     * Ang mali sa input, o null kapag tama. Ang mga kilalang key ay may sariling rule; ang iba ay malayang text.
     * @return array<string, string>|null field => mensahe
     */
    public function validate(string $page, string $provider, string $key, ?string $value, bool $system = false): ?array
    {
        $provider = strtolower(trim($provider));
        $key      = strtolower(trim($key));
        $value    = trim((string) $value);
        $full     = $provider . '.' . $key;

        if ($this->normPage($page) === '') return ['page_name' => 'The page is required.'];
        if (mb_strlen($this->normPage($page)) > 255) return ['page_name' => 'Page: 255 characters at most.'];
        if (!preg_match('/^[a-z0-9_]{1,50}$/', $provider)) return ['provider' => 'Provider: lowercase letters, digits and _ only.'];
        if (strlen($key) > 100 || !preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)*$/', $key)) {
            return ['key' => 'Key: lowercase letters, digits, _ and . only (e.g. flow.on_delivery).'];
        }

        if (!$system && self::isSystemKey($full)) {
            return ['value' => $full . ' is written by the system (Test token / sync), not here.'];
        }

        $digits = $full === 'botcake.page_id'
            || preg_match('/^botcake\.(flow|field)\.[a-z0-9_]+$/', $full);

        if ($digits && !preg_match('/^\d+$/', $value)) {
            return ['value' => $full . ' takes digits only (no spaces, slashes or letters).'];
        }
        if ($value === '') return ['value' => 'The value is required.'];

        return null;
    }

    /**
     * Isulat ang value. Parehong value ng active → walang bago.
     * @return array{status:string, setting:PageSetting} status = created|replaced|unchanged
     */
    public function set(string $page, string $provider, string $key, string $value, bool $secret = false, ?int $userId = null, bool $system = false): array
    {
        $errors = $this->validate($page, $provider, $key, $value, $system);
        if ($errors) throw new \InvalidArgumentException(reset($errors));

        $provider = strtolower(trim($provider));
        $key      = strtolower(trim($key));
        $value    = trim($value);
        $secret   = $secret || in_array($provider . '.' . $key, self::SECRET_KEYS, true);

        $res = DB::transaction(function () use ($page, $provider, $key, $value, $secret, $userId) {
            $current = $this->pageQuery($page)->where('provider', $provider)->where('key', $key)
                ->where('active', true)->lockForUpdate()->orderByDesc('id')->get();

            $head = $current->first();
            if ($head && $current->count() === 1 && $head->is_secret === $secret && $head->plainValue() === $value) {
                return ['status' => 'unchanged', 'setting' => $head];
            }

            foreach ($current as $old) {
                $old->update(['active' => false, 'replaced_at' => now()]);
            }

            // Ang unang naitalang baybay ng page ang ginagamit, para iisa ang pangalan sa listahan.
            $name = $this->pageQuery($page)->orderBy('id')->value('page_name') ?? $this->normPage($page);

            $setting = PageSetting::create([
                'page_name'  => $name,
                'provider'   => $provider,
                'key'        => $key,
                'value'      => $secret ? Crypt::encryptString($value) : $value,
                'is_secret'  => $secret,
                'active'     => true,
                'created_by' => $userId,
            ]);

            // Walang value sa log: sino, kailan at aling key lang.
            $status = $current->isEmpty() ? 'created' : 'replaced';
            Log::info('PageSettings: ' . $status, [
                'page' => $name, 'key' => $provider . '.' . $key, 'setting_id' => $setting->id, 'by' => $userId,
            ]);

            // Ang naka-cache ng /api/botcake/chat (page_id => page, mga hash ng chat.key): binubura ngayon at pagkatapos ng commit.
            if ($provider === 'botcake' && in_array($key, ['page_id', 'chat.key'], true)) $this->forgetChatPageMap();

            return ['status' => $status, 'setting' => $setting];
        });
        // Pagkatapos ng commit: ang Ready ng page (ChatblastReady) ay kinukuwenta ulit sa susunod na tanong.
        if ($res['status'] !== 'unchanged' && ChatblastReady::watches($provider, $key)) ChatblastReady::forget($this->pageKey($page));

        return $res;
    }

    private function forgetChatPageMap(): void
    {
        BotcakeChat::forgetPageMap();
        DB::afterCommit(fn () => BotcakeChat::forgetPageMap());
    }

    public function deactivate(PageSetting $setting, ?int $userId = null): void
    {
        if (!$setting->active) return;

        $setting->update(['active' => false, 'replaced_at' => now()]);
        if (ChatblastReady::watches($setting->provider, $setting->key)) ChatblastReady::forget($this->pageKey($setting->page_name));
        if ($setting->provider === 'botcake' && in_array($setting->key, ['page_id', 'chat.key'], true)) $this->forgetChatPageMap();
        Log::info('PageSettings: deactivated', [
            'page' => $setting->page_name, 'key' => $setting->fullKey(), 'setting_id' => $setting->id, 'by' => $userId,
        ]);
    }

    public function get(string $page, string $provider, string $key): ?string
    {
        return $this->pageQuery($page)->where('provider', strtolower($provider))->where('key', strtolower($key))
            ->where('active', true)->orderByDesc('id')->first()?->plainValue();
    }

    /**
     * Mga page na may active na lahat ng key na ito (pangalan gaya ng naitala).
     * @return array<int, string>
     */
    public function pagesWith(string $provider, array $keys): array
    {
        $keys = array_values(array_unique($keys));
        $rows = PageSetting::where('provider', $provider)->whereIn('key', $keys)->where('active', true)
            ->orderBy('id')->get(['page_name', 'key']);

        return $rows->groupBy(fn ($r) => $this->pageKey($r->page_name))
            ->filter(fn ($g) => $g->pluck('key')->unique()->count() === count($keys))
            ->map(fn ($g) => $g->first()->page_name)
            ->sort()->values()->all();
    }
}
