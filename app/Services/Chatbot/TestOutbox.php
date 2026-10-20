<?php

namespace App\Services\Chatbot;

use Throwable;

/**
 * Ang Test tab: walang ipinapadala sa Botcake; itinatala lang sa takbo. Ang sheet row ay ipinapakita lang, maliban kung
 * naka-on ang "Write sheet rows for real" ($sheet): saka ito isinusulat sa totoong spreadsheet sa parehong ChatbotSheet
 * ng live. Ang error ng Google ay sinasabi sa simpleng salita (walang raw na mensahe o credentials).
 */
class TestOutbox implements Outbox
{
    public function __construct(private ?ChatbotSheet $sheet = null)
    {
    }

    public function send(FlowRun $run, string $nodeId, array $messages): array
    {
        return ['status' => 'test', 'error' => null];
    }

    public function appendRow(FlowRun $run, string $nodeId, string $spreadsheetId, string $tab, array $row): array
    {
        if ($this->sheet === null) return ['status' => 'test', 'error' => null];
        try {
            $range = $this->sheet->append($spreadsheetId, $tab, $row);
        } catch (Throwable $e) {
            report($e);
            return ['status' => 'failed', 'error' => self::plain($e, $tab)];
        }
        $at = is_string($range) && preg_match('/![A-Z]+(\d+)/', $range, $m) ? ', row ' . $m[1] . ' (' . $range . ')' : '';

        return ['status' => 'sent', 'error' => null, 'range' => $range, 'note' => 'Sheet row written: ' . $tab . $at];
    }

    /** Ang error ng Google sa simpleng salita, kasama ang email ng service account na pagbabahagian. */
    private static function plain(Throwable $e, string $tab): string
    {
        $code = (int) $e->getCode();
        $text = mb_strtolower($e->getMessage());
        if ($code === 403 || $code === 404 || str_contains($text, 'permission') || str_contains($text, 'not found: spreadsheet')) {
            $who = self::serviceAccount();
            return 'The service account can\'t open this spreadsheet. ' . ($who ? 'Share it with ' . $who . ' as Editor, then try again.' : 'Share it with the Likha service account as Editor, then try again.');
        }
        if (str_contains($text, 'unable to parse range')) return 'The tab "' . $tab . '" was not found in the spreadsheet. Check the tab name in the Sheet step.';

        return 'Google refused the row. Check the spreadsheet link and the tab name, then try again.';
    }

    /** Ang email ng service account (config, o ang client_email ng credentials ng Likha); walang ibang bahagi ng credentials. */
    public static function serviceAccount(): ?string
    {
        $email = (string) config('services.chatbot.sheet_account', '');
        if ($email === '' && is_file($file = storage_path('app/credentials.json'))) {
            $email = (string) (json_decode((string) file_get_contents($file), true)['client_email'] ?? '');
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}