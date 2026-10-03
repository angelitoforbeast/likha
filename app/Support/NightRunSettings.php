<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Iisang reader ng night-run settings (app_settings, CEO lang ang nagse-set) — gamit ng
 * routes/console.php, ng mga command at ng mga controller. Laging validated ang ibinabalik:
 * mali o kulang na value → default. Walang DB o walang app_settings → lahat OFF (walang masi-schedule).
 */
class NightRunSettings
{
    public const SWITCHES = ['night_macro_import_enabled', 'night_likha_import_enabled', 'night_astra_enabled'];

    public const TIMES = [
        'night_import_time_1'   => '01:00',
        'night_import_time_2'   => '02:00',
        'night_astra_time'      => '03:00',
        'night_astra_stop_time' => '07:00',
    ];

    public const MAX_ROWS_DEFAULT = 1500;
    public const MAX_ROWS_LIMIT   = 20000;

    // \z, hindi $: ang $ ay tumatanggap ng newline sa dulo.
    private const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d\z/';

    /**
     * @return array{night_macro_import_enabled: bool, night_likha_import_enabled: bool, night_astra_enabled: bool,
     *   night_import_time_1: string, night_import_time_2: string, night_astra_time: string,
     *   night_astra_stop_time: string, night_astra_max_rows: int}
     */
    public static function read(): array
    {
        $stored = [];
        try {
            if (Schema::hasTable('app_settings')) {
                $stored = AppSetting::whereIn('key', self::keys())->pluck('value', 'key')->all();
            }
        } catch (\Throwable $e) {
            // DB di available (hal. fresh migrate) — lahat OFF, defaults.
            $stored = [];
        }

        $out = [];
        foreach (self::SWITCHES as $key) {
            $out[$key] = ($stored[$key] ?? null) === '1';
        }

        foreach (self::TIMES as $key => $default) {
            $value = $stored[$key] ?? null;
            $out[$key] = is_string($value) && preg_match(self::TIME_PATTERN, $value) ? $value : $default;
        }

        // Dapat mas maaga ang Astra time kaysa stop time; kung hindi, parehong default.
        if ($out['night_astra_time'] >= $out['night_astra_stop_time']) {
            $out['night_astra_time']      = self::TIMES['night_astra_time'];
            $out['night_astra_stop_time'] = self::TIMES['night_astra_stop_time'];
        }

        $max = (string) ($stored['night_astra_max_rows'] ?? '');
        $out['night_astra_max_rows'] = ctype_digit($max) && (int) $max >= 1 && (int) $max <= self::MAX_ROWS_LIMIT
            ? (int) $max
            : self::MAX_ROWS_DEFAULT;

        return $out;
    }

    /**
     * Isulat ang mga value na NA-VALIDATE na ng caller (CEO route). Ang hindi night key ay hindi pinapansin.
     */
    public static function save(array $values): void
    {
        DB::transaction(function () use ($values) {
            foreach (self::keys() as $key) {
                if (!array_key_exists($key, $values)) {
                    continue;
                }

                $value = $values[$key];
                AppSetting::set($key, in_array($key, self::SWITCHES, true) ? ($value ? '1' : '0') : $value);
            }
        });
    }

    /** @return string[] */
    private static function keys(): array
    {
        return array_merge(self::SWITCHES, array_keys(self::TIMES), ['night_astra_max_rows']);
    }
}
