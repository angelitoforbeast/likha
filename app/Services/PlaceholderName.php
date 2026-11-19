<?php

namespace App\Services;

/**
 * Ang pangalang ipinapakita ng Facebook kapag nakatago ang profile ng customer ("Facebook User"): hindi ito pangalan ng
 * isang tao, daan-daang customer ng iisang page ang may ganito. Kaya hindi ito kailanman susi ng customer (spec 307):
 * ang order na may ganitong pangalan ay parang walang pangalan, PSID o phone lang ang makakakilala sa kanya.
 */
class PlaceholderName
{
    /** Ang mga placeholder na pangalan (anumang laki ng titik at espasyo). Ang blangko ay placeholder din. */
    public const NAMES = ['Facebook User', 'Messenger User', 'Instagram User'];

    public static function is(?string $name): bool
    {
        $key = self::squash($name);
        if ($key === '') return true;

        static $names = null;
        $names ??= array_map(fn ($n) => self::squash($n), self::NAMES);

        return in_array($key, $names, true);
    }

    /**
     * Maliit na titik, walang anumang espasyo (tab, no-break space, zero-width at iba pang hindi nakikitang character),
     * kaya pareho ang "Facebook User", "facebook  user" at "FacebookUser".
     */
    private static function squash(?string $name): string
    {
        $s = preg_replace('/[\s\p{Z}\p{Cf}]+/u', '', (string) $name);

        return mb_strtolower($s ?? (string) $name, 'UTF-8');
    }
}
