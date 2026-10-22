<?php

namespace App\Services\Chatbot;

use App\Models\BotcakeChat;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ang takbo kada chat (RunChatbotFlow, queue "chatbot"):
 * - Isang customer (page + PSID) sa isang pagkakataon: cache lock; kapag hawak ng iba, "locked" (ibinabalik ng job
 *   sa queue).
 * - Sunod-sunod: lahat ng hindi pa natatakbong chat ng customer hanggang sa chat na ito, ayon sa id.
 * - Isang beses lang: ang __last_chat ng customer ay itinatala BAGO patakbuhin ang chat, kaya ang ulit na job (retry,
 *   o ang job ng mas naunang chat) ay walang ginagawa. Ang chat na naputol sa gitna ay hindi inuulit (at most once).
 * - Habang hinihintay ang sagot ng AI (__ai, chatbot:ai-pool), "locked" pa rin ang customer kahit bitaw na ang cache
 *   lock: ang bagong chat ay naghihintay, at ang sagot ay itinutuloy ng resumeAi sa parehong lock.
 */
class ChatbotRunner
{
    public const LOCK_SECONDS = 300; // mas mahaba sa pinakamahabang takbo (hanggang 100 hakbang, ilang AI)
    public const STALE_MINUTES = 30;  // kapareho ng retryUntil ng RunChatbotFlow

    public function __construct(private ChatbotPage $page, private FlowEngine $engine, private BotcakeOutbox $outbox)
    {
    }

    /** @return string 'done' | 'locked' | 'skipped' */
    public function handle(int $chatId): string
    {
        $chat = BotcakeChat::find($chatId);
        if (!$chat || !ctype_digit((string) $chat->page_id) || !ctype_digit((string) $chat->psid) || $chat->page_name === null) return 'skipped';

        $lock = Cache::lock('chatbot-run:' . $chat->page_id . ':' . $chat->psid, self::LOCK_SECONDS);
        if (!$lock->get()) return 'locked';

        try {
            $fields = new CustomerFields((int) $chat->page_id, (int) $chat->psid);
            $last = (int) $fields->get(CustomerFields::LAST_CHAT);
            if ($chatId <= $last) return 'done';
            if (ChatbotAiQueue::pending($fields)) return 'locked';
            // Unang takbo para sa customer na ito: ang chat na ito lang. Ang mga naunang chat (bago naitali ang flow, o
            // habang walang flow) ay hindi na sinasagot.
            if ($last === 0) $last = $chatId - 1;

            $pending = BotcakeChat::where('page_id', $chat->page_id)->where('psid', $chat->psid)
                ->where('id', '>', $last)->where('id', '<=', $chatId)->orderBy('id')->get()
                ->reject(fn ($c) => ForbeastWatch::standby($c)); // standby: tumatakbo lang kapag lumipat sa Botcake (bagong chat)
            $botfields = null;
            $staleBefore = now()->subMinutes(self::STALE_MINUTES);
            foreach ($pending as $c) {
                $fields->set(CustomerFields::LAST_CHAT, (string) $c->id);
                $flow = $this->page->flow((string) $c->page_name);
                if (!$flow || !is_array($flow->graph)) continue;
                // Tao ang sumasagot sa customer na ito (/chatbot/inbox): tapos na ang chat, walang takbo.
                if ($fields->get(CustomerFields::BOT_PAUSED) === '1') {
                    ChatbotEvents::skipped((int) $c->page_id, (int) $c->psid, $c->id, $flow->id, 'bot paused');
                    continue;
                }
                // Ang chat na naghintay nang matagal (hal. patay ang worker) ay hindi na sinasagot: luma na ang usapan.
                if ($c->received_at && $c->received_at->lt($staleBefore)) {
                    ChatbotEvents::skipped((int) $c->page_id, (int) $c->psid, $c->id, $flow->id, 'chat too old');
                    continue;
                }
                $botfields ??= $this->page->botfields((string) $c->page_name);

                $run = new FlowRun($flow->id, $flow->graph, (string) $c->page_name, (string) $c->page_id, $botfields, $fields, $c->id,
                    (string) $c->message, (string) $c->name, $c->received_at?->timezone('Asia/Manila')->format('Y-m-d H:i') ?? '');
                $this->engine->run($run, $this->outbox);
                ChatbotEvents::store($run, (int) $c->page_id, (int) $c->psid);
                // Hinihintay ang sagot ng AI: ang mga kasunod na chat ay mamaya na (ibinabalik ang job sa queue).
                if (($run->aiPending || $run->sendPending) && $c->id !== $pending->last()->id) return 'locked';
            }

            return 'done';
        } finally {
            $lock->release();
        }
    }

