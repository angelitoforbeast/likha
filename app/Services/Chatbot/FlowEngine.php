<?php

namespace App\Services\Chatbot;

use App\Models\BotcakeChat;
use App\Models\ChatbotReply;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ang takbo ng isang flow para sa isang chat. Kapag naghihintay ang customer sa "wait for reply" ng parehong flow,
 * doon nagpapatuloy (ang sagot ay sine-save sa field, saka ang port na reply); kung hindi, mula sa Start. Hanggang
 * 100 node kada takbo. Ang node na pumalya ay sumusunod sa port na failed kapag mayroon, kung hindi ay humihinto ang
 * takbo (itinatala). Ang mga padala at sheet row ay dumadaan sa Outbox (live o Test).
 */
class FlowEngine
{
    public const MAX_STEPS = 100;
    public const MAX_AI_CALLS = 10;

    public function __construct(private ChatbotAi $ai)
    {
    }

    public function run(FlowRun $run, Outbox $outbox): FlowRun
    {
        $nodes = [];
        foreach ($run->graph['nodes'] ?? [] as $n) $nodes[$n['id']] = $n;
        $edges = [];
        foreach ($run->graph['edges'] ?? [] as $e) $edges[$e['from'] . ':' . $e['port']] = $e['to'];

        // Itinuloy pagkatapos ng AI: sa AI node mismo (gamit ang sagot). Ang step ng sequence ay laging mula sa Start: hindi
        // nito ipinagpapatuloy o binubura ang wait ng main flow.
        if ($run->aiAnswer !== null || $run->sendResult !== null) {
            $current = ($run->aiAnswer ?? $run->sendResult)['node'];
            $run->event('run_started', null, ['reason' => $run->aiAnswer !== null ? 'resumed after AI' : 'resumed after send', 'flow' => $run->flowId]);
        } else {
            $current = $run->startedBy !== null ? false : $this->resumePoint($run, $nodes, $edges);
        }
        if ($current === false) {
            $start = collect($nodes)->firstWhere('type', 'start');
            $current = $start['id'] ?? null;
            $run->event('run_started', null, ['reason' => $run->startedBy ?? 'new', 'flow' => $run->flowId]);
        }
        $suspended = false;
        $lastType = null;

        for ($steps = $run->steps; $current !== null; $steps++) {
            $run->steps = $steps;
            if ($steps >= self::MAX_STEPS) {
                $run->stopped = 'Stopped after ' . self::MAX_STEPS . ' steps (a loop in the flow?).';
                Log::warning('chatbot: step limit', ['flow' => $run->flowId, 'page' => $run->pageName, 'chat' => $run->chatId]);
                break;
            }
            $node = $nodes[$current] ?? null;
            if ($node === null) break;

            try {
                $r = $this->execute($node, $run, $outbox);
            } catch (Throwable $e) {
                report($e);
                $r = ['port' => 'failed', 'note' => 'Error: ' . mb_substr($e->getMessage(), 0, 200)];
            }

            $ports = FlowGraph::ports($node);
            $port = $r['port'] ?? null;
            $lastType = $node['type'];
            if ($node['type'] === 'condition') $run->event('condition', $node['id'], ['result' => $port === 'no' ? 'no' : str_replace('row_', 'row ', (string) $port)]);
            if ($port === 'failed' && !in_array('failed', $ports, true)) {
                // Walang failed port ang node na ito: hihinto ang takbo.
                $run->path[] = ['node' => $node['id'], 'type' => $node['type'], 'port' => null, 'note' => $r['note'] ?? null];
                $run->stopped = $r['note'] ?? 'The step failed.';
                Log::warning('chatbot: step failed', ['flow' => $run->flowId, 'page' => $run->pageName, 'chat' => $run->chatId, 'node' => $node['id'], 'note' => $run->stopped]);
                break;
            }
            $run->path[] = ['node' => $node['id'], 'type' => $node['type'], 'port' => $port, 'note' => $r['note'] ?? null];
            // "Counts as an order": dumaan ang takbo sa node (hindi pumalya, hindi pa naghihintay ng padala sa pool).
            if (!empty($node['config']['counts_order']) && $port !== 'failed' && empty($r['stop']) && !$run->sendPending) {
                try {
                    ChatbotTags::order($run, $node['id']);
                } catch (Throwable $e) {
                    report($e); // ang talaan ng order ay hindi pumipigil sa takbo
                }
            }
            if (!empty($r['stop'])) {
                $run->stopped = $r['note'] ?? 'Stopped.';
                Log::warning('chatbot: run stopped', ['flow' => $run->flowId, 'page' => $run->pageName, 'chat' => $run->chatId, 'node' => $node['id'], 'note' => $run->stopped]);
                break;
            }
            if (!empty($r['suspend'])) $suspended = true;
            if (!empty($r['suspend']) || $port === null) break;

            $current = $edges[$node['id'] . ':' . $port] ?? null;
        }

        // Ang dahilan: ang huminto, ang naghihintay, ang End, ang pumalyang step na walang kasunod, o walang kasunod.
        $lastStep = $run->path ? end($run->path) : null;
        $reason = $run->stopped ?? ($suspended ? ($run->aiPending ? 'waiting for the AI answer' : ($run->sendPending ? 'sending' : 'waiting for a reply')) : ($lastType === 'end' ? 'end'
            : (($lastStep['port'] ?? null) === 'failed' ? (string) ($lastStep['note'] ?? 'a step failed') : 'no next step')));
        $run->event('run_ended', null, ['reason' => $reason, 'path' => array_slice(array_column($run->path, 'node'), 0, 100)]);

        return $run;
    }

