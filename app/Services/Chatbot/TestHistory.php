<?php

namespace App\Services\Chatbot;

/**
 * Ang usapan ng test customer ng Test tab, para sa AI step (gaya ng history ng live): ang kanyang mga mensahe at ang mga
 * naipadala ng bot, sa ayos, sa field na __test_history (JSON) ng test PSID lang. Hanggang 100. Binubura ng Reset.
 */
class TestHistory
{
    public const MAX = 100;

    /** @return array<int, array{role: string, content: string}> */
    public static function read(CustomerFields $fields): array
    {
        $list = json_decode((string) $fields->get(CustomerFields::TEST_HISTORY), true);

        return is_array($list) ? array_values(array_filter($list, fn ($m) => is_array($m) && isset($m['role'], $m['content']))) : [];
    }

    /**
     * Idagdag ang mensahe ng test customer (kapag mayroon) at ang mga naipadala ng takbo; kapag dumaan sa "Clear AI
     * memory", ang mga padala pagkatapos lang (nauna roon ang mensahe).
     */
    public static function record(CustomerFields $fields, ?string $input, FlowRun $run): void
    {
        if ($fields->psid !== CustomerFields::TEST_PSID) return; // ang test customer lang
        $list = self::read($fields);
        if ($input !== null && trim($input) !== '' && $run->aiClearedAt === null) $list[] = ['role' => 'user', 'content' => $input];
        foreach (array_slice($run->sends, $run->aiClearedAt ?? 0) as $s) {
            $text = self::text($s['messages']);
            if (trim($text) !== '') $list[] = ['role' => 'assistant', 'content' => $text];
        }
        $fields->set(CustomerFields::TEST_HISTORY, json_encode(array_slice($list, -self::MAX), JSON_UNESCAPED_UNICODE));
    }

    /** Ang text ng mga mensahe ng isang padala (gaya ng history ng live: text lang). */
    public static function text(array $messages): string
    {
        return implode("\n", array_filter(array_map(fn ($m) => is_array($m) ? (string) ($m['text'] ?? '') : '', $messages)));
    }
}