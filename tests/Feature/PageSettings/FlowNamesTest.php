<?php

namespace Tests\Feature\PageSettings;

use App\Models\PageSetting;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Botcake\BotcakeApiTestCase;

/**
 * Ang pangalan ng bawat flow ID at ang template ng shipping flow sa /page-settings: mula sa GET flows at
 * GET message_templates ng Botcake (pekeng HTTP at orasan dito), itinatala bilang system keys sa page_settings.
 */
class FlowNamesTest extends BotcakeApiTestCase
{
    private const TOKEN = 'tok-flow-names-8w2j';
    private const API = 'botcake.io/api/public_api/v1/pages/1001/';

    protected function setUp(): void
    {
        parent::setUp();

        $s = $this->settings();
        $s->set('Page Uno', 'botcake', 'page_id', '1001');
        $s->set('Page Uno', 'botcake', 'token', self::TOKEN);
        $this->actingAs($this->user());
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    private function cell(string $key, string $value)
    {
        return $this->postJson('/page-settings/cell', ['page_name' => 'Page Uno', 'provider' => 'botcake', 'key' => $key, 'value' => $value]);
    }

    private function grid(): array
    {
        return $this->get('/page-settings')->assertOk()->viewData('grid');
    }

    public function test_S_052_6_the_name_and_text_keys_are_refused_from_the_page_and_the_template_key_is_editable(): void
    {
        Http::fake([self::API . 'message_templates*' => Http::response(['data' => [], 'success' => true])]);

        foreach (['flow.shipping.name', 'flow.on_delivery.name', 'flow.custom_x.name', 'template.shipping.text'] as $key) {
            $this->cell($key, 'Kahit ano')->assertStatus(422);
            $this->post('/page-settings', ['page_name' => 'Page Uno', 'provider' => 'botcake', 'key' => $key, 'value' => 'Kahit ano'])
                ->assertSessionHasErrors('value');
        }
        $this->assertSame(0, PageSetting::where('key', 'like', '%.name')->orWhere('key', 'like', '%.text')->count());

        $this->cell('template.shipping', 'post_order')->assertOk()->assertJsonPath('cell.value', 'post_order');
        $this->assertSame('post_order', $this->settings()->get('Page Uno', 'botcake', 'template.shipping'));
    }

    public function test_S_052_7_name_and_text_rows_sit_in_their_cells_and_are_not_columns(): void
    {
        $s = $this->settings();
        $s->set('Page Uno', 'botcake', 'flow.shipping', '7001');
        $s->set('Page Uno', 'botcake', 'flow.shipping.name', 'SHIPPING STATUS', system: true);
        $s->set('Page Uno', 'botcake', 'flow.custom_x.name', '!wala', system: true);
        $s->set('Page Uno', 'botcake', 'template.shipping.text', 'Follow up po {{1}}', system: true);

        $grid = $this->grid();
        $keys = array_column($grid['columns'], 'key');

        $this->assertNotContains('botcake.flow.shipping.name', $keys);
        $this->assertNotContains('botcake.flow.custom_x.name', $keys);
        $this->assertNotContains('botcake.template.shipping.text', $keys);
        $this->assertSame(array_search('botcake.flow.broadcast', $keys) + 1, array_search('botcake.template.shipping', $keys));
        $this->assertFalse(collect($grid['columns'])->firstWhere('key', 'botcake.template.shipping')['readonly']);

        $uno = collect($grid['rows'])->firstWhere('name', 'Page Uno');
        $this->assertSame('SHIPPING STATUS', $uno['cells']['botcake.flow.shipping.name']['line']);
        $this->assertSame(['Wala sa Botcake', 'red'], [$uno['cells']['botcake.flow.custom_x.name']['line'], $uno['cells']['botcake.flow.custom_x.name']['tone']]);
        $this->assertSame('Follow up po (mensahe ng sequence)', $uno['cells']['botcake.template.shipping.text']['line']);
        $this->assertSame('Follow up po {{1}}', $uno['cells']['botcake.template.shipping.text']['value'], 'buo ang body para sa hover');
    }
}
