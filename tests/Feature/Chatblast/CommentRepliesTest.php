<?php

namespace Tests\Feature\Chatblast;

use App\Models\PageSetting;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang sagot sa comment ng mga page na nasa Forbeast: ang Comment Reply 1-3 (public, isa na random) at ang Comment PM
 * (isang private reply), per page sa /chatbot/pages. Fake na HTTP; walang tawag sa totoong Forbeast o Meta.
 */
class CommentRepliesTest extends ChatblastTestCase
{
    private const COLUMNS = ['comment_reply_1' => 'Comment Reply 1', 'comment_reply_2' => 'Comment Reply 2',
        'comment_reply_3' => 'Comment Reply 3', 'comment_pm' => 'Comment PM'];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_20_100000_create_botcake_chats_table.php']]);
        $this->page('Page Uno', '1001');
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    public function test_S_149_1_the_four_comment_columns_are_editable_with_history_and_in_the_paste_mapping(): void
    {
        Queue::fake();
        $this->actingAs($this->user());

        $columns = collect($this->getJson('/chatbot/data')->assertOk()->json('columns'))->keyBy('key');
        foreach (self::COLUMNS as $key => $label) {
            $this->assertSame([$label, 'long'], [$columns[$key]['label'] ?? null, $columns[$key]['kind'] ?? null], $key);
        }

        $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'comment_pm', 'value' => 'Hi po! {{PRICE}} lang.'])->assertOk()->assertJson(['status' => 'created']);
        $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'comment_pm', 'value' => 'Hello po!'])->assertOk();
        $this->assertSame('Hello po!', $this->settings()->get('Page Uno', 'chatbot', 'comment_pm'));
        $versions = $this->getJson('/chatbot/history?page_name=Page%20Uno&key=comment_pm')->assertOk()->json('versions');
        $this->assertSame(['Hello po!', 'Hi po! {{PRICE}} lang.'], array_column($versions, 'value'));

        // Ang paste mapping: ang AI ay may isang linyang paliwanag ng bawat bagong field.
        config(['services.openai.key' => 'test-key', 'services.openai.content_check_model' => 'gpt-4o-mini',
            'services.openai.ai_checker_prices' => ['gpt-4o-mini' => [0.15, 0.60]]]);
        $asked = '';
        Http::fake(['api.openai.com/*' => function (HttpRequest $req) use (&$asked) {
            $asked = $req['messages'][1]['content'][0]['text'];
            return Http::response(['choices' => [['message' => ['content' => json_encode(['mapping' => [['cell' => 0, 'field' => 'comment_pm', 'confidence' => 'high']]])]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]]);
        }]);
        $this->postJson('/chatbot/paste-map', ['page_name' => 'Page Uno', 'cells' => ['Salamat po sa comment!']])->assertOk();
        $this->assertStringContainsString('comment_pm | Comment PM | the one private message sent to a person who commented on a post', $asked);
        $this->assertStringContainsString('comment_reply_2 | Comment Reply 2 | a public reply to a comment on a post (variant 2', $asked);
        $this->assertSame(0, PageSetting::where('key', 'comment_reply_1')->count());
    }
}
