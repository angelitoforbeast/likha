<?php

namespace App\Console\Commands;

use App\Services\Chatbot\ChatbotAiKeys;
use App\Services\Chatbot\ChatbotAiQueue;
use App\Services\Chatbot\ChatbotAiUsage;
use App\Services\Chatbot\Gateways;
use App\Services\PageSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ang demo ng pool ng AI key (spec 210) para subukan ang screen nang walang tawag sa labas: tatlong pekeng key (isang down,
 * isang disabled), isang lipat, ang alert, mga row ng usage sa bawat status, at ang note ng load sa isang page. Sa local at
 * testing lang (tumatanggi sa iba); ang mga key ay hindi totoo at hindi tinatawag.
 */
class ChatbotAiDemo extends Command
{
    protected $signature = 'chatbot:ai-demo {page? : a page on /chatbot/pages that gets the low-load note}';

    protected $description = 'Local only: fill /chatbot/ai-keys with fake keys, a switch, an alert, usage rows and a load note.';

    public function handle(ChatbotAiKeys $keys, PageSettings $settings): int
    {
        if (!app()->environment(['local', 'testing'])) {
            $this->error('Refused: chatbot:ai-demo runs only in the local or testing environment.');
            return self::FAILURE;
        }
        $ids = [];
        foreach ([['openai', 'demo-openai-1'], ['openai', 'demo-openai-2'], ['anthropic', 'demo-claude-1'], ['deepseek', 'demo-deepseek-1']] as [$p, $label]) {
            $res = $keys->add($p, $label, 'sk-demo-not-real-' . Str::lower(Str::random(16)), null);
            if ($res['error'] !== null) {
                $this->warn($label . ': ' . $res['error']);
                continue;
            }
            $ids[$label] = $res['id'];
        }
        if (isset($ids['demo-openai-1'])) $keys->failed(['id' => $ids['demo-openai-1'], 'provider' => 'openai'], 'no_credit');
        if (isset($ids['demo-deepseek-1'])) $keys->setEnabled($ids['demo-deepseek-1'], false, null);
        $page = (string) ($this->argument('page') ?? 'Demo Page');
        $keys->event($page, 'openai', 'demo-openai-1', 'demo-openai-2', 'no credit');
        Cache::forget('chatbot-ai-keys:alert');
        $keys->alert($page, 'All AI keys failed.');

        if (ChatbotAiUsage::ready()) {
            $pageId = (string) ($settings->get($page, 'botcake', 'page_id') ?? '100000000000001');
            foreach (['pending', 'sent', 'needs_price', 'unbilled', 'page_not_found', 'invalid', 'no_gateway'] as $i => $status) {
                DB::table(ChatbotAiQueue::TABLE)->insert(['request_id' => (string) Str::uuid(), 'page_id' => (int) $pageId, 'psid' => 1, 'flow_id' => 0, 'node_id' => 'demo',
                    'page_name' => $page, 'model' => 'gpt-4o-mini', 'max_tokens' => 1, 'status' => 'sync', 'deadline_at' => now(), 'answered_at' => now(),
                    'created_at' => now(), 'updated_at' => now(), 'provider' => 'openai', 'model_used' => 'gpt-4o-mini', 'path' => 'sync', 'prompt_tokens' => 100,
                    'completion_tokens' => 20, 'key_label' => 'demo-openai-2', 'key_last4' => 'demo', 'cost_micros' => 27, 'usage_status' => $status,
                    'usage_gateway' => $status === 'no_gateway' ? null : Gateways::FORBEAST]);
            }
            // Ang note ng load sa row ng page (cache, gaya ng sagot ng Forbeast na "low").
            Cache::put('chatbot-ai-load:' . Gateways::FORBEAST . ':' . $pageId, ['state' => 'low', 'note' => 'Low AI load: ₱78.78 left. Contact Forbeast to top up before the AI stops.',
                'balance' => '78.78', 'at' => now()->getTimestamp()], 900);
        }
        $this->info('Demo keys: ' . implode(', ', array_keys($ids)) . '. Open /chatbot/ai-keys and /chatbot/pages.');

        return self::SUCCESS;
    }
}
