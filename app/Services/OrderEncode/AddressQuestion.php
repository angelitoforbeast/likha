<?php

namespace App\Services\OrderEncode;

use App\Jobs\RunSpotAstra;
use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Services\Chatbot\ChatbotSequences;
use Throwable;

/**
 * Pagkatapos ng live na encode na hindi PROCEED: ang Astra on the spot (kung naka-on), saka ang tanong sa address. Kapag ang
 * row ay hold dahil sa address (R2, R4, R6, ang checker ng address na hindi ✅, o blangkong lugar/address) at hindi R5, ang
 * customer ay isinasama nang isang beses kada row sa sequence ng setting. Hindi kailanman nagpapabagsak ng encode.
 */
class AddressQuestion
{
    public const VIA = 'order-encode';
    /** Ang mga hold ng panuntunan na tungkol sa address (R5 = doble, hindi tinatanong). */
    private const ADDRESS_RULES = ['R2', 'R4', 'R6'];

    public function __construct(private Settings $settings, private ChatbotSequences $sequences)
    {
    }

    /** Ang live na encode ay tapos: walang gagawin kapag PROCEED; Astra kapag naka-on, kung hindi ay ang tanong agad. */
    public function afterEncode(OrderEncode $o): void
    {
        try {
            $row = $o->macro_output_id ? MacroOutput::find($o->macro_output_id) : null;
            if (!$row || trim((string) $row->STATUS) !== '') return;
            if ($this->settings->spotAstra()) {
                $o->update(['input' => array_merge((array) $o->input, ['astra' => ['status' => 'queued']])]);
                try {
                    RunSpotAstra::dispatch($o->id);

                    return;
                } catch (Throwable $e) {
                    report($e); // hindi na-queue si Astra: ang tanong ay hindi naiiwang nakabitin
                    $o->update(['input' => array_merge((array) $o->input, ['astra' => ['status' => 'not_queued']])]);
                }
            }
            $this->ask($o, null);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Ang tanong sa address. $astra = ang resulta ni Astra (status, final_code), null kapag hindi tumakbo o pumalya.
     */
    public function ask(OrderEncode $o, ?array $astra): void
    {
        try {
            $o->refresh();
            $row = $o->macro_output_id ? MacroOutput::find($o->macro_output_id) : null;
            if (!$row || trim((string) $row->STATUS) !== '' || !$this->addressHold($o, $row, $astra)) return;

            $asked = OrderEncode::where('macro_output_id', $row->id)->where('id', '<>', $o->id)->get(['id', 'input'])->contains(fn ($e) => !empty($e->input['asked']));
            if ($asked) {
                $this->note($o, 'address question already asked for this row: left for staff');

                return;
            }
            $sequence = $this->settings->addressSequence();
            $pageId = (int) ($o->input['page_id'] ?? 0);
            if ($sequence === null || $pageId <= 0 || !$o->psid) {
                $this->note($o, 'address hold: no address question sequence set, left for staff');

                return;
            }
            $res = $this->sequences->subscribe($sequence, $pageId, (int) $o->psid, self::VIA);
            $note = 'address question: ' . $res['result'] . (isset($res['reason']) ? ' (' . $res['reason'] . ')' : '') . ($res['result'] === 'refused' ? ', left for staff' : '');
            $o->refresh();
            $input = array_merge((array) $o->input, ['live_notes' => array_merge((array) ($o->input['live_notes'] ?? []), [$note])]);
            if (in_array($res['result'], ['subscribed', 'already'], true)) $input['asked'] = ['result' => $res['result'], 'sequence' => $sequence, 'at' => now()->toDateTimeString()];
            $o->update(['input' => $input]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Hold dahil sa address? Si Astra (kapag tumakbo) ang huling salita sa address; kung hindi, ang encode. */
    private function addressHold(OrderEncode $o, MacroOutput $row, ?array $astra): bool
    {
        $out = (array) $o->outputs;
        $rule = $out['hold']['rule'] ?? null;
        if ($rule === 'R5') return false;
        $blank = collect(['PROVINCE', 'CITY', 'BARANGAY', 'ADDRESS'])->contains(fn ($c) => trim((string) $row->{$c}) === '');
        if ($astra !== null && in_array($astra['status'] ?? '', ['fixed', 'partial'], true)) return ($astra['final_code'] ?? '') !== '✅' || $blank;

        return in_array($rule, self::ADDRESS_RULES, true) || ($out['final'] ?? '') !== '✅' || $blank;
    }

    private function note(OrderEncode $o, string $note): void
    {
        $o->update(['input' => array_merge((array) $o->input, ['live_notes' => array_merge((array) ($o->input['live_notes'] ?? []), [$note])])]);
    }
}
