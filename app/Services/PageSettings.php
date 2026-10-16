<?php

namespace App\Services;

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
    /** Mga key na system lang ang sumusulat (hindi tinatanggap mula sa page). */
    public const SYSTEM_KEYS = ['botcake.token_status'];

    /** Mga key na laging secret. */
    public const SECRET_KEYS = ['botcake.token'];

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

        if ($this->normPage($page) === '') return ['page_name' => 'Kailangan ang page.'];
        if (mb_strlen($this->normPage($page)) > 255) return ['page_name' => 'Page: hanggang 255 na character lang.'];
        if (!preg_match('/^[a-z0-9_]{1,50}$/', $provider)) return ['provider' => 'Provider: maliliit na letra, numero at _ lang.'];
        if (strlen($key) > 100 || !preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)*$/', $key)) {
            return ['key' => 'Key: maliliit na letra, numero, _ at . lang (hal. flow.on_delivery).'];
        }

        if (!$system && in_array($full, self::SYSTEM_KEYS, true)) {
            return ['value' => 'Ang ' . $full . ' ay isinusulat ng system (Test token / sync), hindi dito.'];
        }

        $digits = $full === 'botcake.page_id'
            || preg_match('/^botcake\.(flow|field)\.[a-z0-9_]+$/', $full);

        if ($digits && !preg_match('/^\d+$/', $value)) {
            return ['value' => 'Ang ' . $full . ' ay numero lang (walang espasyo, slash o letra).'];
        }
        if ($value === '') return ['value' => 'Kailangan ang value.'];

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

        return DB::transaction(function () use ($page, $provider, $key, $value, $secret, $userId) {
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

            return ['status' => $status, 'setting' => $setting];
        });
    }

    public function deactivate(PageSetting $setting, ?int $userId = null): void
    {
        if (!$setting->active) return;

        $setting->update(['active' => false, 'replaced_at' => now()]);
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
