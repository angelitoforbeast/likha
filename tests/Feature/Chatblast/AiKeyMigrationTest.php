<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\ChatbotAiQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 210: ang migration ng key at gastos ay tumatakbo at bumabalik (ang lumang row ay naiiwan), at ang demo ng screen ay sa
 * local at testing lang.
 */
class AiKeyMigrationTest extends AiKeyPoolTestCase
{
    private const MIGRATION = 'database/migrations/2026_11_15_100000_add_key_and_cost_to_chatbot_ai_requests.php';

    public function test_the_key_and_cost_migration_runs_rolls_back_and_runs_again_keeping_rows(): void
    {
        $this->assertTrue(Schema::hasColumns(ChatbotAiQueue::TABLE, ['key_id', 'key_label', 'key_last4', 'cost_micros', 'cost_flag', 'usage_status', 'usage_gateway', 'usage_sent_at', 'usage_result']));
        DB::table(ChatbotAiQueue::TABLE)->insert(['request_id' => 'keep-me', 'page_id' => 1, 'psid' => 1, 'flow_id' => 1, 'node_id' => 'n', 'page_name' => 'P', 'model' => 'm',
            'max_tokens' => 1, 'status' => 'sync', 'deadline_at' => now(), 'created_at' => now(), 'updated_at' => now(), 'key_label' => 'pool-1', 'usage_status' => 'pending']);
        $m = require base_path(self::MIGRATION);
        $m->down();
        $this->assertFalse(Schema::hasColumn(ChatbotAiQueue::TABLE, 'key_id'));
        $this->assertSame('keep-me', DB::table(ChatbotAiQueue::TABLE)->value('request_id'));
        $m->down(); // walang column na: walang ginagawa
        $m->up();
        $m->up(); // naroon na: walang ginagawa
        $this->assertTrue(Schema::hasColumn(ChatbotAiQueue::TABLE, 'usage_status'));
        $this->assertSame(1, DB::table(ChatbotAiQueue::TABLE)->count());
    }

    public function test_the_demo_fills_the_screen_in_testing_and_is_refused_elsewhere(): void
    {
        $this->assertSame(0, Artisan::call('chatbot:ai-demo', ['page' => 'Page Uno']));
        $this->actingAs($this->user('CEO', 'ana@example.test'));
        $data = $this->getJson('/chatbot/ai-keys/data')->assertOk()->json();
        $this->assertCount(4, $data['keys']);
        $this->assertSame(['Down: no credit', 'Not tested', 'Not tested', 'Disabled'], array_column($data['keys'], 'status'));
        $this->assertNotNull($data['alert']);
        $this->assertCount(1, $data['events']['events']);
        $this->assertSame([], $this->providerCalls);

        $this->app->detectEnvironment(fn () => 'production');
        $this->assertSame(1, Artisan::call('chatbot:ai-demo'));
    }
}
