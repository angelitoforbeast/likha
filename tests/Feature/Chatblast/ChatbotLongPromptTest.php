<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowEngine;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\FlowRun;
use App\Services\Chatbot\TestOutbox;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang mahahabang prompt sa botfield: buo ang naiimbak, buo ang napupunta sa AI, at malinaw ang pagtanggi sa sobra.
 * Fake na HTTP; walang tawag sa totoong Botcake o OpenAI.
 */
class ChatbotLongPromptTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
        ]]);
        config(['services.openai.key' => 'sk-test-not-real']);
        $this->page('Page Uno', '1001');
    }

    /** Isang prompt na 14,113 letra na may mga linya, ñ at emoji (gaya ng Prompt 2 ng owner). */
    private static function prompt(): string
    {
        $text = "You are Ate Karla, the seller of one product. Sagutin nang magalang, may ñ at 😊.\n";
        $out = str_repeat($text, intdiv(14113, mb_strlen($text)));

        return $out . str_repeat('x', 14113 - mb_strlen($out));
    }

    public function test_S_073_1_a_14113_character_botfield_is_stored_and_read_back_unchanged(): void
    {
        Http::fake();
        $this->actingAs($this->user());
        $prompt = self::prompt();
        $this->assertSame(14113, mb_strlen($prompt));

        $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'prompt_2', 'value' => $prompt])->assertOk()->assertJson(['value' => $prompt]);

        $this->assertSame($prompt, app(PageSettings::class)->get('Page Uno', 'chatbot', 'prompt_2'));
        $this->assertSame($prompt, collect($this->getJson('/chatbot/data')->json('rows'))->firstWhere('page', 'Page Uno')['cells']['prompt_2']);
        $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'prompt_3', 'value' => str_repeat('a', 50000)])->assertOk();
    }

    public function test_S_073_2_the_ai_step_receives_the_full_expanded_prompt(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Opo!']]]])]);
        $prompt = self::prompt();
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'prompt_2', $prompt);
        $graph = FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start', 'x' => 0, 'y' => 0, 'config' => []],
            ['id' => 'n2', 'type' => 'ai', 'x' => 1, 'y' => 0, 'config' => ['system' => "{{Prompt 2}}\nPrice: {{price}}", 'save_to' => 'GPT INPUT']],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]]);
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'price', '499');

        $run = new FlowRun(1, $graph, 'Page Uno', '1001', app(ChatbotPage::class)->botfields('Page Uno'), new CustomerFields(1001, 7001),
            11, 'Magkano po?', 'Ana Cruz', '2026-10-19 10:00');
        app(FlowEngine::class)->run($run, new TestOutbox());

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $q) => $q['messages'][0] === ['role' => 'system', 'content' => $prompt . "\nPrice: 499"]
            && end($q->data()['messages']) === ['role' => 'user', 'content' => 'Magkano po?']);
    }

    public static function tooLong(): array
    {
        return [
            'over 50,000 characters' => [str_repeat('a', 50001), 'At most 50000 characters.'],
            // 20,001 × "€" (3 bytes) = 60,003 bytes: kasya sa 50,000 letra pero hindi sa TEXT ng MySQL nang ligtas.
            'over 60,000 bytes' => [str_repeat('€', 20001), 'Too long: at most 60000 bytes. Accented letters and emoji take 2 to 4 bytes each.'],
        ];
    }

    /** @dataProvider tooLong */
    public function test_S_073_3_a_value_over_the_limit_gets_422_with_a_clear_message(string $value, string $error): void
    {
        Http::fake();
        $this->actingAs($this->user());
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'prompt_2', 'old prompt');

        $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'prompt_2', 'value' => $value])->assertStatus(422)->assertExactJson(['error' => $error]);

        $this->assertSame('old prompt', app(PageSettings::class)->get('Page Uno', 'chatbot', 'prompt_2'));
        $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'prompt_2', 'value' => str_repeat('€', 20000)])->assertOk(); // 60,000 bytes: kasya
    }
}