    /**
     * Ang hinihintay na lampas na sa deadline (chatbot_waits): nagpapatuloy sa port na skipped, sa parehong lock ng
     * customer. Isang beses lang: ang row ay kinukuha sa iisang DELETE bago ang takbo, kaya ang pangalawang tick ay walang
     * nagagawa. Ang chat ng customer na hindi pa natatakbo ay nauuna (ang sagot ang nananaig): naiiwan ang row.
     * @return string 'done' | 'locked' | 'waiting' (may chat pa sa queue) | 'gone' | 'dropped'
     */
    public function skip(int $waitId): string
    {
        $wait = DB::table('chatbot_waits')->where('id', $waitId)->first();
        if (!$wait) return 'gone';

        $lock = Cache::lock('chatbot-run:' . $wait->page_id . ':' . $wait->psid, self::LOCK_SECONDS);
        if (!$lock->get()) return 'locked';

        try {
            $fields = new CustomerFields((int) $wait->page_id, (int) $wait->psid);
            if (ChatbotAiQueue::pending($fields)) return 'waiting';
            $unrun = BotcakeChat::where('page_id', (string) $wait->page_id)->where('psid', (string) $wait->psid)
                ->where('id', '>', (int) $fields->get(CustomerFields::LAST_CHAT));
            // Ang sagot na tatakbo pa ang nananaig. Ang luma na (hindi na tatakbo, hal. lampas sa retry ng job) ay hindi
            // humaharang: sumagot ang customer, kaya walang skip (sa ibaba).
            if ((clone $unrun)->where('received_at', '>=', now()->subMinutes(self::STALE_MINUTES))->exists()) return 'waiting';
            $replied = $unrun->exists();
            if (DB::table('chatbot_waits')->where('id', $waitId)->where('due_at', '<=', now())->delete() !== 1) return 'gone';
            // Natapos na ang paghihintay sa ibang paraan (sagot, "clear all fields", ibang wait): wala nang gagawin.
            if ($fields->get(CustomerFields::WAIT) !== $wait->flow_id . ':' . $wait->node_id) return 'gone';

            $last = BotcakeChat::where('page_id', (string) $wait->page_id)->where('psid', (string) $wait->psid)->orderByDesc('id')->first();
            $flow = $last && $last->page_name !== null ? $this->page->flow((string) $last->page_name) : null;
            $node = collect(is_array($flow?->graph) ? $flow->graph['nodes'] ?? [] : [])->firstWhere('id', $wait->node_id);
            $drop = function (string $why, bool $endWait) use ($wait, $fields) {
                if ($endWait) $fields->clear(CustomerFields::WAIT);
                ChatbotEvents::skipped((int) $wait->page_id, (int) $wait->psid, null, (int) $wait->flow_id, $why, $wait->node_id);

                return 'dropped';
            };
            // Ang haba ng hinintay ay mula sa row mismo (naitala → deadline), kahit nawala na ang step.
            $after = FlowGraph::skipLabel((int) round(abs(Carbon::parse($wait->created_at ?? $wait->due_at)->diffInMinutes(Carbon::parse($wait->due_at)))));
            $label = 'no reply in ' . $after;

            if ($replied) return $drop($label . ', but the customer replied and the reply was not run', false);
            if (!$flow || !is_array($flow->graph)) return $drop($label . ', but the page is off or has no flow', false);
            // Tao ang sumasagot: walang takbo; ang __wait ay naiiwan (ang sagot ng customer ay nagpapatuloy pa rin).
            if ($fields->get(CustomerFields::BOT_PAUSED) === '1') return $drop($label . ', but the bot paused', false);
            if ($flow->id !== (int) $wait->flow_id || !$node || ($node['type'] ?? '') !== 'send' || empty($node['config']['wait'])) {
                return $drop($label . ', but the flow changed', true);
            }
            if (!FlowGraph::skipMinutes((array) $node['config'])) return $drop($label . ', but the flow changed', false);
            // Messenger: walang padala kapag 24 oras na mula sa huling mensahe ng customer.
            if (!$last->received_at || $last->received_at->lte(now()->subHours(24))) return $drop($label . ', but the 24-hour window has closed', true);

            $run = new FlowRun($flow->id, $flow->graph, (string) $last->page_name, (string) $last->page_id, $this->page->botfields((string) $last->page_name), $fields, null,
                (string) $last->message, (string) $last->name, $last->received_at->timezone('Asia/Manila')->format('Y-m-d H:i'));
            $run->skippedAfter = $after;
            $this->engine->run($run, $this->outbox);
            ChatbotEvents::store($run, (int) $wait->page_id, (int) $wait->psid);

            return 'done';
        } finally {
            $lock->release();
        }
    }

    /**
     * Ang sagot ng AI (ResumeChatbotAi, mula sa chatbot:ai-pool): itinutuloy ang takbo sa AI node, sa lock ng customer.
     * @return string 'done' | 'locked' | 'gone' | 'dropped'
     */
    public function resumeAi(array $ticket): string
    {
        return $this->resume(ChatbotAiQueue::TABLE, ['answered', 'failed'], '', ['ai'], 'ai_dropped', $ticket, function (FlowRun $run, object $row) {
            $run->aiAnswer = ['node' => (string) $row->node_id, 'ok' => $row->status === 'answered', 'text' => (string) $row->answer,
                'error' => $row->error ?? 'OpenAI did not answer.'];
        });
    }

