<?php

namespace App\Services\OrderEncode;

use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Services\Chatbot\ChatbotEvents;
use App\Services\Chatbot\FlowGraph;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Ang pagpapatakbo ng isang OrderEncode: binabasa ang input at ang listahan ng lugar, pinapatakbo ang Encoder, itinatabi ang
 * resulta at mga hakbang. Shadow: order_encodes lang ang sinusulat. Live: pagkatapos, ang macro_output row (LiveOrder); "done"
 * ay pagkatapos lang nito, para ang pumalyang sulat ay maulit ng job. Ang live na order na hindi na maa-encode ay isinusulat
 * sa sheet (fail), para walang mawalang order. Isang row kada customer kada araw: ang live ay isa-isa kada customer (lock),
 * at ang row ng customer ay hinahanap bago ang encode at R5.
 */
class Runner
{
    /** Ang lock ng customer: mas mahaba sa timeout ng job (600 s), para hindi ito mawala habang tumatakbo ang encode. */
    public const CUSTOMER_LOCK_SECONDS = 660;

    public function __construct(private Settings $settings, private LocationList $list, private Sheets $sheets, private LiveOrder $live, private Conversation $conversation)
    {
    }

    public function run(OrderEncode $o): void
    {
        if ($o->status === 'done') return; // ang ulit na job ay walang ginagawa
        if ($o->mode !== 'live') {
            $this->encode($o, $this->input($o));

            return;
        }
        // Ang dalawang takbo ng isang customer (double tap, dalawang worker) ay hindi sabay: ang ikalawa ay naghihintay at
        // nakikita ang row ng una. Lampas sa hintay: ang job ay inuulit ng queue.
        Cache::lock(self::customerKey($o), self::CUSTOMER_LOCK_SECONDS)->block((int) config('order_encode.customer_lock_wait', 300), fn () => $this->live($o));
    }

    public static function customerKey(OrderEncode $o): string
    {
        return 'order-encode:customer:' . md5($o->page . '|' . ($o->psid ? (string) $o->psid : 'name:' . (string) ($o->input['row'][2] ?? '')));
    }

    private function live(OrderEncode $o): void
    {
        $o->refresh();
        if ($o->status === 'done') return;
        $in = $this->input($o);
        if (!$o->macro_output_id) {
            $found = $this->live->find($o, $in);
            if (isset($found['final'])) {
                $this->notAccepted($o, $found['final']);

                return;
            }
            // Ang bukas na row ng customer: naka-link bago ang encode, kaya hindi ito binibilang ng R5 at hindi ito nadodoble ng fallback.
            if (isset($found['open'])) $o->update(['macro_output_id' => $found['open']->id, 'input' => array_merge((array) $o->input, ['linked' => true])]);
        }
        $in = $this->conversation->build($o, $in); // ang mga mensahe ng order na ito lang (at ang orihinal na order kapag sagot)
        if (!$this->encode($o, $in)) return;

        $o->refresh();
        if (!empty($o->input['linked'])) {
            $id = (int) $o->macro_output_id;
            $updated = RowLock::hold($id, (int) config('order_encode.row_lock_wait', 300), fn () => ($row = MacroOutput::find($id)) && $this->live->update($row, $o));
            if (!$updated) $this->note($o, 'row ' . $id . ' got a status meanwhile: not changed');
        } else {
            $this->live->create($o);
        }
        $o->update(['status' => 'done']);
        app(AddressQuestion::class)->afterEncode($o->fresh()); // hindi PROCEED: Astra on the spot, saka ang tanong sa address
    }

    /** Ang final na row ng customer ngayong araw: walang ginagalaw; ang order ay may tala at chat event. */
    private function notAccepted(OrderEncode $o, MacroOutput $row): void
    {
        $note = LiveOrder::NOT_ACCEPTED . $row->id;
        $o->update(['status' => 'done', 'input' => array_merge((array) $o->input, ['not_accepted' => $row->id, 'live_notes' => [$note]])]);
        $this->event($o, ['result' => 'not_accepted', 'error' => $note]);
    }

