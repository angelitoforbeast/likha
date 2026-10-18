<?php

namespace App\Services;

/**
 * Ang pangalan ng bawat flow ID at ang template ng shipping flow, para sa /page-settings. Itinatala bilang system keys
 * sa page_settings: botcake.flow.<x>.name (ang pangalan, o marker) at botcake.template.shipping.text (ang body).
 */
class BotcakeFlowNames
{
    /** Mga marker sa value: wala sa listahan ng page, bura na sa Botcake, o hindi nasuri (may dahilan pagkatapos). */
    public const NOT_FOUND = '!wala';
    public const REMOVED   = '!bura';
    public const UNCHECKED = '!hindi nasuri: ';

    /** Ang ipinapakita kapalit ng {{1}}: doon pumapasok ang mensahe ng chatblast sequence. */
    public const SLOT = '(mensahe ng sequence)';

    /**
     * Ang linyang ipinapakita sa ilalim ng cell para sa isang naitalang pangalan o body.
     * @return array{line:string, tone:string} tone = red, grey o blangko
     */
    public static function line(string $value): array
    {
        if ($value === self::NOT_FOUND) return ['line' => 'Wala sa Botcake', 'tone' => 'red'];
        if ($value === self::REMOVED) return ['line' => 'Bura na sa Botcake', 'tone' => 'red'];
        if (str_starts_with($value, self::UNCHECKED)) return ['line' => substr($value, 1), 'tone' => 'grey'];

        return ['line' => str_replace('{{1}}', self::SLOT, $value), 'tone' => ''];
    }
}
