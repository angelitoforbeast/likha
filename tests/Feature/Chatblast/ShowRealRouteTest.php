<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Services\Chatbot\ChatbotProvider;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowRun;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;

/**
 * Ang totoong address na dinaanan ng sagot: ang {{replies_via}} ay ang pangalan ng address ng page sa forbeast gateway
 * (hal. "Incepxion") kapag hindi ito ang default; kung wala, gaya ng dati.
 */
class ShowRealRouteTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test/api';
    private const APP2 = 'https://incepxion.example.test/api';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
        ]]);
        config([
            'services.chatbot.replies_enabled' => true,
            'services.gateway.url' => self::GW, 'services.gateway.key' => 'gw_global_key',
            'services.gateway.urls' => 'incepxion=' . self::APP2,
            'services.incepxionbot.url' => '',
        ]);
        $this->page('Page Uno', '1001');
    }

    private function chat(array $payload): int
    {
        return BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => '7001', 'name' => 'Ana', 'message' => 'hi',
            'message_id' => 'm' . random_int(1, 999999), 'payload' => $payload, 'received_at' => now()])->id;
    }

    private function via(?int $chatId): string
    {
        return (new FlowRun(1, [], 'Page Uno', '1001', [], new CustomerFields(1001, 7001), $chatId, 'hi', 'Ana', '2026-11-04 09:00'))->text('{{replies_via}}');
    }

    private function gateway(?string $app): void
    {
        $s = app(PageSettings::class);
        $s->set('Page Uno', 'chatbot', 'provider', 'gateway');
        if ($app !== null) $s->set('Page Uno', 'chatbot', 'gateway_app', $app, false, null, true);
    }

    public function test_S_224_1_1_a_page_on_forbeast_without_an_address_is_forbeast(): void
    {
        $this->gateway(null);
        $this->assertSame('Forbeast', $this->via($this->chat(['source' => 'gateway'])));
        $this->assertSame('Forbeast', $this->via(null));
    }

    public function test_S_224_1_2_a_page_on_the_incepxion_address_is_incepxion(): void
    {
        $this->gateway('incepxion');
        $this->assertSame('Incepxion', $this->via($this->chat(['source' => 'gateway'])));
        $this->assertSame('Incepxion', $this->via(null), 'without a chat: the page provider and its address');
        $this->assertSame('Incepxion', ChatbotProvider::repliesVia('Page Uno', ChatbotProvider::FORBEAST));
    }

    public function test_S_224_1_3_botcake_stays_botcake_even_with_an_address(): void
    {
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'gateway_app', 'incepxion', false, null, true);
        $this->assertSame('Botcake', $this->via(null), 'page on Botcake');
        $this->gateway('incepxion');
        $this->assertSame('Botcake', $this->via($this->chat(['source' => 'default_reply'])), 'a chat Botcake delivered');
    }

    public function test_S_224_1_4_without_gateway_urls_the_label_is_todays(): void
    {
        config(['services.gateway.urls' => '']);
        $this->gateway('incepxion');
        $this->assertSame('Forbeast', $this->via($this->chat(['source' => 'gateway'])));
        $this->assertSame('Forbeast', $this->via(null));
        $this->assertSame('Incepxionbot', ChatbotProvider::repliesVia('Page Uno', 'incepxionbot'), 'the other gateway keeps its label');
        $this->assertSame('Botcake', ChatbotProvider::repliesVia('Page Uno', ChatbotProvider::BOTCAKE));
    }

    public function test_S_224_1_5_the_variable_help_names_the_address(): void
    {
        $info = (new \ReflectionClassConstant(\App\Http\Controllers\ChatbotFlowController::class, 'RUN_VAR_INFO'))->getValue();
        $this->assertStringContainsString('Incepxion', $info['replies_via']);
    }
}