    /**
     * Ang susunod na node kapag nagpapatuloy mula sa "wait for reply" (null = walang kasunod, tapos na), o false kapag
     * walang hinihintay sa flow na ito (mula sa Start). Ang hinihintay sa ibang flow o sa nawalang node ay binubura.
     */
    private function resumePoint(FlowRun $run, array $nodes, array $edges): string|null|false
    {
        $wait = (string) $run->fields->get(CustomerFields::WAIT);
        if ($wait === '') return false;
        [$flowId, $nodeId] = array_pad(explode(':', $wait, 2), 2, '');
        $node = $nodes[$nodeId] ?? null;
        $run->fields->clear(CustomerFields::WAIT);
        if ((int) $flowId !== $run->flowId || !$node || $node['type'] !== 'send' || empty($node['config']['wait'])) return false;

        if ($run->skippedAfter !== null) {
            // Walang sagot sa takdang oras: ang port na skipped, walang sine-save.
            $label = $run->skippedAfter;
            $run->event('run_started', null, ['reason' => 'no reply in ' . $label, 'flow' => $run->flowId]);
            $run->event('wait_skipped', $nodeId, ['after' => $label]);
            $run->path[] = ['node' => $nodeId, 'type' => 'send', 'port' => 'skipped', 'note' => 'No reply in ' . $label];

            return $edges[$nodeId . ':skipped'] ?? null;
        }

        $run->event('run_started', null, ['reason' => 'resumed after wait', 'flow' => $run->flowId]);
        if (($node['config']['save_to'] ?? '') !== '') {
            $run->fields->set($node['config']['save_to'], $run->input);
            $run->event('field', $nodeId, ['op' => 'set', 'field' => $node['config']['save_to'], 'value' => $run->input]);
        }
        $run->path[] = ['node' => $nodeId, 'type' => 'send', 'port' => 'reply', 'note' => 'Reply received'];

        return $edges[$nodeId . ':reply'] ?? null;
    }

    /** @return array{port: ?string, note?: ?string, suspend?: bool} */
    private function execute(array $node, FlowRun $run, Outbox $outbox): array
    {
        $c = (array) ($node['config'] ?? []);

        return match ($node['type']) {
            'start' => ['port' => 'next'],
            'end' => ['port' => null],
            'condition' => $this->condition($c, $run),
            'send' => $this->send($node, $c, $run, $outbox),
            'set' => $this->set($node, $c, $run),
            'ai' => $this->ai($node, $c, $run, $outbox),
            'clear_ai' => $this->clearAi($node, $c, $run),
            'sheet' => $this->sheet($node, $c, $run, $outbox),
            'sequence' => $this->sequence($node, $c, $run),
            default => ['port' => null],
        };
    }

