<?php

namespace App\Services\OrderEncode;

use App\Jobs\EncodeOrder;
use App\Jobs\ResumeChatbotAi;
use App\Jobs\RunEncodeAstra;
use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Services\AiCheckerRowRunner;
use App\Services\BotcakeCustomerMatcher;
use App\Services\Chatbot\ChatbotAiQueue;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\FlowRun;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Encode order" step: ine-encode ang row na isinulat ng Upload to macro sa parehong takbo, at hinihintay ito ng flow gaya ng
 * AI step. Ang hinihintay ay isang ticket sa chatbot_ai_requests (model "order-encode", status "encoding": hindi ito kinukuha
 * ng AI pool), kaya ang mga susunod na chat ng customer ay naghihintay din (__ai), at ang pagtuloy ay sa ResumeChatbotAi,
 * isang beses lang. Lampas sa "Wait up to": ang sweep ang nagtutuloy (failed, o ang resulta ng encode kapag si Astra na lang
 * ang hindi natapos). Ang resulta ay mga variable ng customer na magagamit ng Condition.
 */
class FlowEncode
{
    public const MODEL = 'order-encode';
    public const STATUS = 'encoding';
    public const VARS = ['encode_province', 'encode_city', 'encode_barangay', 'encode_address', 'encode_name', 'encode_phone', 'encode_proceed', 'encode_hold_reason', 'encode_row_id'];
    /** Ang mga hold ng panuntunan na tungkol sa address (R5 = doble). */
    private const ADDRESS_RULES = ['R2', 'R4', 'R6'];

    public function __construct(private Settings $settings, private LocationList $list)
    {
    }

