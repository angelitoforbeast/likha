<?php

namespace App\Services\Chatbot;

/**
 * Ang iisang interpolator ng chatbot: {{pangalan}}. Ang pangalan ay hindi case-sensitive at ang espasyo at _ ay iisa
 * ("Prompt 1" = prompt_1 = PROMPT 1). Ang hindi kilalang variable ay blangko.
 */
class ChatbotVars
{
    public const PATTERN = '/\{\{\s*([^{}]{1,100}?)\s*\}\}/u';

    /** Ang anyo ng pangalan na ginagamit sa paghahanap at sa key ng customer field: maliliit, iisang espasyo. */
    public static function norm(string $name): string
    {
        return mb_substr(trim(preg_replace('/[\s_]+/u', ' ', mb_strtolower($name))), 0, 100);
    }

    /**
     * @param array<string, string> $vars ang mga pangalan ay naka-norm na
     */
    public static function interpolate(string $template, array $vars): string
    {
        return (string) preg_replace_callback(self::PATTERN, fn ($m) => (string) ($vars[self::norm($m[1])] ?? ''), $template);
    }

    /**
     * Isang cell ng sheet row: gaya ng interpolate(), at kapag may variable ang template, laging may apostrophe sa
     * unahan (USER_ENTERED): ang laman ng customer o ng AI ay hindi kailanman formula, at hindi rin ginagawang numero o
     * petsa ng Google (hal. 09171234567 ay nananatiling may 0). Ang template na walang variable ay iniiwan gaya ng
     * isinulat ng gumawa ng flow (hal. =TODAY()).
     */
    public static function sheetCell(string $template, array $vars): string
    {
        $cell = (string) preg_replace_callback(self::PATTERN, fn ($m) => (string) ($vars[self::norm($m[1])] ?? ''), $template, -1, $count);
        if ($count === 0) return $cell;

        return "'" . $cell;
    }

    /**
     * Pagsamahin ang mga pinagmulan ayon sa ayos (ang huli ang nananaig), naka-norm ang mga pangalan.
     * @param array<int, array<string, ?string>> $sources
     * @return array<string, string>
     */
    public static function merge(array ...$sources): array
    {
        $out = [];
        foreach ($sources as $source) {
            foreach ($source as $name => $value) {
                $key = self::norm((string) $name);
                if ($key !== '' && !str_starts_with($key, '__')) $out[$key] = (string) $value;
            }
        }

        return $out;
    }
}