    /** Subscribe o unsubscribe sa isang sequence (o sa lahat). Ang Test tab ay walang binabago. */
    private function sequence(array $node, array $c, FlowRun $run): array
    {
        $action = ($c['action'] ?? '') === 'unsubscribe' ? 'unsubscribe' : 'subscribe';
        $id = (int) ($c['sequence_id'] ?? 0);
        if ($run->test) {
            $name = $id ? (string) (\App\Models\ChatbotSequence::whereKey($id)->value('name') ?? '#' . $id) : 'all sequences';
            $run->event('sequence', $node['id'], ['op' => 'test', 'action' => $action, 'name' => $name]);
            return ['port' => 'next', 'note' => 'Test: would ' . $action . ' ' . $name];
        }
        // app(): ChatbotSequences ay gumagamit din ng engine na ito.
        $sequences = app(ChatbotSequences::class);
        $pageId = (int) $run->pageId;
        $psid = $run->fields->psid;
        if ($action === 'unsubscribe') {
            $names = $sequences->unsubscribe($id ?: null, $pageId, $psid, 'flow', $run, $node['id']);
            return ['port' => 'next', 'note' => $names ? 'Unsubscribed from ' . implode(', ', $names) : 'Not in any matching sequence'];
        }
        $r = $sequences->subscribe($id, $pageId, $psid, 'flow', $run->flowId, $run, $node['id']);

        return ['port' => 'next', 'note' => ucfirst($r['result']) . ': ' . $r['name']];
    }

    private function condition(array $c, FlowRun $run): array
    {
        $vars = $run->vars();
        foreach (array_values((array) ($c['rows'] ?? [])) as $i => $row) {
            $name = ChatbotVars::norm(trim((string) ($row['variable'] ?? ''), "{} \t"));
            $subject = trim((string) ($vars[$name] ?? ''));
            $target = trim($run->text((string) ($row['value'] ?? '')));
            if (self::matches((string) ($row['rule'] ?? 'is'), $subject, $target)) {
                return ['port' => 'row_' . ($i + 1), 'note' => "Row " . ($i + 1) . ": {$name} " . (FlowGraph::RULES[$row['rule']] ?? $row['rule'])];
            }
        }

        return ['port' => 'no', 'note' => 'No row matched'];
    }

    /** Ang mga patakaran ng Condition; hindi case-sensitive ang text. */
    public static function matches(string $rule, string $subject, string $target): bool
    {
        $s = mb_strtolower($subject);
        $t = mb_strtolower($target);
        $num = fn (string $v) => preg_replace('/[^\d.\-]/', '', $v);

        return match ($rule) {
            'is' => $s === $t,
            'is_not' => $s !== $t,
            'contains' => $t !== '' && str_contains($s, $t),
            'contains_any' => collect(preg_split('/[\r\n,]+/', $t))->map(fn ($v) => trim($v))->filter(fn ($v) => $v !== '')
                ->contains(fn ($v) => str_contains($s, $v)),
            'not_contains' => $t === '' || !str_contains($s, $t),
            'starts_with' => $t !== '' && str_starts_with($s, $t),
            'regex' => $target !== '' && @preg_match('~' . str_replace('~', '\~', $target) . '~iu', $subject) === 1,
            'is_empty' => $subject === '',
            'is_not_empty' => $subject !== '',
            'gt' => is_numeric($num($subject)) && is_numeric($num($target)) && (float) $num($subject) > (float) $num($target),
            'lt' => is_numeric($num($subject)) && is_numeric($num($target)) && (float) $num($subject) < (float) $num($target),
            default => false,
        };
    }

