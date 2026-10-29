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

    /** Isang request na pirmado gaya ng Forbeast: HMAC ng "<timestamp>.<body>" (walang body ang GET). */
    private function signed(string $method, string $url, string $body = '', string $secret = self::SECRET, array $headers = [])
    {
        $ts = time();
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GATEWAY_TIMESTAMP' => (string) $ts,
            'HTTP_X_GATEWAY_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $ts . '.' . ($method === 'GET' ? '' : $body), $secret)];
        foreach ($headers as $k => $v) $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;

        return $this->call($method, $url, [], [], [], $server, $body);
    }

    private function apply(string $code, string $pageId = '1001', array $headers = ['X-Forbeast-User' => self::ACTOR], string $secret = self::SECRET)
    {
        return $this->signed('POST', '/api/gateway/pages/' . $pageId . '/flow', json_encode(['code' => $code]), $secret, $headers);
    }

    /** Ang template na ginagamit ng karamihan ng case: bawat uri ng botfield, at isang text ng flow na hindi dapat lumabas. */
    private function template(): ChatbotFlow
    {
        $flow = $this->flow('Main Flow from CBB', [
            $this->send('FLOW-OWN-TEXT {{Main Flow}}', '{{SHOP DETAILS}}'),
            ['send', ['items' => [['type' => 'image', 'value' => '{{PICTURE}}'], ['type' => 'text', 'value' => '{{Seq 2}}'], ['type' => 'image', 'value' => '{{Seq Img 3}}']]]],
            ['ai', ['system' => '{{Prompt 1}}', 'user' => '{{last_input}}', 'save_to' => 'answer']],
        ]);
        $flow->issueTemplateCode();

        return $flow->fresh();
    }

    /** Ang mga value ng Page Uno (Likha ang sumulat). */
    private function values(): void
    {
        foreach (['main_flow' => 'Hello {{Fill-Up Form}}', 'price' => '198', 'seq_2' => 'Step two', 'loop_3_form' => 'Old loop form', 'seq_1' => 'Unused step'] as $k => $v) {
            $this->settings()->set('Page Uno', ChatbotFields::PROVIDER, $k, $v);
        }
        $this->settings()->set('Page Dos', ChatbotFields::PROVIDER, 'price', 'PAGE-DOS-PRICE-777');
    }

    private function active(string $page, string $key): ?PageSetting
    {
        return app(PageSettings::class)->pageQuery($page)->where('provider', ChatbotFields::PROVIDER)->where('key', $key)->where('active', true)->first();
    }

    public function test_S_195_2_get_a_code_returns_the_flow_and_its_fields_grouped_with_required_and_this_pages_values(): void
    {
        $this->values();
        $flow = $this->template();

        $res = $this->signed('GET', '/api/gateway/flow-codes/' . strtolower($flow->template_code) . '?page=1001')->assertOk();

        $this->assertSame($flow->template_code, $res->json('code'));
        $this->assertSame(['id' => $flow->id, 'name' => 'Main Flow from CBB', 'steps' => 3], array_diff_key($res->json('flow'), ['updated_at' => 1]));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $res->json('flow.updated_at'));
        $fields = collect($res->json('fields'));
        $this->assertSame([
            ['item_name_in_jnt_dashboard', 'Product', true], ['price', 'Product', true], ['quantity', 'Product', true], ['picture', 'Product', true],
            ['main_flow', 'Forms and messages', true], ['fill_up_form', 'Forms and messages', true],
            ['seq_2', 'Sequence', true], ['seq_img_2', 'Sequence', false], ['seq_3', 'Sequence', false], ['seq_img_3', 'Sequence', true],
            ['shop_details', 'Shop details', false],
            ['prompt_1', 'Other', true],
        ], $fields->map(fn ($f) => [$f['key'], $f['group'], $f['required']])->all());

        $price = $fields->firstWhere('key', 'price');
        $this->assertEquals(['label' => 'PRICE', 'type' => 'text', 'kind' => 'number', 'read_only' => false, 'editable' => true, 'value' => '198', 'updated_by' => 'likha', 'pair' => null,
            'max_length' => ChatbotFields::MAX_VALUE], array_intersect_key($price, array_flip(['label', 'type', 'kind', 'read_only', 'editable', 'value', 'updated_by', 'pair', 'max_length'])));
        $this->assertIsString($price['hint']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $price['updated_at']);
        $this->assertEquals(['kind' => 'image', 'type' => 'image', 'value' => null, 'updated_at' => null, 'updated_by' => null], array_intersect_key($fields->firstWhere('key', 'picture'), array_flip(['kind', 'type', 'value', 'updated_at', 'updated_by'])));
        $this->assertEquals(['kind' => 'long_text', 'read_only' => true, 'editable' => false, 'required' => false, 'max_length' => null], array_intersect_key($fields->firstWhere('key', 'shop_details'), array_flip(['kind', 'read_only', 'editable', 'required', 'max_length'])));
        $this->assertSame("PAGE: Page Uno\nPRICE: ₱198", $fields->firstWhere('key', 'shop_details')['value']);
        $this->assertSame(['Hello {{Fill-Up Form}}', 'long_text'], [$fields->firstWhere('key', 'main_flow')['value'], $fields->firstWhere('key', 'main_flow')['kind']]);

        // Ang sariling text ng flow, ang ibang page at ang mga hindi ginagamit ay hindi lumalabas.
        foreach (['FLOW-OWN-TEXT', 'PAGE-DOS-PRICE-777', 'Old loop form', 'Unused step', self::SECRET] as $never) $this->assertStringNotContainsString($never, $res->getContent());
    }

    public function test_S_195_3_post_a_code_sets_the_pages_flow_writes_history_and_keeps_every_value(): void
    {
        $this->values();
        $old = $this->flow('Old flow', [$this->send('{{Loop 3 Form}}')]);
        app(\App\Services\Chatbot\ChatbotPage::class)->link('Page Uno', $old);
        $flow = $this->template();
        $before = PageSetting::where('provider', ChatbotFields::PROVIDER)->where('active', true)->whereNotIn('key', ['flow', 'flow_id'])->orderBy('id')->get(['page_name', 'key', 'value'])->toArray();

        $now = $this->signed('GET', '/api/gateway/pages/1001/flow')->assertOk();
        $this->assertSame(['page_id' => '1001', 'page_name' => 'Page Uno', 'code' => null], array_intersect_key($now->json(), array_flip(['page_id', 'page_name', 'code'])));
        $this->assertSame([$old->id, ['loop_3_form']], [$now->json('flow.id'), array_column($now->json('fields'), 'key')]);

        $res = $this->apply('  ' . strtolower($flow->template_code) . ' ')->assertOk();

        $this->assertSame(['code' => $flow->template_code, 'changed' => true], array_intersect_key($res->json(), array_flip(['code', 'changed'])));
        $this->assertSame($flow->id, $res->json('flow.id'));
        $this->assertSame('main_flow', $res->json('fields.4.key'));
        $this->assertSame((string) $flow->id, $this->settings()->get('Page Uno', ChatbotFields::PROVIDER, 'flow_id'));
        $this->assertSame($flow->id, app(\App\Services\Chatbot\ChatbotPage::class)->flow('Page Uno')?->id);
        $this->assertSame('Main Flow from CBB', $this->active('Page Uno', 'flow')->plainValue());
        $this->assertSame($before, PageSetting::where('provider', ChatbotFields::PROVIDER)->where('active', true)->whereNotIn('key', ['flow', 'flow_id'])->orderBy('id')->get(['page_name', 'key', 'value'])->toArray(), 'walang value na nabura o napalitan');
        $this->assertNull($this->settings()->get('Page Dos', ChatbotFields::PROVIDER, 'flow_id'));

        // Ang history ng page: sino at aling code.
        $this->actingAs($this->user());
        $top = $this->getJson('/chatbot/history?page_name=Page%20Uno&key=flow')->assertOk()->json('versions.0');
        $this->assertSame(['value' => 'Main Flow from CBB', 'by' => 'via Forbeast: with code ' . $flow->template_code . ', by ' . self::ACTOR], array_intersect_key($top, ['value' => 1, 'by' => 1]));
        // Ang Pages screen: ang pangalan at code ng flow.
        $row = collect($this->getJson('/chatbot/data')->assertOk()->json('rows'))->firstWhere('page', 'Page Uno');
        $this->assertSame(['Main Flow from CBB', $flow->template_code], [$row['cells']['flow'], $row['flow_code']]);

        // Ulit, parehong code, walang user: walang bago.
        $this->assertFalse($this->apply($flow->template_code, '1001', [])->assertOk()->json('changed'));
        $this->assertSame(1, $this->settings()->pageQuery('Page Uno')->where('provider', ChatbotFields::PROVIDER)->where('key', 'flow_id')->where('source', 'like', 'with code%')->count());
    }

    public function test_S_195_3_a_page_without_a_flow_reads_null_and_the_apply_without_a_user_still_says_forbeast(): void
    {
        $this->assertEquals(['flow' => null, 'code' => null, 'fields' => []], array_intersect_key($this->signed('GET', '/api/gateway/pages/1001/flow')->assertOk()->json(), ['flow' => 1, 'code' => 1, 'fields' => 1]));

        $flow = $this->template();
        $this->apply($flow->template_code, '1001', [])->assertOk();
        $this->assertSame('with code ' . $flow->template_code, $this->active('Page Uno', 'flow')->source);
        $this->apply($flow->template_code, '1001', ['X-Forbeast-User' => str_repeat('x', 201)])->assertStatus(422)->assertJson(['error' => 'invalid_value']);
    }

    public function test_S_195_4_a_revoked_or_unknown_code_is_404_and_changes_nothing(): void
    {
        $flow = $this->template();
        $code = $flow->template_code;
        $this->apply($code)->assertOk();
        $flow->revokeTemplateCode();
        $other = $this->flow('Other', [$this->send('Hi')]);
        app(\App\Services\Chatbot\ChatbotPage::class)->link('Page Dos', $other);

        foreach ([$code, 'LK-ZZZZ-ZZ', 'not a code', str_repeat('A', 300)] as $bad) {
            $this->signed('GET', '/api/gateway/flow-codes/' . rawurlencode($bad) . '?page=1001')->assertNotFound()->assertJson(['error' => 'unknown_code']);
            $this->apply($bad)->assertNotFound()->assertJson(['error' => 'unknown_code']);
        }
        // Ang page na nakatali na ay nananatili sa flow kahit binawi ang code.
        $this->assertSame((string) $flow->id, $this->settings()->get('Page Uno', ChatbotFields::PROVIDER, 'flow_id'));
        $this->assertSame($flow->id, $this->signed('GET', '/api/gateway/pages/1001/flow')->assertOk()->json('flow.id'));
        $this->assertNull($this->signed('GET', '/api/gateway/pages/1001/flow')->json('code'));
    }

    public function test_S_195_5_a_page_that_is_not_on_this_site_is_404_on_every_flow_endpoint(): void
    {
        $code = $this->template()->template_code;

        $this->signed('GET', '/api/gateway/flow-codes/' . $code . '?page=9999')->assertNotFound()->assertJson(['error' => 'unknown_page']);
        $this->signed('GET', '/api/gateway/flow-codes/' . $code)->assertNotFound()->assertJson(['error' => 'unknown_page']);
        $this->signed('GET', '/api/gateway/pages/9999/flow')->assertNotFound()->assertJson(['error' => 'unknown_page']);
        $this->apply($code, '9999')->assertNotFound()->assertJson(['error' => 'unknown_page']);
        $this->assertSame(0, PageSetting::where('key', 'flow_id')->count());
    }

    public function test_S_195_6_an_unsigned_or_wrongly_signed_call_is_401_and_changes_nothing(): void
    {
        $code = $this->template()->template_code;

        $this->call('GET', '/api/gateway/flow-codes/' . $code . '?page=1001')->assertUnauthorized()->assertJson(['error' => 'bad_signature']);
        $this->signed('GET', '/api/gateway/flow-codes/' . $code . '?page=1001', '', 'wrong-secret')->assertUnauthorized();
        $this->signed('GET', '/api/gateway/pages/1001/flow', '', 'wrong-secret')->assertUnauthorized();
        $this->apply($code, '1001', [], 'wrong-secret')->assertUnauthorized();
        // Page Dos ay walang sariling secret: ang secret ng Page Uno ay hindi gumagana roon.
        $this->apply($code, '1002')->assertUnauthorized();
        $this->call('POST', '/api/gateway/pages/1001/flow', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['code' => $code]))->assertUnauthorized();
        $this->assertSame(0, PageSetting::where('key', 'flow_id')->count());
    }

    public function test_S_195_7_a_flow_naming_system_keys_never_makes_them_editable_from_forbeast(): void
    {
        $flow = $this->flow('Sneaky', [$this->send('{{FLOW}} {{flow_id}} {{provider}} {{gateway}} {{active}} {{ai_provider}} {{auto_switch}} {{SHOP DETAILS}} {{Prompt 3}}')]);
        $flow->issueTemplateCode();
        $this->apply($flow->fresh()->template_code)->assertOk();
        $put = fn (array $values) => $this->signed('PUT', '/api/gateway/pages/1001/values', json_encode(['values' => $values]), self::SECRET, ['X-Forbeast-User' => self::ACTOR]);

        foreach (['flow' => 'Other', 'flow_id' => '999', 'provider' => 'botcake', 'gateway' => 'incepxionbot', 'active' => '0', 'ai_provider' => 'local', 'auto_switch' => '{}', 'shop_details' => 'x'] as $key => $value) {
            $put([$key => $value])->assertStatus(422)->assertJson(['error' => 'field_not_editable', 'key' => $key]);
        }
        $this->assertSame((string) $flow->id, $this->settings()->get('Page Uno', ChatbotFields::PROVIDER, 'flow_id'));
        $this->assertNull($this->settings()->get('Page Uno', ChatbotFields::PROVIDER, 'provider'));
        $put(['prompt_3' => 'Needed by the flow'])->assertOk();
    }

    public function test_S_195_7_values_from_forbeast_read_back_as_forbeast_likha_marks_them_and_needed_fields_can_be_filled(): void
    {
        $this->values();
        $flow = $this->template();
        $this->apply($flow->template_code)->assertOk();
        $put = fn (array $values) => $this->signed('PUT', '/api/gateway/pages/1001/values', json_encode(['values' => $values]), self::SECRET, ['X-Forbeast-User' => self::ACTOR]);

        $put(['main_flow' => 'From Forbeast', 'prompt_1' => 'You sell tape.'])->assertOk();
        $put(['prompt_2' => 'Not used by the flow'])->assertStatus(422)->assertJson(['error' => 'field_not_editable', 'key' => 'prompt_2']);
        $this->assertSame('You sell tape.', $this->settings()->get('Page Uno', ChatbotFields::PROVIDER, 'prompt_1'));

        $fields = collect($this->signed('GET', '/api/gateway/pages/1001/values')->assertOk()->json('fields'));
        $this->assertSame(['forbeast', true], [$fields->firstWhere('key', 'main_flow')['updated_by'], $fields->firstWhere('key', 'main_flow')['required']]);
        $price = $fields->firstWhere('key', 'price');
        $this->assertSame(['likha', true, 'Product', 'number'], [$price['updated_by'], $price['required'], $price['group'], $price['kind']]);
        $this->assertSame([false, null], [$fields->firstWhere('key', 'loop_3_form')['required'], $fields->firstWhere('key', 'main_flow_2')['updated_by']]);
        // Ang dating kontrata ay buo pa: walang prompt sa values GET.
        $this->assertNull($fields->firstWhere('key', 'prompt_1'));
        $this->assertSame('forbeast', collect($this->signed('GET', '/api/gateway/pages/1001/flow')->json('fields'))->firstWhere('key', 'prompt_1')['updated_by']);

        $this->actingAs($this->user());
        $row = collect($this->getJson('/chatbot/data')->assertOk()->json('rows'))->firstWhere('page', 'Page Uno');
        $this->assertSame(['main_flow' => self::ACTOR, 'prompt_1' => self::ACTOR], $row['from_forbeast']);
        $this->assertStringContainsString('via Forbeast', $this->get('/chatbot/pages')->assertOk()->getContent());
    }

    public function test_S_195_8_publishing_the_flow_in_likha_changes_every_page_that_uses_it(): void
    {
        $flow = $this->template();
        $this->apply($flow->template_code)->assertOk();
        app(\App\Services\Chatbot\ChatbotPage::class)->link('Page Dos', $flow);
        $bot = app(\App\Services\Chatbot\ChatbotPage::class);
        $this->assertSame($flow->graph, $bot->flow('Page Uno')->graph); // naka-cache na

        $this->actingAs($this->user());
        $graph = FlowGraph::clean(['nodes' => [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'New reply']]]]],
            'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]]);
        $this->putJson("/chatbot/flows/{$flow->id}/graph", ['graph' => $graph, 'version' => $flow->fresh()->draft_version])->assertOk();
        $this->postJson("/chatbot/flows/{$flow->id}/publish", ['version' => $flow->fresh()->draft_version])->assertOk();

        foreach (['Page Uno', 'Page Dos'] as $page) $this->assertSame($graph, $bot->flow($page)->graph, $page);
        $this->assertSame(1, $this->signed('GET', '/api/gateway/pages/1001/flow')->json('flow.steps'));
    }

    public function test_S_195_9_seq_and_seq_img_come_back_as_pairs_and_only_the_used_one_is_required(): void
    {
        $flow = $this->flow('Pairs', [['send', ['items' => [['type' => 'image', 'value' => '{{Seq Img 5}}'], ['type' => 'text', 'value' => '{{Seq 12}}']]]]]);
        $flow->issueTemplateCode();

        $fields = collect($this->signed('GET', '/api/gateway/flow-codes/' . $flow->fresh()->template_code . '?page=1001')->assertOk()->json('fields'));

        $this->assertSame([['seq_5', 5, false], ['seq_img_5', 5, true], ['seq_12', 12, false], ['seq_img_12', 12, false]],
            $fields->map(fn ($f) => [$f['key'], $f['pair'], $f['required']])->all(), 'Seq 11-20 ay opsyonal gaya ng Check');
        $this->assertSame(['long_text', 'image'], [$fields[0]['kind'], $fields[1]['kind']]);
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

        // Ang flow na step ng sequence ay hindi main flow: walang code.
        $step = $this->flow('Follow-up step', [$this->send('Hi again')]);
        $seq = \App\Models\ChatbotSequence::create(['name' => 'Follow-ups', 'active' => true]);
        \App\Models\ChatbotSequenceStep::create(['sequence_id' => $seq->id, 'position' => 1, 'delay_amount' => 1, 'delay_unit' => 'hours', 'flow_id' => $step->id, 'active' => true]);
        $this->postJson("/chatbot/flows/{$step->id}/code")->assertStatus(422)->assertJson(['error' => 'This flow is a sequence step, not a main flow: a page cannot run it as its flow.']);
        $this->assertNull($step->fresh()->template_code);
    }
}