    /** Isang tala sa order (makikita sa /order-encode). */
    private function note(OrderEncode $o, string $note): void
    {
        $o->update(['input' => array_merge((array) $o->input, ['live_notes' => array_merge((array) ($o->input['live_notes'] ?? []), [$note])])]);
    }

    /** Isang encode_order event sa chat ng customer (kapag kilala ang page id ng takbo). */
    private function event(OrderEncode $o, array $detail): void
    {
        $pageId = (int) ($o->input['page_id'] ?? 0);
        if ($pageId > 0 && $o->psid) ChatbotEvents::write($pageId, (int) $o->psid, 'encode_order', $detail, $o->flow_id, $o->input['node'] ?? null);
    }

    /** Ang encode mismo; false kapag hindi na-encode (fail). */
    private function encode(OrderEncode $o, Input $in): bool
    {
        // improved: mga barangay na eksaktong gaya ng sa J&T file at ang mga panuntunan ng spec 135; sheet: kopya ng dalawang script.
        $improved = $this->settings->rules() === Rules::NAME;
        $macro = $this->list->macro();
        $locations = $improved ? $this->list->exactLocations() : $this->list->locations();
        if (!$macro || !$locations) {
            $this->fail($o, 'The address list (jnt_address.txt) is missing or empty.');

            return false;
        }

        $key = $this->settings->key();
        $steps = new Steps($key);
        $gpt = new Gpt($key, $this->settings->modelExtract(), $this->settings->modelChecker(), $steps, (int) config('services.openai.timeout', 60));

        $steps->add('input', 'OK', ['blank' => array_values(array_filter(Input::FIELDS, fn ($f) => trim($in->get($f)) === ''))]);
        $rules = $improved ? new Rules($locations, new Notes()) : null;
        $res = (new Encoder($macro, $locations, $gpt, $steps, $rules))->run($in);
        if ($rules) $res['outputs'] = $this->duplicates($o, $in, $res['outputs'], $steps);

        $o->update([
            'input' => array_merge((array) $o->input, ['fields' => $in->v]),
            'outputs' => $res['outputs'], 'steps' => $steps->log, 'usage' => self::usage($steps->log), 'encoded_at' => now(), 'error' => $res['error'],
        ]);
        if ($o->mode !== 'live') $o->update(['status' => 'done']);

        return true;
    }

    /**
     * Hindi na maa-encode: failed. Ang live na order ay isinusulat sa sheet gaya ng dati; ang shadow ay namarkahan lang.
     */
    public function fail(OrderEncode $o, string $why): void
    {
        $why = preg_replace('/sk-[A-Za-z0-9_\-]{8,}/', '[key]', $why) ?? '';
        if ($o->macro_output_id && $o->mode !== 'import') { // ang import row ay may macro_output_id mula sa simula: failed pa rin, para hindi maihambing
            // Re-encode ng bukas na row ng customer: ang row ay hindi ginalaw at walang isusulat sa sheet (doble ang order).
            if (!empty($o->input['linked'])) {
                $o->update(['status' => 'failed', 'error' => mb_substr($why . ' The re-encode left row ' . $o->macro_output_id . ' unchanged.', 0, 500)]);

                return;
            }
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

    /** R5: ang order na may kapareho sa parehong araw ay hold (reason "Possible duplicate: <order ids>"). Ang naka-link na order: ang araw ng row nito. */
    private function duplicates(OrderEncode $o, Input $in, array $outputs, Steps $steps): array
    {
        if (!($outputs['proceed'] ?? false)) return $outputs;
        $day = $o->macro_output_id ? substr((string) MacroOutput::whereKey($o->macro_output_id)->value('ts_date'), 0, 10) : '';
        $ids = (new Duplicates())->find($o, $in, $outputs, $day !== '' ? $day : null);
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
