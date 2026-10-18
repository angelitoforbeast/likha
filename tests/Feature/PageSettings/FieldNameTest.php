<?php

namespace Tests\Feature\PageSettings;

use App\Services\PageSettings;
use Tests\Feature\Botcake\BotcakeApiTestCase;

/**
 * Ang linya ng Message field sa Botcake cell: ang ID at ang pangalan ng custom field mula sa naitalang listahan
 * (botcake.field.message.options). Walang tawag sa Botcake; ang page script ang gumuguhit (literal lang dito,
 * walang JS test harness).
 */
class FieldNameTest extends BotcakeApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user());
    }

    public static function states(): array
    {
        return [
            'nasa listahan: ang pangalan'   => ["text: ' · ' + item.name"],
            'wala sa listahan: amber'       => ["el('span', {class: 'text-amber-700', text: ' · (not in Botcake list)'})"],
            'wala pang listahan: grey'      => ["el('span', {class: 'ml-1 text-xs text-gray-400', text: 'press Test for the name'})"],
        ];
    }

    /** @dataProvider states */
    public function test_S_064_1_the_message_field_line_shows_the_name_from_the_stored_list(string $code): void
    {
        $s = app(PageSettings::class);
        $s->set('Page Kwatro', 'botcake', 'field.message', '204121');
        $s->set('Page Kwatro', 'botcake', 'field.message.options', json_encode([['id' => '204121', 'name' => 'GPT-INPUT', 'candidate' => true, 'recommended' => true]]), false, null, true);

        $res = $this->get('/chatblast/page-settings')->assertOk();
        $this->assertStringContainsString($code, htmlspecialchars_decode($res->getContent(), ENT_QUOTES));
        // Ang listahan ay kasama na sa grid ng page: walang hiwalay na tawag para sa pangalan.
        $row = collect($res->viewData('grid')['rows'])->firstWhere('name', 'Page Kwatro');
        $this->assertStringContainsString('GPT-INPUT', $row['cells']['botcake.field.message.options']['value']);
    }
}
