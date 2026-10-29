<?php

namespace Tests\Feature\Chatblast;

use App\Models\ChatbotFlow;
use App\Models\PageSetting;
use App\Services\Chatbot\FlowGraph;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Ang template code ng flow: ginagawa ng staff sa editor, ipinapaste sa Forbeast, at ang page ay nakatali sa flow na iyon
 * (/api/gateway/flow-codes/{code}, /api/gateway/pages/{id}/flow). Ang Forbeast ay pinepeke ng mga request dito, pirmado
 * gaya ng page values API.
 */
class FlowTemplateCodesTest extends ChatblastTestCase
{
    private const SECRET = 'page-uno-webhook-secret-q1w2e3r4';
    private const ACTOR = 'Ana Reyes ana@forbeast.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2026_11_12_100000_add_source_to_page_settings.php',
            'database/migrations/2026_11_14_100000_add_template_code_to_chatbot_flows.php',
        ]]);
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '1002');
        $this->settings()->set('Page Uno', 'forbeast', 'webhook_secret', self::SECRET, true, null, true);
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    /** Isang flow mula sa mga node (bawat isa ay [type, config]); published maliban kung $published = false. */
    private function flow(string $name, array $nodes, bool $published = true): ChatbotFlow
    {
        $list = [['id' => 'n0', 'type' => 'start']];
        foreach ($nodes as $i => [$type, $config]) $list[] = ['id' => 'n' . ($i + 1), 'type' => $type, 'config' => $config];
        $flow = ChatbotFlow::create(['name' => $name, 'graph' => FlowGraph::clean(['nodes' => $list, 'edges' => []])]);
        if ($published) DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);

        return $flow->fresh();
    }

    private function send(string ...$texts): array
    {
        return ['send', ['items' => array_map(fn ($t) => ['type' => 'text', 'value' => $t], $texts)]];
    }

    public function test_S_195_1_staff_create_copy_regenerate_and_revoke_a_flow_code(): void
    {
        $flow = $this->flow('Main Flow from CBB', [$this->send('{{Main Flow}}')]);
        $draft = $this->flow('Draft only', [$this->send('Hi')], false);

        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        $this->postJson("/chatbot/flows/{$flow->id}/code")->assertForbidden();
        $this->deleteJson("/chatbot/flows/{$flow->id}/code")->assertForbidden();
        $this->assertNull($flow->fresh()->template_code);

        $this->actingAs($this->user());
        $code = $this->postJson("/chatbot/flows/{$flow->id}/code")->assertOk()->json('code');
        $this->assertMatchesRegularExpression('/^LK-[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{4}-[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{2}$/', $code);
        $this->assertSame($code, $flow->fresh()->template_code);

        // Ang code ay nasa screen ng flow (may Copy) at sa listahan ng flow.
        $editor = $this->get("/chatbot/flows/{$flow->id}")->assertOk()->getContent();
        $this->assertStringContainsString($code, $editor);
        $this->assertStringContainsString('Template code', $editor);
        $this->assertStringContainsString('data-code-act="copy"', $editor);
        $this->assertStringContainsString($code, $this->get('/chatbot')->assertOk()->getContent());

        // Regenerate: bagong code, ang luma ay hindi na gumagana.
        $next = $this->postJson("/chatbot/flows/{$flow->id}/code")->assertOk()->json('code');
        $this->assertNotSame($code, $next);
        $this->assertNull(ChatbotFlow::findByTemplateCode($code));
        $this->assertSame($flow->id, ChatbotFlow::findByTemplateCode(strtolower($next))?->id, 'hindi case-sensitive');

        // Revoke.
        $this->deleteJson("/chatbot/flows/{$flow->id}/code")->assertOk()->assertJson(['code' => null]);
        $this->assertNull($flow->fresh()->template_code);
        $this->assertNull(ChatbotFlow::findByTemplateCode($next));

        // Ang hindi pa naipa-publish na flow ay walang code (walang tatakbo).
        $this->postJson("/chatbot/flows/{$draft->id}/code")->assertStatus(422)->assertJson(['error' => 'Publish this flow first: a page given its code would run nothing.']);
        $this->assertNull($draft->fresh()->template_code);
    }
}