    private function send(array $node, array $c, FlowRun $run, Outbox $outbox): array
    {
        // Itinuloy pagkatapos ng padala (botcake:send-pool): ang resulta ang ginagamit, hindi na binubuo o ipinapadala ulit.
        if ($run->sendResult !== null && $run->sendResult['node'] === $node['id']) {
            return $this->afterSend($node, $c, $run, $this->takeSendResult($run));
        }
        $messages = [];
        $templates = [];
        foreach ((array) ($c['items'] ?? []) as $item) {
            $v = trim($run->text((string) ($item['value'] ?? '')));
            if ($v === '') continue;
            // Ang "Visible to AI" na naka-uncheck ay itinatala kasama ng mensahe; hindi ito ipinapadala sa Botcake/Forbeast (BotcakeOutbox::wire).
            $hidden = ($item['ai'] ?? true) === false ? ['ai' => false] : [];
            if (($item['type'] ?? 'text') === 'image') {
                if (!self::isUrl($v)) continue;
                $messages[] = ['type' => 'image', 'url' => $v] + $hidden;
            } else {
                $messages[] = ['type' => 'text', 'text' => mb_substr($v, 0, 2000)] + $hidden;
            }
            $templates[] = (string) ($item['value'] ?? '');
        }

        if (!$messages) return $this->afterSend($node, $c, $run, null);
        if ($stop = $this->mismatch($run, $node['id'])) return $stop;
        $run->tags = ChatbotTags::forSend($run, $templates);
        // Hindi hinihintay ang Botcake: ang padala ay sa botcake:send-pool, at humihinto ang takbo hanggang sa resulta.
        if (BotcakeSendQueue::async($run, $outbox) && BotcakeSendQueue::submitChatbot($run, $node['id'], $messages) !== null) {
            $run->sendPending = true;
            return ['port' => null, 'suspend' => true, 'note' => 'Sending ' . count($messages) . ' message' . (count($messages) === 1 ? '' : 's')];
        }
        $res = $outbox->send($run, $node['id'], $messages);

        return $this->afterSend($node, $c, $run, ['messages' => $messages, 'status' => $res['status'], 'error' => $res['error']]);
    }

    /** @return array{node: string, status: string, error: ?string, messages: array} */
    private function takeSendResult(FlowRun $run): array
    {
        $r = $run->sendResult;
        $run->sendResult = null;

        return $r;
    }

    /**
     * Pagkatapos ng padala ng send node (naghintay man o itinuloy mula sa pool): ang talaan ng takbo, ang failed port, at
     * ang "wait for reply". $res null = walang maipadala.
     */
    private function afterSend(array $node, array $c, FlowRun $run, ?array $res): array
    {
        $note = 'Nothing to send';
        if ($res !== null) {
            $messages = (array) $res['messages'];
            $run->sends[] = ['node' => $node['id'], 'messages' => $messages, 'status' => $res['status'], 'error' => $res['error']];
            if ($res['status'] === 'skipped') $run->event('skipped', $node['id'], ['reason' => 'replies off']);
            if ($res['status'] === 'failed') return ['port' => 'failed', 'note' => 'Send failed: ' . $res['error']];
            $note = count($messages) . ' message' . (count($messages) === 1 ? '' : 's') . ' ' . $res['status'];
        }

        // Ang step ng sequence ay hindi naghihintay: ang wait (at ang timer) ng customer ay sa main flow ng page.
        if (!empty($c['wait']) && $run->startedBy !== null) {
            $run->event('skipped', $node['id'], ['reason' => 'a sequence step does not wait for a reply']);
            return ['port' => null, 'note' => $note . '; a sequence step does not wait for a reply'];
        }
        if (!empty($c['wait'])) {
            $run->fields->set(CustomerFields::WAIT, $run->flowId . ':' . $node['id']);
            // Ang deadline ng skip (live lang: ang test customer ay walang tick). Ang wait na walang skip ay walang timer.
            $minutes = FlowGraph::skipMinutes($c);
            if (!$run->test) {
                if ($minutes) ChatbotWaits::park($run->fields->pageId, $run->fields->psid, $run->flowId, $node['id'], now()->addMinutes($minutes));
                else ChatbotWaits::forget($run->fields->pageId, $run->fields->psid);
            } elseif ($minutes) {
                // Test: ang deadline ay field ng test customer (hindi chatbot_waits, kaya hindi ito naaabot ng tick ng live).
                $run->fields->set(CustomerFields::TEST_SKIP, now()->addMinutes($minutes)->toIso8601String());
            } else {
                $run->fields->clear(CustomerFields::TEST_SKIP);
            }
            $run->event('waiting', $node['id'], $minutes ? ['skip_after' => FlowGraph::skipLabel($minutes)] : []);
            return ['port' => 'reply', 'note' => $note . '; waiting for the reply', 'suspend' => true];
        }

        return ['port' => 'continue', 'note' => $note];
    }

