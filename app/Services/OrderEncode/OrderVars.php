<?php

namespace App\Services\OrderEncode;

use App\Models\MacroOutput;
use App\Models\OrderEncode;

/**
 * Ang mga order_* variable ng flow: ang pinakabagong order row ng customer sa page na ito (PAGE + PSID, sa huling 7 araw;
 * ang pinakabago ng huling 24 oras ay iyon din kapag mayroon) at ang huling encode nito. Binabasa tuwing kailangan ng
 * takbo, kaya ang sequence step pagkatapos ng delay ay nakikita ang resulta ng encode na tumakbo sa background.
 */
class OrderVars
{
    public const NAMES = ['order_row_id', 'order_status', 'order_checker', 'order_hold_reason', 'order_province', 'order_city', 'order_barangay',
        'order_address', 'order_name', 'order_phone', 'order_encode_state'];
    public const DAYS = 7;
    /** Ang mga hold ng panuntunan na tungkol sa address (gaya ng FlowEncode::holdReason). */
    private const ADDRESS_RULES = ['R2', 'R4', 'R6'];

    /** @return array<string, string> lahat ng NAMES; blangko (at state "none") kapag walang row */
    public static function read(string $page, string $psid): array
    {
        $out = array_fill_keys(self::NAMES, '');
        $out['order_encode_state'] = 'none';
        if (trim($page) === '' || trim($psid) === '' || $psid === '0') return $out;

        $row = MacroOutput::query()->where('PAGE', $page)->where('botcake_psid', $psid)
            ->where('ts_date', '>=', now('Asia/Manila')->subDays(self::DAYS)->toDateString())->orderByDesc('id')->first();
        if (!$row) return $out;

        $values = [];
        foreach (['PROVINCE', 'CITY', 'BARANGAY', 'ADDRESS', 'FULL NAME', 'PHONE NUMBER'] as $c) $values[$c] = (string) $row->{$c};
        $latest = OrderEncode::where('macro_output_id', $row->id)->where('mode', 'live')->orderByDesc('id')->first();

        return [
            'order_row_id' => (string) $row->id, 'order_status' => trim((string) $row->STATUS), 'order_checker' => trim((string) $row->{'APP SCRIPT CHECKER'}),
            'order_hold_reason' => self::holdReason($row, $values),
            'order_province' => $values['PROVINCE'], 'order_city' => $values['CITY'], 'order_barangay' => $values['BARANGAY'], 'order_address' => $values['ADDRESS'],
            'order_name' => $values['FULL NAME'], 'order_phone' => $values['PHONE NUMBER'],
            'order_encode_state' => $latest ? self::state($latest) : 'none',
        ];
    }

    /**
     * Ang kalagayan ng encode ng isang order_encodes row: none (upload lang), queued, running (tumatakbo ang encode, o si
     * Astra ay naka-queue o tumatakbo pa), done, failed.
     */
    public static function state(OrderEncode $o): string
    {
        $encode = (array) ($o->input['encode'] ?? []);

        return match (true) {
            $o->status === 'uploaded' => 'none',
            $o->status === 'queued' => !empty($encode['started_at']) ? 'running' : 'queued',
            $o->status === 'done' && !empty($encode['astra_queued']) && empty($o->input['astra']) => 'running',
            $o->status === 'failed' => 'failed',
            default => 'done',
        };
    }

    /**
     * Bakit hindi PROCEED (FlowEncode::holdReason): blangko kapag final ang row o hindi pa na-encode. Ang resulta ng huling
     * encode (at ni Astra) kapag mayroon; kung wala (hal. row ng import o ng night run), ang HOLD sa AI ANALYZE at ang
     * APP SCRIPT CHECKER ng row.
     */
    private static function holdReason(MacroOutput $row, array $values): string
    {
        if (FlowEncode::final($row)) return '';
        $encoded = OrderEncode::where('macro_output_id', $row->id)->where('mode', 'live')->where('status', 'done')->whereNotNull('outputs')->orderByDesc('id')->first();
        if ($encoded && (array) $encoded->outputs) {
            $astra = is_array($encoded->input['astra'] ?? null) ? $encoded->input['astra'] : null;

            return FlowEncode::holdReason((array) $encoded->outputs, $values, $astra);
        }
        $checker = trim((string) $row->{'APP SCRIPT CHECKER'});
        $rule = preg_match('/^\s*HOLD\s+(R\d+)/i', (string) $row->{'AI ANALYZE'}, $m) ? strtoupper($m[1]) : null;
        if ($checker === '' && $rule === null) return '';
        // Ang hold ng panuntunan ay may "TO FIX" sa checker: ang R5 (doble) ay hindi tungkol sa address.
        $final = $rule !== null && !in_array($rule, self::ADDRESS_RULES, true) ? '✅' : $checker;

        return FlowEncode::holdReason(['hold' => $rule ? ['rule' => $rule] : null, 'final' => $final], $values, null);
    }
}
