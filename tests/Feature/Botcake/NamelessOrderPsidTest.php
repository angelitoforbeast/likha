<?php

namespace Tests\Feature\Botcake;

use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Services\BotcakeCustomerMatcher;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Ang order na walang Facebook name ay kinakabitan ng PSID sa phone: customer values muna, saka chat text. */
class NamelessOrderPsidTest extends BotcakeApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
        ]]);
        app(PageSettings::class)->set('Page Uno', 'botcake', 'page_id', '1001');
        BotcakeChat::forgetPageMap();
    }

    private function value(string $psid, string $phone, int $page = 1001): void
    {
        DB::table('chatbot_customer_values')->insert(['page_id' => $page, 'psid' => $psid, 'key' => 'gpt phone number', 'value' => $phone]);
    }

    private function chat(string $psid, string $message, ?array $payload = null, int $page = 1001): void
    {
        BotcakeChat::create(['page_id' => (string) $page, 'psid' => $psid, 'name' => 'x', 'message' => $message, 'payload' => $payload, 'received_at' => now()]);
    }

    private function matchPage(): array
    {
        return app(BotcakeCustomerMatcher::class)->matchPage('Page Uno');
    }

    public function test_S_121_3_1_customer_values_give_the_psid_when_exactly_one_customer_has_the_phone(): void
    {
        $this->value('111', '+63 912 345 6789');
        $this->value('222', '09987654321');
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Ana', 'psid' => '111', 'status' => 'available', 'source' => 'api', 'ad_id' => '120250000000000009']);
        $order = $this->order('Page Uno', '', '0912 345 6789');

        $this->matchPage();

        $this->assertSame(['111', '120250000000000009'], [$order->fresh()->botcake_psid, $order->fresh()->botcake_ad_id]);
    }

    public function test_S_121_3_2_chat_text_gives_the_psid_when_customer_values_have_nothing(): void
    {
        $this->chat('333', 'ito po number ko 0912-345-6789 salamat');
        $this->chat('444', 'walang numero dito');
        $this->chat('555', '09123456789', ['_standby' => true]);   // kopya habang nasa Forbeast: hindi binibilang
        $order = $this->order('Page Uno', '', '09123456789');

        $this->matchPage();

        $this->assertSame('333', $order->fresh()->botcake_psid);
    }

    public function test_S_121_3_3_two_customers_with_the_phone_means_no_write(): void
    {
        $this->value('111', '09123456789');
        $this->value('112', '639123456789');
        $this->chat('333', '09123456789');
        $this->chat('334', '09123456789');
        $byValues = $this->order('Page Uno', '', '09123456789');

        $this->matchPage();
        $this->assertNull($byValues->fresh()->botcake_psid, 'dalawa sa values: hindi bumababa sa chat text');

        DB::table('chatbot_customer_values')->delete();
        $this->matchPage();
        $this->assertNull($byValues->fresh()->botcake_psid, 'dalawa sa chat text: walang isusulat');
    }

    public function test_S_121_3_4_an_existing_psid_and_a_named_order_are_never_touched(): void
    {
        $this->value('111', '09123456789');
        $hasPsid = $this->order('Page Uno', '', '09123456789', 'kept-psid');
        $named = $this->order('Page Uno', 'Ana Cruz', '09123456789');

        $this->matchPage();

        $this->assertSame('kept-psid', $hasPsid->fresh()->botcake_psid);
        $this->assertNull($named->fresh()->botcake_psid, 'may pangalan: ang fallback ay para sa walang pangalan lang');
    }

    public function test_S_121_3_5_another_pages_customer_is_not_a_candidate(): void
    {
        $this->value('111', '09123456789', 2002);
        $this->chat('333', '09123456789', null, 2002);
        $order = $this->order('Page Uno', '', '09123456789');

        $this->matchPage();

        $this->assertNull($order->fresh()->botcake_psid);
    }
}