    private function set(array $node, array $c, FlowRun $run): array
    {
        $done = [];
        foreach ((array) ($c['ops'] ?? []) as $op) {
            $field = (string) ($op['field'] ?? '');
            switch ($op['op'] ?? 'set') {
                case 'clear_all':
                    $run->fields->clearAll();
                    $done[] = 'cleared all fields';
                    $run->event('field', $node['id'], ['op' => 'clear_all']);
                    break;
                case 'clear':
                    if ($field !== '') $run->fields->clear($field);
                    $done[] = "cleared {$field}";
                    if ($field !== '') $run->event('field', $node['id'], ['op' => 'clear', 'field' => $field]);
                    break;
                case 'append':
                    if ($field === '') break;
                    $v = $run->text((string) ($op['value'] ?? ''));
                    $old = (string) $run->fields->get($field);
                    $run->fields->set($field, $old === '' ? $v : $old . (string) ($op['separator'] ?? ', ') . $v);
                    $done[] = "appended to {$field}";
                    $run->event('field', $node['id'], ['op' => 'append', 'field' => $field, 'value' => $v]);
                    break;
                default:
                    if ($field === '') break;
                    $v = $run->text((string) ($op['value'] ?? ''));
                    $run->fields->set($field, $v);
                    $done[] = "set {$field}";
                    $run->event('field', $node['id'], ['op' => 'set', 'field' => $field, 'value' => $v]);
            }
        }

        return ['port' => 'next', 'note' => $done ? implode(', ', $done) : 'No change'];
    }

    /** "Clear AI memory": nakakalimutan ng AI ang usapan hanggang dito (walang binubura), at ang mga napiling field ay binubura. */
    private function clearAi(array $node, array $c, FlowRun $run): array
    {
        $run->forgetAi();
        $fields = array_values(array_filter((array) ($c['fields'] ?? []), fn ($f) => is_string($f) && $f !== ''));
        foreach ($fields as $f) $run->fields->clear($f);
        $run->event('ai_cleared', $node['id'], $fields ? ['fields' => implode(', ', $fields)] : []);

        return ['port' => 'next', 'note' => 'AI memory cleared' . ($fields ? '; cleared ' . implode(', ', $fields) : '')];
    }

