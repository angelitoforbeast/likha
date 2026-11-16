<?php

namespace App\Services\MetaAds;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ang Meta app (App ID, App Secret, Configuration ID) ng site na ito (spec 281). Isang app_settings row kada value:
 * meta_ads.app.app_id, meta_ads.app.app_secret (naka-encrypt, Crypt::encryptString), meta_ads.app.config_id.
 * Ang naka-save na row ang nananalo; kapag walang row, ang .env (config services.meta_ads.*) gaya ng dati.
 * Ang secret ay hindi kailanman ibinabalik sa page: secretSet() lang ang alam ng screen.
 * Spec 282: meta_ads.app.company_name at meta_ads.app.contact_email para sa public na /privacy. Walang .env fallback:
 * kapag wala, config('app.name') ang pangalan at walang email line.
 */
class AppConfig
{
    public const PREFIX     = 'meta_ads.app.';
    public const APP_ID     = self::PREFIX . 'app_id';
    public const APP_SECRET = self::PREFIX . 'app_secret';
    public const CONFIG_ID  = self::PREFIX . 'config_id';
    public const COMPANY    = self::PREFIX . 'company_name';
    public const EMAIL      = self::PREFIX . 'contact_email';

    /** Ang naka-save na pangalan ng kompanya (null = wala; ang /privacy ay gumagamit ng config('app.name')). */
    public static function companyName(): ?string
    {
        return self::blankToNull(self::row(self::COMPANY));
    }

    /** Ang contact email sa /privacy (null = walang email line). */
    public static function contactEmail(): ?string
    {
        return self::blankToNull(self::row(self::EMAIL));
    }

    /**
     * Isinusulat ang company name at contact email kapag nagbago ('' = binubura ang value). Ibinabalik ang mga nagbago.
     * @return list<string>
     */
    public static function savePrivacy(?string $company, ?string $email): array
    {
        $changed = [];
        foreach (['company_name' => [self::COMPANY, $company], 'contact_email' => [self::EMAIL, $email]] as $field => [$key, $value]) {
            $value = trim((string) $value);
            if ($value === (string) self::blankToNull(self::row($key))) continue;
            self::put($key, $value);
            $changed[] = $field;
        }

        return $changed;
    }

    public static function appId(): ?string
    {
        return self::blankToNull(self::row(self::APP_ID) ?? config('services.meta_ads.app_id'));
    }

    /** Facebook Login for Business configuration. Ang naka-save na walang laman ay "wala" (kahit may nasa .env). */
    public static function configId(): ?string
    {
        return self::blankToNull(self::row(self::CONFIG_ID) ?? config('services.meta_ads.config_id'));
    }

    public static function appSecret(): ?string
    {
        $row = self::row(self::APP_SECRET);
        if ($row === null) return self::blankToNull(config('services.meta_ads.app_secret'));
        try {
            return self::blankToNull(Crypt::decryptString($row));
        } catch (Throwable) {
            return null; // naka-encrypt sa ibang key: parang walang secret
        }
    }

    /** Saan galing ang field (app_id | app_secret | config_id): 'saved' (row dito), 'env' (server file) o null (wala). */
    public static function source(string $field): ?string
    {
        if (self::row(self::PREFIX . $field) !== null) return 'saved';

        return trim((string) config('services.meta_ads.' . $field)) !== '' ? 'env' : null;
    }

    /**
     * Isinusulat ang nagbago lang (ang pareho sa kasalukuyan ay walang row na nagagawa, kaya ang site na naka-.env ay
     * nananatiling naka-.env). $secret = null o '': itinatabi ang dati. Ibinabalik ang mga field na nagbago.
     * @return list<string>
     */
    public static function save(string $appId, ?string $secret, ?string $configId): array
    {
        $changed = [];
        if ($appId !== (string) self::appId()) {
            self::put(self::APP_ID, $appId);
            $changed[] = 'app_id';
        }
        if ($secret !== null && $secret !== '' && !hash_equals((string) self::appSecret(), $secret)) {
            self::put(self::APP_SECRET, Crypt::encryptString($secret));
            $changed[] = 'app_secret';
        }
        if ((string) $configId !== (string) self::configId()) {
            self::put(self::CONFIG_ID, (string) $configId);
            $changed[] = 'config_id';
        }

        return $changed;
    }

    private static function put(string $key, string $value): void
    {
        DB::table('app_settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
    }

    /** Ang value ng row, o null kapag walang row. */
    private static function row(string $key): ?string
    {
        try {
            $row = DB::table('app_settings')->where('key', $key)->first(['value']);
        } catch (Throwable) {
            return null; // walang app_settings table: .env lang
        }

        return $row === null ? null : (string) $row->value;
    }

    private static function blankToNull(mixed $v): ?string
    {
        $v = trim((string) $v);

        return $v !== '' ? $v : null;
    }
}