    /**
     * Ang step sa takbo (live): ang resulta para sa FlowEngine (port, note, suspend). Ang mga variable ay nabura na.
     * @return array{port: ?string, note: string, suspend?: bool}
     */
    public function start(FlowRun $run, string $nodeId, array $c): array
    {
        $up = Upload::ofRun($run);
        if (!$up) return $this->failNow($run, $nodeId, 'No Upload to macro row in this run: nothing to encode', null);
        $row = MacroOutput::find($up->macro_output_id);
        if (!$row) return $this->failNow($run, $nodeId, 'The uploaded row is gone', null);

        // Final na ang row (PROCEED, courier status o waybill): walang ginagalaw, proceed yes.
        if (self::final($row) || $up->status !== 'uploaded') {
            $this->setVars($run, $this->result($up, $row, $up->input['astra'] ?? null));
            $run->event('encode_macro', $nodeId, ['result' => self::final($row) ? 'final' : 'already', 'row' => (string) $row->id]);

            return ['port' => 'next', 'note' => self::final($row) ? 'Row ' . $row->id . ' is final: not encoded' : 'Already encoded in this run: row ' . $row->id];
        }
        // Ang hinihintay ay itinutuloy sa main flow ng page: ang step ng sequence ay hindi naghihintay.
        if ($run->startedBy !== null || !Schema::hasTable(ChatbotAiQueue::TABLE)) return $this->failNow($run, $nodeId, 'Encode order cannot wait in a sequence step', (int) $row->id);
        if ($this->settings->key() === '' || !$this->list->has()) return $this->failNow($run, $nodeId, 'The encode is not ready (no OpenAI key or address file)', (int) $row->id);

        $wait = max(FlowGraph::ENCODE_WAIT_MIN, min(FlowGraph::ENCODE_WAIT_MAX, (int) ($c['wait'] ?? FlowGraph::ENCODE_WAIT)));
        $request = (string) Str::uuid();
        DB::table(ChatbotAiQueue::TABLE)->insert([
            'request_id' => $request, 'page_id' => (int) $run->pageId, 'psid' => $run->fields->psid, 'chat_id' => $run->chatId,
            'flow_id' => $run->flowId, 'node_id' => $nodeId, 'run_id' => $run->runId, 'page_name' => $run->pageName, 'model' => self::MODEL,
            'messages' => '[]', 'max_tokens' => 0,
            'context' => json_encode(['input' => $run->input, 'full_name' => $run->fullName, 'received_at' => $run->receivedAt,
                'ai_calls' => $run->aiCalls, 'steps' => $run->steps, 'row_id' => (int) $row->id], JSON_UNESCAPED_UNICODE),
            'status' => self::STATUS, 'deadline_at' => now()->addSeconds($wait), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $up->update(['status' => 'queued', 'input' => array_merge((array) $up->input, ['encode' => ['request' => $request, 'node' => $nodeId, 'astra' => !empty($c['astra'])]])]);
        // Ang marka ay bago ang job: ang resume ay itinutuloy lang kapag ito pa ang hinihintay ng customer.
        $run->fields->set(CustomerFields::AI, $request);
        try {
            EncodeOrder::dispatch($up->id, true);
        } catch (Throwable $e) {
            report($e);
            DB::table(ChatbotAiQueue::TABLE)->where('request_id', $request)->delete();
            $run->fields->clear(CustomerFields::AI);
            $up->update(['status' => 'uploaded']);

            return $this->failNow($run, $nodeId, 'The encode could not be queued', (int) $row->id);
        }
        $run->aiPending = true;
        $run->encodePending = true;
        $run->event('encode_pending', $nodeId, ['row' => (string) $row->id, 'wait' => $wait]);

        return ['port' => null, 'suspend' => true, 'note' => 'Waiting for the encode (up to ' . $wait . ' s)'];
    }

    /**
     * Itinuloy ang takbo: ang resulta ng ticket ($answer: ['ok', 'text', 'error', 'row']) bilang mga variable at port.
     * @return array{port: string, note: string}
     */
    public function resumed(FlowRun $run, string $nodeId, array $answer): array
    {
        $res = $answer['ok'] ? json_decode((string) $answer['text'], true) : null;
        if (!is_array($res)) return $this->failNow($run, $nodeId, 'Encode failed: ' . mb_substr((string) ($answer['error'] ?? 'no result'), 0, 150), isset($answer['row']) ? (int) $answer['row'] : null);

        $this->setVars($run, $res);
        $proceed = ($res['encode_proceed'] ?? '') === 'yes';
        $run->event('encode_macro', $nodeId, ['result' => $proceed ? 'proceed' : 'held', 'row' => (string) ($res['encode_row_id'] ?? ''), 'error' => (string) ($res['encode_hold_reason'] ?? '')]);

        return ['port' => 'next', 'note' => $proceed ? 'Encoded: PROCEED' : 'Encoded: held (' . ($res['encode_hold_reason'] ?? '') . ')'];
    }

    /** Ang lahat ng encode_* variable ay binubura (bawat takbo ng step, bago ang lahat). */
    public function clearVars(FlowRun $run): void
    {
        foreach (self::VARS as $v) $run->fields->clear($v);
    }

    /** @param array<string, string> $vars ang mga encode_* (ang wala o blangko ay binubura) */
    public function setVars(FlowRun $run, array $vars): void
    {
        foreach (self::VARS as $v) {
            $value = (string) ($vars[$v] ?? '');
            $value === '' ? $run->fields->clear($v) : $run->fields->set($v, $value);
        }
    }

    /**
     * Pagkatapos ng encode ng row (Runner): PROCEED o walang Astra → tapos; kung hindi, si Astra sa parehong row (ang
     * resulta ng encode ay naitabi muna sa ticket, para ang sweep ay may maibibigay kapag mabagal si Astra).
     */
    public function afterEncode(OrderEncode $o): void
    {
        $row = MacroOutput::find($o->macro_output_id);
        if (!$row) {
            $this->finish($o, null, 'The uploaded row is gone.'); // binura habang naghihintay: failed, hindi next

            return;
        }
        $result = $this->result($o, $row, null);
        if ($result['encode_proceed'] === 'yes' || empty($o->input['encode']['astra']) || !$this->settings->spotAstra()) {
            $this->finish($o, $result);

            return;
        }
        DB::table(ChatbotAiQueue::TABLE)->where('request_id', (string) $o->input['encode']['request'])->where('status', self::STATUS)
            ->update(['answer' => json_encode($result, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);

        try {
            RunEncodeAstra::dispatch($o->id); // sariling job sa astra-live: hindi hinahawakan ang encode worker habang si Astra
        } catch (Throwable $e) {
            report($e);
            $this->afterAstra($o, ['status' => 'not_queued']);
        }
    }

    /** Ang RunEncodeAstra job: si Astra sa row, saka ang pagtuloy ng flow. */
    public function astraStep(OrderEncode $o): void
    {
        $this->afterAstra($o, $this->astra($o, (int) $o->macro_output_id));
    }

    /** Ang resulta ni Astra (o ang pagpalya nito) sa order, saka ang pagtuloy na may huling value ng row. */
    public function afterAstra(OrderEncode $o, array $astra): void
    {
        $o->refresh();
        $notes = array_merge((array) ($o->input['live_notes'] ?? []), ['Astra: ' . $astra['status'] . (!empty($astra['final_code']) ? ' ' . $astra['final_code'] : '')]);
        $o->update(['input' => array_merge((array) $o->input, ['astra' => $astra + ['at' => now()->toDateTimeString()], 'live_notes' => $notes])]);
        $row = MacroOutput::find($o->macro_output_id);
        $row ? $this->finish($o, $this->result($o, $row, $astra)) : $this->finish($o, null, 'The uploaded row is gone.');
    }

    /**
     * Ang hinihintay ay tapos: answered (may resulta) o failed ($result null), saka ang pagtuloy. Kapag hindi na "encoding"
     * (lampas na sa deadline at naituloy na ng sweep), walang ginagawa: ang row lang ang naayos.
     */
    public function finish(OrderEncode $o, ?array $result, ?string $error = null): void
    {
        $request = (string) ($o->input['encode']['request'] ?? '');
        if ($request === '') return;
        $ok = DB::table(ChatbotAiQueue::TABLE)->where('request_id', $request)->where('status', self::STATUS)->update([
            'status' => $result ? 'answered' : 'failed', 'answer' => $result ? json_encode($result, JSON_UNESCAPED_UNICODE) : null,
            'error' => $result ? null : mb_substr($error ?? 'The encode failed.', 0, 255), 'answered_at' => now(), 'updated_at' => now(),
        ]);
        if ($ok === 1) ResumeChatbotAi::dispatch(ChatbotAiQueue::ticket(DB::table(ChatbotAiQueue::TABLE)->where('request_id', $request)->first()));
    }

    /** Si Astra sa row (ang parehong run-one-row ng RunSpotAstra at ng night run); hindi naghihintay kapag hawak ng iba ang row. */
    private function astra(OrderEncode $o, int $id): array
    {
        try {
            $out = RowLock::hold($id, 0, function () use ($id, $o) {
                $row = MacroOutput::find($id);
                if (!$row || trim((string) $row->STATUS) !== '') return null;

                return app(AiCheckerRowRunner::class)->run($id, 'astra', (string) config('app.url'), [
                    'source' => 'spot', 'user_id' => null, 'user_name' => LiveOrder::USER, 'batch_id' => 'spot-' . $o->id,
                    'http_timeout' => 120, 'only_when_status_blank' => true,
                ]);
            });
        } catch (LockTimeoutException) {
            return ['status' => 'busy'];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'failed'];
        }
        $result = is_array($out['result'] ?? null) ? $out['result'] : null;

        return $out === null ? ['status' => 'skipped'] : ['status' => (string) ($result['status'] ?? 'failed'), 'final_code' => $result['final_code'] ?? null, 'log_id' => $out['log_id'] ?? null];
    }

    /** Ang mga variable mula sa row (ang huling value nito) at sa encode. */
    private function result(OrderEncode $o, ?MacroOutput $row, ?array $astra): array
    {
        if (!$row) return ['encode_proceed' => 'no', 'encode_hold_reason' => 'error'];
        $proceed = self::final($row);
        $cols = ['PROVINCE', 'CITY', 'BARANGAY', 'ADDRESS', 'FULL NAME', 'PHONE NUMBER'];
        $values = array_combine($cols, array_map(fn ($c) => (string) $row->{$c}, $cols));

        return [
            'encode_province' => $values['PROVINCE'], 'encode_city' => $values['CITY'], 'encode_barangay' => $values['BARANGAY'],
            'encode_address' => $values['ADDRESS'], 'encode_name' => $values['FULL NAME'], 'encode_phone' => $values['PHONE NUMBER'],
            'encode_proceed' => $proceed ? 'yes' : 'no',
            'encode_hold_reason' => $proceed ? '' : self::holdReason((array) $o->outputs, $values, $astra),
            'encode_row_id' => (string) $row->id,
        ];
    }

    /**
     * Bakit hindi PROCEED, ang una sa ayos: address, duplicate, name, phone, price, other. Si Astra (kapag tumakbo at may
     * resulta) ang huling salita sa address; kung hindi, ang encode (R2/R4/R6 at ang address check).
     * @param array<string, mixed> $outputs ang outputs ng encode
     * @param array<string, string> $row PROVINCE, CITY, BARANGAY, ADDRESS, FULL NAME, PHONE NUMBER
     */
    public static function holdReason(array $outputs, array $row, ?array $astra): string
    {
        $rule = $outputs['hold']['rule'] ?? null;
        $astraRan = $astra !== null && in_array($astra['status'] ?? '', ['fixed', 'partial'], true);
        $addressOk = $astraRan ? ($astra['final_code'] ?? '') === '✅' : ($outputs['final'] ?? '') === '✅' && !in_array($rule, self::ADDRESS_RULES, true);
        $blank = collect(['PROVINCE', 'CITY', 'BARANGAY', 'ADDRESS'])->contains(fn ($c) => trim((string) ($row[$c] ?? '')) === '');

        return match (true) {
            !$addressOk || $blank => 'address',
            $rule === 'R5' => 'duplicate',
            trim((string) ($row['FULL NAME'] ?? '')) === '' => 'name',
            BotcakeCustomerMatcher::normPhone((string) ($row['PHONE NUMBER'] ?? '')) === '' => 'phone',
            !in_array(trim((string) ($outputs['r'] ?? '')), ['', '✅'], true) => 'price',
            default => 'other',
        };
    }

    /** Final na ang row: may STATUS (hindi CANNOT PROCEED) o waybill. */
    private static function final(MacroOutput $row): bool
    {
        $status = strtoupper(trim((string) $row->STATUS));

        return ($status !== '' && $status !== 'CANNOT PROCEED') || trim((string) $row->waybill) !== '';
    }

    /** Hindi ma-encode ngayon: failed port, ang hold ay "error". */
    private function failNow(FlowRun $run, string $nodeId, string $note, ?int $rowId): array
    {
        $this->setVars($run, ['encode_proceed' => 'no', 'encode_hold_reason' => 'error', 'encode_row_id' => $rowId ? (string) $rowId : '']);
        $run->event('encode_macro', $nodeId, ['result' => 'failed', 'error' => $note]);

        return ['port' => 'failed', 'note' => $note];
    }
}
