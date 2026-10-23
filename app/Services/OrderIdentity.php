<?php

namespace App\Services;

use App\Models\MacroOutput;

/**
 * Paano hinahanap ang umiiral na order ng isang row ng Likha o Macro import. May fb_name: TIMESTAMP + PAGE + fb_name
 * (dati pa). Walang fb_name: TIMESTAMP + PAGE + phone. Walang fb_name at walang phone: Macro lang ang may $allUserInput,
 * at ang tugma ay ang kapareho ring TIMESTAMP + PAGE + all_user_input; kung wala, walang ginagalaw na order.
 */
class OrderIdentity
{
    /** Ang flag sa AI ANALYZE ng bagong order na walang pangalan at walang phone. */
    public const NO_NAME_NO_PHONE = 'No name / no phone';

    public static function find(?string $timestamp, ?string $page, string $fbName, string $phone, ?string $allUserInput = null): ?MacroOutput
    {
        $base = MacroOutput::where([['TIMESTAMP', '=', $timestamp], ['PAGE', '=', $page]]);

        if (trim($fbName) !== '') {
            return $base->where('fb_name', '=', $fbName)->first();
        }

        if ($phone !== '') {
            // Maaaring iba ang anyo ng phone sa naka-save (espasyo, +63): ikinukumpara ang normalized.
            return $base->orderBy('id')->get()
                ->first(fn (MacroOutput $o) => BotcakeCustomerMatcher::normPhone($o->{'PHONE NUMBER'}) === $phone);
        }

        if ($allUserInput !== null && trim($allUserInput) !== '') {
            return $base->where('all_user_input', '=', $allUserInput)->orderBy('id')->first();
        }

        return null;
    }

    /** Ang FB NAME sa unang linya ng all_user_input; '' kapag blangko (hindi ang susunod na linya). */
    public static function fbNameFromInput(?string $input): string
    {
        return preg_match('/FB\s*NAME:[ \t]*(.*?)\r?\n/i', (string) $input, $m) ? $m[1] : '';
    }
}
