<?php

namespace App\Services\Chatbot;

use App\Models\BotcakeChat;
use Illuminate\Support\Facades\Cache;

/**
 * Ang takbo kada chat (RunChatbotFlow, queue "chatbot"):
 * - Isang customer (page + PSID) sa isang pagkakataon: cache lock; kapag hawak ng iba, "locked" (ibinabalik ng job
 *   sa queue).
 * - Sunod-sunod: lahat ng hindi pa natatakbong chat ng customer hanggang sa chat na ito, ayon sa id.
 * - Isang beses lang: ang __last_chat ng customer ay itinatala BAGO patakbuhin ang chat, kaya ang ulit na job (retry,
 *   o ang job ng mas naunang chat) ay walang ginagawa. Ang chat na naputol sa gitna ay hindi inuulit (at most once).
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
            // Unang takbo para sa customer na ito: ang chat na ito lang. Ang mga naunang chat (bago naitali ang flow, o
            // habang walang flow) ay hindi na sinasagot.
            if ($last === 0) $last = $chatId - 1;

            $pending = BotcakeChat::where('page_id', $chat->page_id)->where('psid', $chat->psid)
                ->where('id', '>', $last)->where('id', '<=', $chatId)->orderBy('id')->get();
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
            }

            return 'done';
        } finally {
            $lock->release();
        }
    }
}
