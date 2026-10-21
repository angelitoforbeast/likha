<?php

namespace Tests\Feature\Chatblast;

use App\Models\PageSetting;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

/**
 * /chatbot/cells: ang sabay-sabay na save ng mga botfield mula sa Paste values (at sa paste sa grid). Isang request,
 * isang transaction, lahat o wala.
 */
class ChatbotPasteTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_20_100000_create_botcake_chats_table.php']]);
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '2002');
        app(PageSettings::class)->set('Page Tres', 'botcake', 'page_id', '3003'); // walang token: wala sa listahan
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    private function value(string $page, string $key): ?string
    {
        return $this->settings()->get($page, ChatbotFields::PROVIDER, $key);
    }

    private function change(string $page, string $key, string $value): array
    {
        return ['page_name' => $page, 'key' => $key, 'value' => $value];
    }

    public function test_S_092_5_save_writes_every_change_in_one_request_keeps_history_and_returns_the_previous_values(): void
    {
        $this->actingAs($this->user());
        $this->settings()->set('Page Uno', 'chatbot', 'price', '499');

        $res = $this->postJson('/chatbot/cells', ['changes' => [
            $this->change('Page Uno', 'price', ' 599 '),
            $this->change('Page Uno', 'main_flow', "Hi\nThere"),
            $this->change('Page Dos', 'price', '1'),
            $this->change('Page Dos', 'price', '599'), // parehong cell: ang huli ang masusunod
            $this->change('page dos', 'seq_img_1', 'https://img.example.test/a.jpg'),
            $this->change('Page Dos', 'quantity', ''), // blangko, walang value: walang nagbago
        ]])->assertOk()->json();

        $this->assertSame([4, 2, 0], [$res['saved'], $res['pages'], $res['skipped']]);
        $this->assertEqualsCanonicalizing([
            $this->change('Page Uno', 'price', '499'),
            $this->change('Page Uno', 'main_flow', ''),
            $this->change('Page Dos', 'price', ''),
            $this->change('Page Dos', 'seq_img_1', ''),
        ], $res['undo']);
        $this->assertSame(['599', "Hi\nThere", '599', 'https://img.example.test/a.jpg'],
            [$this->value('Page Uno', 'price'), $this->value('Page Uno', 'main_flow'), $this->value('Page Dos', 'price'), $this->value('Page Dos', 'seq_img_1')]);
        $old = PageSetting::where('page_name', 'Page Uno')->where('provider', 'chatbot')->where('key', 'price')->where('active', false)->sole();
        $this->assertSame('499', $old->value);
        $this->assertNotNull($old->replaced_at);

        // Parehong value ulit: walang bagong row.
        $again = $this->postJson('/chatbot/cells', ['changes' => [$this->change('Page Uno', 'price', '599')]])->assertOk()->json();
        $this->assertSame([0, 1, []], [$again['saved'], $again['unchanged'], $again['undo']]);
        $this->assertSame(2, PageSetting::where('page_name', 'Page Uno')->where('key', 'price')->count());
    }

    public function test_S_092_6_one_bad_change_refuses_the_whole_save(): void
    {
        $this->actingAs($this->user());
        $this->settings()->set('Page Uno', 'chatbot', 'price', '499');
        $good = $this->change('Page Uno', 'price', '777');

        foreach ([
            'too many letters' => $this->change('Page Dos', 'prompt_1', str_repeat('a', ChatbotFields::MAX_VALUE + 1)),
            'too many bytes' => $this->change('Page Dos', 'prompt_1', str_repeat('ñ', 30001)),
            'not a link' => $this->change('Page Dos', 'picture', 'www.img.example.test/a.jpg'),
            'unknown column' => $this->change('Page Dos', 'no_such_column', 'x'),
            'page not on the list' => $this->change('Page Tres', 'price', '1'),
            'no page' => $this->change('', 'price', '1'),
            'not text' => ['page_name' => 'Page Dos', 'key' => 'price', 'value' => ['x']],
        ] as $case => $bad) {
            $res = $this->postJson('/chatbot/cells', ['changes' => [$good, $bad]])->assertStatus(422)->json();
            $this->assertSame($bad['page_name'], $res['errors'][0]['page_name'], $case);
            $this->assertSame($bad['key'], $res['errors'][0]['key'], $case);
            $this->assertSame('499', $this->value('Page Uno', 'price'), $case . ': walang na-save');
        }
        $this->postJson('/chatbot/cells', ['changes' => 'x'])->assertStatus(422);
        $this->postJson('/chatbot/cells', ['changes' => []])->assertStatus(422);
        $this->postJson('/chatbot/cells', ['changes' => array_fill(0, 5001, $good)])->assertStatus(422); // hanggang 5000 kada save
        $this->assertNull($this->value('Page Dos', 'prompt_1'));
    }

    public function test_S_092_7_blanks_keep_the_value_unless_blanks_as_empty_and_active_and_flow_are_skipped(): void
    {
        $this->actingAs($this->user());
        $this->settings()->set('Page Uno', 'chatbot', 'price', '499');
        $this->settings()->set('Page Uno', 'chatbot', 'flow', 'Main COD');

        $res = $this->postJson('/chatbot/cells', ['changes' => [
            $this->change('Page Uno', 'price', ''),
            $this->change('Page Uno', 'active', '0'),
            $this->change('Page Uno', 'flow', 'Other'),
            $this->change('Page Uno', 'quantity', '2'),
        ]])->assertOk()->json();
        $this->assertSame([1, 2], [$res['saved'], $res['skipped']]);
        $this->assertSame(['499', null, 'Main COD', '2'], [$this->value('Page Uno', 'price'), $this->value('Page Uno', 'active'), $this->value('Page Uno', 'flow'), $this->value('Page Uno', 'quantity')]);

        $res = $this->postJson('/chatbot/cells', ['blanks_empty' => true, 'changes' => [$this->change('Page Uno', 'price', '')]])->assertOk()->json();
        $this->assertSame([1, [$this->change('Page Uno', 'price', '499')]], [$res['saved'], $res['undo']]);
        $this->assertNull($this->value('Page Uno', 'price'));
        $this->assertSame(1, PageSetting::where('page_name', 'Page Uno')->where('key', 'price')->count(), 'binura lang (inactive), naiwan bilang history');
    }

    public function test_S_092_8_without_chatblast_access_the_bulk_save_is_refused(): void
    {
        $body = ['changes' => [$this->change('Page Uno', 'price', '1')]];
        $this->post('/chatbot/cells', $body)->assertRedirect();
        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        $this->postJson('/chatbot/cells', $body)->assertForbidden();
        $this->assertNull($this->value('Page Uno', 'price'));
    }

    public function test_S_092_9_undo_restores_every_field_of_the_save(): void
    {
        $this->actingAs($this->user());
        $this->settings()->set('Page Uno', 'chatbot', 'price', '499');
        $this->settings()->set('Page Dos', 'chatbot', 'prompt_1', "Line one\nLine two");

        $undo = $this->postJson('/chatbot/cells', ['changes' => [
            $this->change('Page Uno', 'price', '599'),
            $this->change('Page Uno', 'quantity', '3'),
            $this->change('Page Dos', 'prompt_1', 'New prompt'),
        ]])->assertOk()->json('undo');

        $res = $this->postJson('/chatbot/cells', ['blanks_empty' => true, 'changes' => $undo])->assertOk()->json();
        $this->assertSame(3, $res['saved']);
        $this->assertSame(['499', null, "Line one\nLine two"], [$this->value('Page Uno', 'price'), $this->value('Page Uno', 'quantity'), $this->value('Page Dos', 'prompt_1')]);
    }
}
