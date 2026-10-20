<?php

namespace Tests\Feature\Chatblast;

use App\Models\PageSetting;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

/**
 * Ang Edit ng isang page sa /chatbot/pages: ang modal ay kumukuha ng mga field sa /chatbot/data (walang bagong endpoint)
 * at nagse-save sa /chatbot/cells. Ang galaw ng modal mismo ay nasa browser check.
 */
class ChatbotPageEditTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_20_100000_create_botcake_chats_table.php']]);
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '2002');
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    public function test_S_096_1_the_data_gives_one_pages_fields_in_column_order_with_their_values_only_with_access(): void
    {
        $this->settings()->set('Page Uno', 'chatbot', 'price', '499');
        $this->settings()->set('Page Uno', 'chatbot', 'main_flow', "Hi\nThere");
        $this->settings()->set('Page Dos', 'chatbot', 'price', '1');

        $this->getJson('/chatbot/data')->assertUnauthorized();
        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        $this->getJson('/chatbot/data')->assertForbidden();

        $this->actingAs($this->user());
        $data = $this->getJson('/chatbot/data')->assertOk()->json();
        $this->assertSame(array_column(app(ChatbotFields::class)->columns(), 'key'), array_column($data['columns'], 'key'), 'ang ayos ng grid');
        $uno = collect($data['rows'])->firstWhere('page', 'Page Uno');
        $this->assertSame(['price' => '499', 'main_flow' => "Hi\nThere"], array_intersect_key($uno['cells'], ['price' => 1, 'main_flow' => 1]));
        $this->assertSame('1001', $uno['page_id']);
    }

    public function test_S_096_2_saving_one_page_changes_only_that_page(): void
    {
        $this->actingAs($this->user());
        $this->settings()->set('Page Uno', 'chatbot', 'price', '499');
        $this->settings()->set('Page Dos', 'chatbot', 'price', '499');
        $before = PageSetting::where('page_name', 'Page Dos')->count();

        $res = $this->postJson('/chatbot/cells', ['blanks_empty' => true, 'changes' => [
            ['page_name' => 'Page Uno', 'key' => 'price', 'value' => '599'],
            ['page_name' => 'Page Uno', 'key' => 'main_flow', 'value' => "Line one\nLine two"],
        ]])->assertOk()->json();

        $this->assertSame([2, 1], [$res['saved'], $res['pages']]);
        $this->assertSame(['599', "Line one\nLine two"], [$this->settings()->get('Page Uno', 'chatbot', 'price'), $this->settings()->get('Page Uno', 'chatbot', 'main_flow')]);
        $this->assertSame('499', $this->settings()->get('Page Dos', 'chatbot', 'price'));
        $this->assertNull($this->settings()->get('Page Dos', 'chatbot', 'main_flow'));
        $this->assertSame($before, PageSetting::where('page_name', 'Page Dos')->count(), 'walang bagong row sa ibang page');
    }

    public function test_S_096_3_each_row_has_an_edit_button_and_the_global_paste_modal_is_gone(): void
    {
        $this->actingAs($this->user());
        $html = $this->get('/chatbot/pages')->assertOk()->getContent();

        foreach (['id="pe"', 'Paste a row copied from Google Sheets (with or without the header line)', 'id="pe-start"', 'id="pe-blanks"',
            'id="pe-save"', 'id="pe-cancel"', "'aria-label': 'Edit ' + r.page", 'firstRowOnly: true'] as $part) {
            $this->assertStringContainsString($part, $html);
        }
        foreach (['id="paste-open"', 'id="paste-pages"', 'Paste values', 'tick-all', 'Select all shown pages'] as $gone) {
            $this->assertStringNotContainsString($gone, $html);
        }
    }
}
