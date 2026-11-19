<?php

namespace Tests\Feature\PancakePsid;

use App\Models\MacroOutput;
use App\Services\AstraEncoder;
use App\Services\MacroChecker;
use App\Services\PancakeChat;
use App\Services\PlaceholderName;

/**
 * Spec 307 FU-1 / FU-2: ang "Facebook User" (pangalang ipinapakita ng Facebook kapag nakatago ang profile) ay hindi
 * kailanman nakakahanap ng Pancake chat, at ang order na may PSID ay hindi humihiram ng chat ayon sa pangalan.
 */
class PlaceholderNameLookupTest extends PancakePsidTestCase
{
    private const PAGE = 'Elyse Abad';
    private const PAGE_ID = '6001';
    private const OWN_PSID = '25000000000000001';
    private const OTHER_PSID = '25000000000000002';

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapPage(self::PAGE, self::PAGE_ID);
    }

    /** Ang chat ng ibang customer na "Facebook User" din sa parehong page (ang chat ni Mercedita noong 18/11). */
    private function otherCustomersChat(): void
    {
        $this->conv(self::PAGE_ID, 'Facebook User', "Name: Mercedita Santos\nPhone: 09170000001\nAddress: Purok 3", self::OTHER_PSID);
    }

    private function order(array $extra = []): MacroOutput
    {
        return MacroOutput::create($extra + [
            'PAGE' => self::PAGE, 'fb_name' => 'Facebook User', 'botcake_psid' => self::OWN_PSID, 'ts_date' => '2026-11-18', 'STATUS' => null,
        ]);
    }

    public function test_the_placeholder_constant_covers_empty_and_any_case_or_spacing(): void
    {
        foreach (['', '   ', null, 'Facebook User', 'facebook user', 'FACEBOOK USER', ' Facebook   User ', "Facebook\u{00A0}User", "Facebook\u{200B} User",
            'FacebookUser', "Facebook\tuser", 'Messenger User', 'messenger  user', 'Instagram User'] as $name) {
            $this->assertTrue(PlaceholderName::is($name), var_export($name, true));
        }
        foreach (['Ana Cruz', 'Facebook', 'User', 'Facebook Users', 'Facebook User Ana', 'Mercedita Santos'] as $name) {
            $this->assertFalse(PlaceholderName::is($name), $name);
        }
        $this->assertSame(['Facebook User', 'Messenger User', 'Instagram User'], PlaceholderName::NAMES);
    }

    public function test_FU_1_a_placeholder_order_whose_psid_chat_is_not_imported_gets_no_chat_and_nothing_of_the_other_customer(): void
    {
        $this->otherCustomersChat();
        $o = $this->order();
        $svc = app(PancakeChat::class);

        $this->assertNull($svc->forOrder(self::PAGE, 'Facebook User', self::OWN_PSID));
        // Kahit walang PSID: ang placeholder ay hindi umaabot sa page + pangalan o sa pangalan lang.
        foreach (['Facebook User', 'facebook  user', 'Messenger User', ''] as $name) {
            $this->assertNull($svc->forOrder(self::PAGE, $name, null), $name);
            $this->assertNull($svc->forOrder('Ibang Page', $name, null), $name);
        }

        // Ang mga bumabasa nito: Astra (EARLIER CONVERSATION at get_chat_history) at Checker 1 pass 2.
        $this->assertSame('', AstraEncoder::pancakeHistory((string) $o->fb_name, $o->PAGE, $o->botcake_psid));
        $this->assertSame('', (new MacroChecker())->fetchPancakeChat((string) $o->fb_name, $o->PAGE, $o->botcake_psid));
        $this->assertSame('', AstraEncoder::pancakeHistory('Facebook User'));
        $this->assertSame('', (new MacroChecker())->fetchPancakeChat('Facebook User', self::PAGE));

        // "See more" sa Checker 1: walang chat, hindi ang chat ng iba.
        $staff = $this->user();
        $this->actingAs($staff)->postJson('/macro_output/pancake-more', ['id' => $o->id])->assertOk()->assertJson(['found' => false, 'text' => '']);
        $this->actingAs($staff)->postJson('/macro_output/pancake-more', ['fb_name' => 'Facebook User'])->assertOk()->assertJson(['found' => false]);
    }

    public function test_FU_1_the_same_order_gets_its_own_chat_matched_by_psid_once_it_is_imported(): void
    {
        $this->otherCustomersChat();
        $o = $this->order();
        $this->conv(self::PAGE_ID, 'Facebook User', 'sariling chat ng customer', self::OWN_PSID);
        $this->otherCustomersChat(); // mas bagong row ng ibang customer: hindi pa rin

        $hit = app(PancakeChat::class)->forOrder(self::PAGE, 'Facebook User', self::OWN_PSID);

        $this->assertSame(['sariling chat ng customer', PancakeChat::MATCH_PSID], [$hit['chat'], $hit['match']]);
        $this->assertSame('sariling chat ng customer', AstraEncoder::pancakeHistory((string) $o->fb_name, $o->PAGE, $o->botcake_psid));
        $this->actingAs($this->user())->postJson('/macro_output/pancake-more', ['id' => $o->id])
            ->assertJson(['found' => true, 'text' => 'sariling chat ng customer', 'label' => 'Matched by PSID']);
    }

    public function test_FU_2_an_order_with_a_psid_never_borrows_a_real_names_chat(): void
    {
        $this->conv(self::PAGE_ID, 'Ana Cruz', 'ibang Ana Cruz', self::OTHER_PSID);
        $this->conv(self::PAGE_ID, 'Ana Cruz', 'Ana Cruz na walang PSID');
        $o = $this->order(['fb_name' => 'Ana Cruz']);
        $svc = app(PancakeChat::class);

        $this->assertNull($svc->forOrder(self::PAGE, 'Ana Cruz', self::OWN_PSID));
        $this->assertNull($svc->forOrder('Hindi Mapped', 'Ana Cruz', self::OWN_PSID));
        $this->assertSame('', AstraEncoder::pancakeHistory('Ana Cruz', self::PAGE, self::OWN_PSID));
        $this->assertSame('', (new MacroChecker())->fetchPancakeChat('Ana Cruz', self::PAGE, self::OWN_PSID));
        $this->actingAs($this->user())->postJson('/macro_output/pancake-more', ['id' => $o->id])->assertJson(['found' => false]);
    }

    public function test_FU_2_N_an_order_without_a_psid_keeps_the_page_and_name_then_name_only_fallbacks(): void
    {
        $this->mapPage('Page Dos', '6002');
        $this->conv('6002', 'Ben Reyes', 'Ben sa ibang page');
        $this->conv(self::PAGE_ID, 'Ana Cruz', 'Ana sa page', self::OTHER_PSID);
        $svc = app(PancakeChat::class);
        $ana = $this->order(['fb_name' => 'Ana Cruz', 'botcake_psid' => null]);
        $ben = $this->order(['fb_name' => 'Ben Reyes', 'botcake_psid' => '']);

        $page = $svc->forOrder(self::PAGE, 'Ana Cruz', null);
        $name = $svc->forOrder(self::PAGE, 'Ben Reyes', '');

        $this->assertSame(['Ana sa page', PancakeChat::MATCH_PAGE_NAME], [$page['chat'], $page['match']]);
        $this->assertSame(['Ben sa ibang page', PancakeChat::MATCH_NAME_ONLY], [$name['chat'], $name['match']]);
        $this->assertSame('Ana sa page', AstraEncoder::pancakeHistory((string) $ana->fb_name, $ana->PAGE, $ana->botcake_psid));
        $this->assertSame('Ben sa ibang page', (new MacroChecker())->fetchPancakeChat((string) $ben->fb_name, $ben->PAGE, $ben->botcake_psid));
        $this->actingAs($this->user())->postJson('/macro_output/pancake-more', ['id' => $ana->id])
            ->assertJson(['found' => true, 'text' => 'Ana sa page', 'label' => 'Matched by page and name']);
    }
}