    private function ai(array $node, array $c, FlowRun $run, Outbox $outbox): array
    {
        // Itinuloy pagkatapos ipadala ang sagot (botcake:send-pool): ang sagot ay nasagot at na-save na bago ang padala.
        if ($run->sendResult !== null && $run->sendResult['node'] === $node['id']) {
            $r = $this->takeSendResult($run);
            $run->sends[] = ['node' => $node['id'], 'messages' => $r['messages'], 'status' => $r['status'], 'error' => $r['error']];
            return ['port' => 'success', 'note' => 'AI answered' . (($c['save_to'] ?? '') !== '' ? ' into ' . $c['save_to'] : '')];
        }
        if ($run->aiAnswer !== null && $run->aiAnswer['node'] === $node['id']) {
            // Ang sagot ng pool (chatbot:ai-pool) sa tawag na nabilang na bago huminto ang takbo. Sinusuri ang ticket bago pa
            // ma-save sa field (ang sagot ng ibang customer ay hindi dapat mapunta kahit sa field).
            if ($stop = $this->mismatch($run, $node['id'])) return $stop;
            $res =['ok' => (bool) $run->aiAnswer['ok'], 'text' => (string) ($run->aiAnswer['text'] ?? ''), 'error' => $run->aiAnswer['error'] ?? null];
            $run->aiAnswer = null;
        } else {
            // Hangganan ng gastos: ang loop na may AI ay hihinto pagkatapos ng ilang tawag sa isang takbo.
            if ($run->aiCalls >= self::MAX_AI_CALLS) return ['port' => null, 'stop' => true, 'note' => 'Stopped: more than ' . self::MAX_AI_CALLS . ' AI calls in one run.'];
            $run->aiCalls++;
            $messages = [];
            $system = trim($run->text((string) ($c['system'] ?? '')));
            if ($system !== '') $messages[] = ['role' => 'system', 'content' => $system];
            foreach ($this->history($run, (int) ($c['history'] ?? 0)) as $m) $messages[] = $m;
            $messages[] = ['role' => 'user', 'content' => $run->text((string) ($c['user'] ?? '{{last_input}}'))];
            $model = (string) ($c['model'] ?? 'gpt-4o-mini');
            $maxTokens = (int) ($c['max_tokens'] ?? 250);

            // Hindi hinihintay ang sagot: ang ticket ay sa pool, at humihinto ang takbo (bakante agad ang worker).
            if (ChatbotAiQueue::async($run, $outbox)) {
                $id = ChatbotAiQueue::submit($run, $node['id'], $model, $messages, $maxTokens);
                $run->aiPending = true;
                $run->event('ai_pending', $node['id'], ['model' => $model, 'request' => substr($id, 0, 8)]);
                return ['port' => null, 'suspend' => true, 'note' => 'Waiting for the AI answer'];
            }
            $res = ChatbotMockAi::answer($run) ?? $this->ai->complete($model, $messages, $maxTokens);
        }
        if (!$res['ok']) {
            $run->event('ai', $node['id'], ['result' => 'failed', 'model' => (string) ($c['model'] ?? 'gpt-4o-mini'), 'error' => (string) $res['error']]);
            return ['port' => 'failed', 'note' => 'AI failed: ' . $res['error']];
        }
        $run->event('ai', $node['id'], ['result' => 'success', 'model' => (string) ($c['model'] ?? 'gpt-4o-mini'), 'saved_to' => (string) ($c['save_to'] ?? '')]);

        if (($c['save_to'] ?? '') !== '') $run->fields->set($c['save_to'], $res['text']);
        if (!empty($c['send'])) {
            if ($stop = $this->mismatch($run, $node['id'])) return $stop;
            $msg = [['type' => 'text', 'text' => mb_substr($res['text'], 0, 2000)]];
            $run->tags = ChatbotTags::forSend($run, [null]);
            if (BotcakeSendQueue::async($run, $outbox) && BotcakeSendQueue::submitChatbot($run, $node['id'], $msg) !== null) {
                $run->sendPending = true;
                return ['port' => null, 'suspend' => true, 'note' => 'AI answered; sending the answer'];
            }
            $sent = $outbox->send($run, $node['id'], $msg);
            $run->sends[] = ['node' => $node['id'], 'messages' => $msg, 'status' => $sent['status'], 'error' => $sent['error']];
        }

        return ['port' => 'success', 'note' => 'AI answered' . (($c['save_to'] ?? '') !== '' ? ' into ' . $c['save_to'] : '')];
    }

    /**
     * Bago ang padala ng takbong itinuloy pagkatapos ng AI: ang page, PSID at chat ng takbo ay dapat kapareho ng ticket ng
     * sagot. Kapag hindi: walang padala, hihinto ang takbo, event ai_mismatch at ERROR log (mga id lang, walang text).
     * @return array|null ang resulta ng node na humihinto, o null kapag tugma (o hindi itinuloy na takbo)
     */
    private function mismatch(FlowRun $run, string $nodeId): ?array
    {
        $t = $run->ticket;
        if ($t === null) return null;
        $same = (string) $t['page_id'] === (string) $run->pageId && (int) $t['page_id'] === $run->fields->pageId
            && (int) $t['psid'] === $run->fields->psid && (string) $t['psid'] === $run->psid()
            && ($t['chat_id'] === null || (int) $t['chat_id'] === (int) $run->chatId);
        if ($same) return null;

        $ids = ['request' => (string) $t['request_id'], 'ticket_page' => (string) $t['page_id'], 'ticket_psid' => (string) $t['psid'], 'ticket_chat' => (string) ($t['chat_id'] ?? ''),
            'run_page' => (string) $run->pageId, 'run_psid' => (string) $run->fields->psid, 'run_chat' => (string) ($run->chatId ?? '')];
        Log::error('chatbot: AI answer ticket mismatch, nothing sent', $ids + ['flow' => $run->flowId, 'node' => $nodeId]);
        $run->event('ai_mismatch', $nodeId, $ids);

        return ['port' => null, 'stop' => true, 'note' => 'Stopped: the AI answer belongs to another customer (nothing sent).'];
    }

