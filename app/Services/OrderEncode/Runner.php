<?php

namespace App\Services\OrderEncode;

use App\Models\OrderEncode;
use App\Services\Chatbot\FlowGraph;
use Throwable;

/**
 * Ang pagpapatakbo ng isang OrderEncode: binabasa ang input at ang listahan ng lugar, pinapatakbo ang Encoder, itinatabi ang
 * resulta at mga hakbang. Shadow: order_encodes lang ang sinusulat. Live: pagkatapos, ang macro_output row (LiveOrder); "done"
 * ay pagkatapos lang nito, para ang pumalyang sulat ay maulit ng job. Ang live na order na hindi na maa-encode ay isinusulat
 * sa sheet (fail), para walang mawalang order.
 */
class Runner
{
    public function __construct(private Settings $settings, private LocationList $list, private Sheets $sheets, private LiveOrder $live)
    {
    }

    public function run(OrderEncode $o): void
    {
        if ($o->status === 'done') return; // ang ulit na job ay walang ginagawa

        // improved: mga barangay na eksaktong gaya ng sa J&T file at ang mga panuntunan ng spec 135; sheet: kopya ng dalawang script.
        $improved = $this->settings->rules() === Rules::NAME;
        $macro = $this->list->macro();
        $locations = $improved ? $this->list->exactLocations() : $this->list->locations();
        if (!$macro || !$locations) {
            $this->fail($o, 'The address list (jnt_address.txt) is missing or empty.');

            return;
        }

        $key = $this->settings->key();
        $steps = new Steps($key);
        $gpt = new Gpt($key, $this->settings->modelExtract(), $this->settings->modelChecker(), $steps, (int) config('services.openai.timeout', 60));

        $in = $this->input($o);
        $steps->add('input', 'OK', ['blank' => array_values(array_filter(Input::FIELDS, fn ($f) => trim($in->get($f)) === ''))]);
        $rules = $improved ? new Rules($locations, new Notes()) : null;
        $res = (new Encoder($macro, $locations, $gpt, $steps, $rules))->run($in);
        if ($rules) $res['outputs'] = $this->duplicates($o, $in, $res['outputs'], $steps);

        $o->update([
            'input' => array_merge((array) $o->input, ['fields' => $in->v]),
            'outputs' => $res['outputs'], 'steps' => $steps->log, 'usage' => self::usage($steps->log), 'encoded_at' => now(), 'error' => $res['error'],
        ]);

        if ($o->mode === 'live') $this->live->create($o->fresh());
        $o->update(['status' => 'done']);
    }

    /**
     * Hindi na maa-encode: failed. Ang live na order ay isinusulat sa sheet gaya ng dati; ang shadow ay namarkahan lang.
     */
    public function fail(OrderEncode $o, string $why): void
    {
        $why = preg_replace('/sk-[A-Za-z0-9_\-]{8,}/', '[key]', $why) ?? '';
        if ($o->macro_output_id && $o->mode !== 'import') { // ang import row ay may macro_output_id mula sa simula: failed pa rin, para hindi maihambing
            // Nasa macro_output na ang order: hindi na isusulat sa sheet (doble).
            $o->update(['status' => 'done', 'error' => mb_substr($why . ' The order is already in macro_output.', 0, 500)]);

            return;
        }
        // Isang sulat lang sa sheet: hindi na inuulit ng ulit na takbo ng failed na order, at hindi kung naisulat na ng step (kasabay na sulat)
        // o kung walang sheet na pagsusulatan (Encode order step na "Continue without saving").
        $sheetId = FlowGraph::spreadsheetId($o->spreadsheet_id);
        if ($o->mode === 'live' && $o->status === 'queued' && $sheetId !== null && empty($o->input['sheet_written'])) {
            try {
                $this->sheets->append($o->spreadsheet_id, $o->tab, (array) ($o->input['row'] ?? []));
                $why .= ' The row was written to the sheet instead.';
            } catch (Throwable $e) {
                report($e);
                $why .= ' The row could NOT be written to the sheet either.';
            }
        }
        $o->update(['status' => 'failed', 'error' => mb_substr($why, 0, 500)]);
    }

    /** R5: ang order na may kapareho sa parehong araw ay hold (reason "Possible duplicate: <order ids>"). */
    private function duplicates(OrderEncode $o, Input $in, array $outputs, Steps $steps): array
    {
        if (!($outputs['proceed'] ?? false)) return $outputs;
        $ids = (new Duplicates())->find($o, $in, $outputs);
        if (!$ids) return $outputs;

        $hold = ['rule' => 'R5', 'reason' => 'Possible duplicate: ' . implode(', ', $ids)];
        $steps->add('rule_hold', 'HOLD:R5', $hold);

        return ['proceed' => false, 'hold' => $hold] + $outputs;
    }

    /** Ang row ng sheet step, binasa ayon sa header ng tab (kung mababasa) o sa ayos ng column. */
    private function input(OrderEncode $o): Input
    {
        $headers = null;
        try {
            // Ang row ng Encode order step at ng Likha import ay laging nasa ayos na A..H, kahit ano ang header ng sheet.
            if (empty($o->input['node']) && ($o->input['source'] ?? '') !== 'import') $headers = $this->sheets->headers($o->spreadsheet_id, $o->tab);
        } catch (Throwable) {
            // walang header: ang ayos ng column
        }

        return Input::fromRow((array) ($o->input['row'] ?? []), $headers);
    }

    /** @return array<string, array{requests: int, tokens_in: int, tokens_out: int}> */
    public static function usage(array $log): array
    {
        $out = [];
        foreach ($log as $s) {
            if (empty($s['model'])) continue;
            $out[$s['model']] ??= ['requests' => 0, 'tokens_in' => 0, 'tokens_out' => 0];
            $out[$s['model']]['requests'] += (int) ($s['requests'] ?? 0);
            $out[$s['model']]['tokens_in'] += (int) ($s['tokens_in'] ?? 0);
            $out[$s['model']]['tokens_out'] += (int) ($s['tokens_out'] ?? 0);
        }

        return $out;
    }
}
