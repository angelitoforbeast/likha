<?php

namespace App\Services\CardStatement;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Screenshot ng card statement → mga linya, sa OpenAI vision model (services.card_statement.model).
 * Ang sagot ay dapat mahigpit na JSON {"lines":[{sale_date, post_date, description, amount, is_credit}]};
 * kahit isang maling linya ay tanggi ang buong basa, para walang kalahating statement na maitugma.
 */
class StatementVision
{
    private const MAX_LINES = 500;

    // Dapat matapos bago ang 60 s ng nginx (fastcgi_read_timeout), kasama ang pag-save: 5 s kumonekta + 45 s sagot.
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT = 45;

    private const PROMPT = <<<'TXT'
This image is a credit card statement. Read every transaction row and every amount row exactly as printed.
Answer with JSON only, in this exact shape:
{"lines":[{"sale_date":"YYYY-MM-DD or null","post_date":"YYYY-MM-DD or null","description":"text as printed","amount":1234.56,"is_credit":false}]}
Rules: dates on the statement are MM/DD/YY; convert them to YYYY-MM-DD. amount is a positive number without commas.
is_credit is true when the amount is a credit (printed with a trailing "-" or "CR"), otherwise false.
Rows without dates (like PREVIOUS STATEMENT BALANCE) have null dates. Do not skip, merge or invent rows.
TXT;

    /** @return list<array{sale_date: ?string, post_date: ?string, description: string, amount: string, is_credit: bool}> */
    public function read(string $path, string $mime): array
    {
        try {
            $response = Http::withToken((string) config('services.openai.key'))
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'           => (string) config('services.card_statement.model', 'gpt-4o'),
                    'temperature'     => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages'        => [[
                        'role'    => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => self::PROMPT],
                            ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path)), 'detail' => 'high']],
                        ],
                    ]],
                ]);
        } catch (ConnectionException $e) {
            throw new StatementReadException('Hindi natapos basahin ang screenshot. Subukan ulit, o hatiin ang screenshot sa mas maliliit na bahagi.');
        }

        if (!$response->successful()) {
            throw new StatementReadException('Hindi nabasa ng AI ang screenshot (HTTP ' . $response->status() . '). Subukan ulit o PDF ang i-upload.');
        }

        $data = json_decode((string) $response->json('choices.0.message.content'), true);
        if (!is_array($data) || !isset($data['lines']) || !is_array($data['lines']) || !array_is_list($data['lines'])) {
            throw new StatementReadException('Hindi tamang JSON ang sagot ng AI sa screenshot. Subukan ulit.');
        }
        if (count($data['lines']) > self::MAX_LINES) {
            throw new StatementReadException('Sobrang daming linya sa sagot ng AI (hanggang ' . self::MAX_LINES . ').');
        }

        return array_map(fn ($l, $i) => $this->line($l, $i + 1), $data['lines'], array_keys($data['lines']));
    }

    private function line(mixed $l, int $n): array
    {
        $bad = fn (string $why) => new StatementReadException("Linya {$n} ng sagot ng AI: {$why}. Walang na-save; subukan ulit.");

        if (!is_array($l)) throw $bad('hindi object');
        if (array_diff(array_keys($l), ['sale_date', 'post_date', 'description', 'amount', 'is_credit']) !== []) throw $bad('may ibang field');
        foreach (['sale_date', 'post_date', 'description', 'amount', 'is_credit'] as $k) {
            if (!array_key_exists($k, $l)) throw $bad("walang {$k}");
        }
        foreach (['sale_date', 'post_date'] as $k) {
            if ($l[$k] !== null && !self::isDate($l[$k])) throw $bad("maling {$k}");
        }
        if (!is_string($l['description']) || trim($l['description']) === '') throw $bad('walang description');
        $amount = $l['amount'];
        if (!(is_int($amount) || is_float($amount) || (is_string($amount) && preg_match('/^\d+(\.\d+)?$/', $amount))) || (float) $amount < 0 || (float) $amount >= 1e10) {
            throw $bad('maling amount');
        }
        if (!is_bool($l['is_credit'])) throw $bad('maling is_credit');

        return [
            'sale_date'   => $l['sale_date'],
            'post_date'   => $l['post_date'],
            'description' => trim($l['description']), // pinuputol sa 255 pagkatapos ma-mask (CardReconcile), hindi bago
            'amount'      => number_format((float) $amount, 2, '.', ''),
            'is_credit'   => $l['is_credit'],
        ];
    }

    private static function isDate(mixed $v): bool
    {
        return is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