    /**
     * Ang huling N mensahe ng customer (botcake_chats, bago ang chat na ito) at ng ating mga naipadalang sagot, sa
     * ayos ng oras, pagkatapos lang ng "Clear AI memory" (__ai_since). Sa Test: ang usapan ng test customer.
     * @return array<int, array{role: string, content: string}>
     */
    private function history(FlowRun $run, int $n): array
    {
        if ($n <= 0) return [];
        // Test: ang usapan ng test customer (TestHistory), at ang mga naipadala na sa takbong ito (pagkatapos ng clear).
        if ($run->test) {
            $sent = array_map(fn ($s) => ['role' => 'assistant', 'content' => TestHistory::text($s['messages'])], array_slice($run->sends, $run->aiClearedAt ?? 0));
            $all = array_values(array_filter(array_merge(TestHistory::read($run->fields), $sent), fn ($m) => trim($m['content']) !== ''));

            return array_slice($all, -$n);
        }
        $psid = (string) $run->fields->psid;
        $since = $run->aiSince();

        $in = BotcakeChat::active()->where('page_id', $run->pageId)->where('psid', $psid)
            ->when($run->chatId, fn ($q) => $q->where('id', '<', $run->chatId))
            ->when($since, fn ($q) => $q->where('id', '>', $since['chat']))
            ->orderByDesc('id')->limit($n)->get(['message', 'received_at'])->toBase() // ang walang laman na Eloquent collection ay hindi ma-merge sa mga array (getKey)
            ->map(fn ($c) => ['at' => (string) $c->received_at, 'role' => 'user', 'content' => (string) $c->message]);
        $out = ChatbotReply::where('psid', (int) $psid)->where('page_id', (int) $run->pageId)->where('kind', 'send')->where('status', 'sent')
            ->when($since, fn ($q) => $q->where('id', '>', $since['reply']))
            ->orderByDesc('id')->limit($n)->get(['content', 'sent_at'])
            ->map(fn ($r) => ['at' => (string) $r->sent_at, 'role' => 'assistant', 'content' => TestHistory::text((array) $r->content)]);

        return $in->merge($out)->filter(fn ($m) => trim($m['content']) !== '')->sortBy('at')->values()->take(-$n)
            ->map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']])->all();
    }

    private function sheet(array $node, array $c, FlowRun $run, Outbox $outbox): array
    {
        $id = FlowGraph::spreadsheetId((string) ($c['sheet'] ?? ''));
        if ($id === null) return ['port' => 'failed', 'note' => 'No spreadsheet link or ID'];
        $tab = trim((string) ($c['tab'] ?? '')) ?: 'Sheet1';

        $vars = $run->vars();
        $row = array_map(fn ($t) => ChatbotVars::sheetCell((string) $t, $vars), (array) ($c['columns'] ?? []));
        $res = $outbox->appendRow($run, $node['id'], $id, $tab, $row);
        $run->sheetRows[] = ['node' => $node['id'], 'sheet' => $id, 'tab' => $tab, 'row' => $row, 'status' => $res['status'], 'error' => $res['error']] + array_filter(['note' => $res['note'] ?? null]);
        $run->event('sheet', $node['id'], ['result' => ['sent' => 'written', 'failed' => 'failed', 'skipped' => 'skipped'][$res['status']] ?? $res['status'], 'error' => (string) $res['error']]);
        if ($res['status'] === 'failed') return ['port' => 'failed', 'note' => 'Sheet failed: ' . $res['error']];

        return ['port' => 'next', 'note' => 'Row ' . $res['status']];
    }

    public static function isUrl(string $v): bool
    {
        return (bool) filter_var($v, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($v, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
