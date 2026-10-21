<?php

namespace Tests\Feature\Chatblast;

use App\Services\PageSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Ang "Flow at template" ng bawat page sa /chatblast/campaigns: ang pangalan ng flow.shipping, ang approved template na
 * gamit nito, ang pangalan ng field.message, at ang buong preview; "suriin" kapag mukhang mali. Fake na Botcake lang.
 */
class ChatblastFlowInfoTest extends ChatblastTestCase
{
    private array $flows = [];
    private array $templates = [];
    private array $fields = [];
    private int $flowsHttp = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user());
        $this->page('Page Uno');
        $this->flows = ['success' => true, 'data' => ['folders' => [['name' => 'Shipping', 'flows' => [
            ['id' => 5500, 'name' => 'Ibang flow'], ['id' => 5501, 'name' => 'CHATBLAST Shipping update'],
        ]]]]];
        $this->templates = ['success' => true, 'data' => [
            ['name' => 'post_order', 'status' => 'APPROVED', 'category' => 'UTILITY',
                'components' => [['type' => 'HEADER', 'text' => 'x'], ['type' => 'BODY', 'text' => "Update po sa order:\n{{1}}"]]],
            ['name' => 'promo_sale', 'status' => 'APPROVED', 'category' => 'MARKETING', 'components' => [['type' => 'BODY', 'text' => 'Sale! {{1}}']]],
        ]];
        $this->fields = ['success' => true, 'data' => [['id' => 7700, 'name' => 'iba'], ['id' => 7701, 'name' => 'chatblast_message']]];
        Http::fake(['botcake.io/*' => function (Request $req) {
            if (str_ends_with($req->url(), '/flows') || str_contains($req->url(), '/flows?')) return Http::response($this->flows, $this->flowsHttp);
            if (str_contains($req->url(), '/message_templates')) return Http::response($this->templates);
            if (str_contains($req->url(), '/custom_fields')) return Http::response($this->fields);
            return Http::response(['success' => false], 404);
        }]);
    }

    private function info(array $query = [])
    {
        return $this->getJson('/chatblast/campaigns/flow?' . http_build_query($query + ['page' => 'Page Uno']));
    }

    public function test_the_flow_its_template_and_the_field_are_shown_with_the_full_preview_and_cached(): void
    {
        $res = $this->info()->assertOk();
        $this->assertSame(['5501', 'CHATBLAST Shipping update'], [$res->json('flow.id'), $res->json('flow.name')]);
        $this->assertSame(['post_order', 'APPROVED', 'UTILITY', "Update po sa order:\n{{1}}", 'setup'],
            [$res->json('template.name'), $res->json('template.status'), $res->json('template.category'), $res->json('template.body'), $res->json('template.source')]);
        $this->assertSame(['7701', 'chatblast_message'], [$res->json('field.id'), $res->json('field.name')]);
        $this->assertStringStartsWith("Update po sa order:\nHello Ma'am/Sir Juan Dela Cruz, nasa biyahe na po ang order niyo", $res->json('preview'));
        $this->assertSame([], $res->json('check'));
        $this->assertStringNotContainsString(self::TOKEN, $res->getContent());

        $this->info()->assertOk();
        Http::assertSentCount(3); // naka-cache nang 10 minuto
        $this->info(['refresh' => 1])->assertOk();
        Http::assertSentCount(6);
    }

    public function test_a_flow_without_chatblast_or_a_template_without_the_slot_is_flagged_and_a_page_setting_picks_the_template(): void
    {
        app(PageSettings::class)->set('Page Uno', 'botcake', 'flow.shipping', '5500');
        app(PageSettings::class)->set('Page Uno', 'botcake', 'template.shipping', 'promo_sale');
        $this->templates['data'][1]['components'][0]['text'] = 'Sale ngayon!';
        $this->templates['data'][1]['status'] = 'PAUSED';

        $res = $this->info()->assertOk();
        $this->assertSame(['Ibang flow', 'promo_sale', 'setting'], [$res->json('flow.name'), $res->json('template.name'), $res->json('template.source')]);
        // Walang babala sa pangalan ng flow (CHATBLAST): ang Setup box ang may tamang pangalan, at ang setup check ang nagsusuri.
        $this->assertCount(2, $res->json('check'));
        $this->assertStringContainsString('{{1}}', $res->json('check.0'));
        $this->assertStringContainsString('PAUSED', $res->json('check.1'));
        $this->assertStringNotContainsString('CHATBLAST', implode(' ', $res->json('check')));
    }

    public function test_a_missing_field_is_flagged_and_the_sample_comes_from_a_campaign_that_sends_to_the_page(): void
    {
        $this->fields = ['success' => true, 'data' => [['id' => 7700, 'name' => 'iba']]];
        \App\Models\ChatblastCampaign::where('kind', 'in_transit')->update(['pages' => json_encode(['Ibang Page'])]);

        $res = $this->info()->assertOk();
        $this->assertSame(['field.message was not found in Botcake: the message will not show.'], $res->json('check'));
        $this->assertSame('Delivering', $res->json('sample.campaign'), 'ang In transit ay para sa ibang page');
    }

    public function test_a_botcake_error_is_shown_without_the_token_and_flagged(): void
    {
        $this->flowsHttp = 401;
        $res = $this->info()->assertOk();
        $this->assertNull($res->json('flow.name'));
        $this->assertStringContainsString('HTTP 401', $res->json('flow.error'));
        $this->assertNotEmpty($res->json('check'));
        $this->assertStringNotContainsString(self::TOKEN, $res->getContent());
    }

    public function test_a_page_without_chatblast_settings_or_another_role_gets_nothing(): void
    {
        $this->info(['page' => 'Walang Settings'])->assertStatus(422);
        $this->actingAs($this->user('Staff', 'staff@example.test'));
        $this->info()->assertNotFound();
        Http::assertNothingSent();
    }
}