    /**
     * Ang resulta ng padala sa Botcake (ResumeChatbotSend, mula sa botcake:send-pool): itinutuloy ang takbo sa send node (o sa
     * AI node na nagpadala ng sagot), sa lock ng customer. Ang padala mismo ay naitala na ng pool.
     * @return string 'done' | 'locked' | 'gone' | 'dropped'
     */
    public function resumeSend(array $ticket): string
    {
        return $this->resume(BotcakeSendQueue::TABLE, ['done', 'failed'], BotcakeSendQueue::MARK, ['send', 'ai'], 'send_dropped', $ticket, function (FlowRun $run, object $row) {
            $r = (array) json_decode((string) $row->result, true);
            $run->sendResult = ['node' => (string) $row->node_id, 'status' => $r['status'] ?? ($row->status === 'done' ? 'sent' : 'failed'),
                'error' => $r['error'] ?? $row->error ?? 'Botcake did not answer (HTTP 0)', 'messages' => (array) (json_decode((string) $row->payload, true)['messages'] ?? [])];
        });
    }

    /**
     * Ang pagtuloy pagkatapos ng isang ticket (AI o padala), sa lock ng customer:
     * - Isang beses lang: ang row ay kinukuha ($finished → resumed) bago ang takbo; ang ulit na job ay walang ginagawa.
     * - Ang customer ay mula sa chat (kapag mayroon) o sa row; ang ticket ng job ay sinusuri ng engine bago ang bawat padala.
     * - Luma na (iba na ang __ai, naka-pause ang bot, nagbago ang flow): hindi itinutuloy, may event.
     */
    private function resume(string $table, array $finished, string $mark, array $nodeTypes, string $dropEvent, array $ticket, callable $apply): string
    {
        $row = DB::table($table)->where('request_id', (string) ($ticket['request_id'] ?? ''))->first();
        if (!$row || !in_array($row->status, $finished, true)) return 'gone';

        $lock = Cache::lock('chatbot-run:' . $row->page_id . ':' . $row->psid, self::LOCK_SECONDS);
        if (!$lock->get()) return 'locked';

        try {
            if (DB::table($table)->where('id', $row->id)->whereIn('status', $finished)
                ->update(['status' => 'resumed', 'resumed_at' => now(), 'updated_at' => now()]) !== 1) return 'gone';

            $chat = $row->chat_id !== null ? BotcakeChat::find($row->chat_id) : null;
            $pageId = $chat ? (string) $chat->page_id : (string) $row->page_id;
            $psid = $chat ? (int) $chat->psid : (int) $row->psid;
            $fields = new CustomerFields((int) $pageId, $psid);
            $drop = function (string $why, bool $clear) use ($fields, $row, $pageId, $psid, $dropEvent) {
                if ($clear) $fields->clear(CustomerFields::AI);
                ChatbotEvents::write((int) $pageId, $psid, $dropEvent, ['reason' => $why, 'request' => substr((string) $row->request_id, 0, 8)], (int) $row->flow_id, (string) $row->node_id);

                return 'dropped';
            };

            if ($fields->get(CustomerFields::AI) !== $mark . $row->request_id) return $drop('the run moved on', false);
            if ($fields->get(CustomerFields::BOT_PAUSED) === '1') return $drop('the bot paused', true);
            $flow = $this->page->flow((string) $row->page_name);
            $node = collect(is_array($flow?->graph) ? $flow->graph['nodes'] ?? [] : [])->firstWhere('id', $row->node_id);
            if (!$flow || $flow->id !== (int) $row->flow_id || !in_array($node['type'] ?? '', $nodeTypes, true)) return $drop('the flow changed', true);
            $fields->clear(CustomerFields::AI);

            $ctx = (array) json_decode((string) $row->context, true);
            $run = new FlowRun($flow->id, $flow->graph, (string) $row->page_name, $pageId, $this->page->botfields((string) $row->page_name), $fields,
                $row->chat_id !== null ? (int) $row->chat_id : null, (string) ($ctx['input'] ?? ''), (string) ($ctx['full_name'] ?? ''), (string) ($ctx['received_at'] ?? ''));
            $run->runId = $row->run_id ?: null;
            $run->aiCalls = (int) ($ctx['ai_calls'] ?? 1);
            $run->steps = (int) ($ctx['steps'] ?? 0);
            $apply($run, $row);
            $run->ticket = $ticket;
            $this->engine->run($run, $this->outbox);
            ChatbotEvents::store($run, (int) $pageId, $psid);

            return 'done';
        } finally {
            $lock->release();
        }
    }
}
