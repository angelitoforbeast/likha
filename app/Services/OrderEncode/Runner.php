<?php

namespace App\Services\OrderEncode;

use App\Models\OrderEncode;
use Throwable;

/**
 * Ang pagpapatakbo ng isang OrderEncode: binabasa ang input at ang listahan ng lugar, pinapatakbo ang Encoder, itinatabi ang
 * resulta at mga hakbang. Shadow: order_encodes lang ang sinusulat. Live: pagkatapos, ang macro_output row (LiveOrder); "done"
 * ay pagkatapos lang nito, para ang pumalyang sulat ay maulit ng job. Ang live na order na hindi na maa-encode ay isinusulat
 * sa sheet (fail), para walang mawalang order.
 */
class Runner
{
    public function __construct(private Settings $settings, private LocationStore $store, private Sheets $sheets, private LiveOrder $live)
    {
    }

    public function run(OrderEncode $o): void
    {
        if ($o->status === 'done') return; // ang ulit na job ay walang ginagawa

        $macroId = $this->settings->sheetId($o->page, 'macro_sheet');
        $afterId = $this->settings->sheetId($o->page, 'compare_sheet');
        $macroRows = $macroId ? $this->store->read(LocationStore::MACRO, $macroId) : null;
        $afterRows = $afterId ? $this->store->read(LocationStore::AFTER, $afterId) : null;
        if (!$macroRows || !$afterRows) {
            $this->fail($o, 'No location list yet. Set macro_sheet and compare_sheet for the page, then run order-encode:sync-locations.');

            return;
        }

        $key = $this->settings->key();
        $steps = new Steps($key);
        $gpt = new Gpt($key, $this->settings->modelExtract(), $this->settings->modelChecker(), $steps, (int) config('services.openai.timeout', 60));

        $in = $this->input($o);
        $steps->add('input', 'OK', ['blank' => array_values(array_filter(Input::FIELDS, fn ($f) => trim($in->get($f)) === ''))]);
        $res = (new Encoder(new Macro($macroRows), new Locations($afterRows), $gpt, $steps))->run($in);

        $o->update([
            'input' => ['row' => $o->input['row'] ?? [], 'fields' => $in->v],
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
        if ($o->mode === 'live' && $o->status !== 'done') {
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

    /** Ang row ng sheet step, binasa ayon sa header ng tab (kung mababasa) o sa ayos ng column. */
    private function input(OrderEncode $o): Input
    {
        $headers = null;
        try {
            $headers = $this->sheets->headers($o->spreadsheet_id, $o->tab);
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
