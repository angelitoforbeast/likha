<?php

namespace Tests\Feature\PancakePsid;

use App\Models\MacroOutput;
use App\Models\User;
use App\Services\PancakeChat;
use Illuminate\Support\Facades\DB;

/** Ang iisang lookup ng Pancake chat ng isang order (service) at ang "See more" / has_pancake na gumagamit nito. */
class PancakeChatLookupTest extends PancakePsidTestCase
{
    private const MORE = '/macro_output/pancake-more';

    private ?User $staff = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapPage('Page Uno', '5001');
        $this->mapPage('Page Dos', '5002');
    }

    private function order(array $extra = []): MacroOutput
    {
        return MacroOutput::create($extra + ['PAGE' => 'Page Uno', 'fb_name' => 'Ana Cruz', 'ts_date' => '2026-10-20', 'STATUS' => 'PROCEED']);
    }

    private function more(array $body)
    {
        return $this->actingAs($this->staff ??= $this->user())->postJson(self::MORE, $body);
    }

    public function test_service_prefers_psid_then_page_and_name_then_name_only(): void
    {
        $this->conv('5002', 'Ana Cruz', 'ibang page, pangalan lang');
        $this->conv('5001', 'Ana Cruz', 'page at pangalan');
        $this->conv('5001', 'Iba Pangalan', 'psid chat', '12345678901234567');
        $svc = app(PancakeChat::class);

        $psid = $svc->forOrder('Page Uno', 'Ana Cruz', '12345678901234567');
        $pageName = $svc->forOrder('Page Uno', 'Ana Cruz', '99999999999999999');
        $nameOnly = $svc->forOrder('Page Tres', 'Ana Cruz', null);

        $this->assertSame(['psid chat', 'psid'], [$psid['chat'], $psid['match']]);
        $this->assertSame(['page at pangalan', 'page_name'], [$pageName['chat'], $pageName['match']]);
        $this->assertSame(['page at pangalan', 'name_only'], [$nameOnly['chat'], $nameOnly['match']]);
        $this->assertNull($svc->forOrder('Page Uno', 'Walang Ganito', '99999999999999999'));
    }

    public function test_service_scopes_the_psid_to_the_mapped_page_and_uses_it_alone_when_the_page_is_not_mapped(): void
    {
        $this->conv('5002', 'Ana', 'sa Page Dos', '12345678901234567');
        $svc = app(PancakeChat::class);

        $this->assertNull($svc->forOrder('Page Uno', '', '12345678901234567'));
        $this->assertSame('psid', $svc->forOrder('Hindi Mapped', '', '12345678901234567')['match']);
    }

    public function test_see_more_returns_the_psid_customers_chat_with_its_label_not_the_same_name_customer(): void
    {
        $this->conv('5001', 'Ana Cruz', 'ibang Ana', '76543210987654321');
        $this->conv('5001', 'Ana Cruz', 'tamang Ana <b>x</b>', '12345678901234567');
        $this->conv('5001', 'Ana Cruz', 'pinakabagong Ana', '11111111111111111');
        $order = $this->order(['botcake_psid' => '12345678901234567']);

        $res = $this->more(['id' => $order->id])->assertOk();

        // Hilaw ang text sa JSON; ang view ang nagse-set nito bilang textContent (hindi innerHTML).
        $res->assertJson(['found' => true, 'text' => 'tamang Ana <b>x</b>', 'label' => 'Matched by PSID']);
    }

    public function test_see_more_labels_page_and_name_and_name_only_matches(): void
    {
        $this->conv('5002', 'Ben', 'ibang page', null);
        $this->conv('5001', 'Cara', 'sa page', null);
        $byPage = $this->order(['fb_name' => 'Cara']);
        $byName = $this->order(['fb_name' => 'Ben']);

        $this->more(['id' => $byPage->id])->assertJson(['text' => 'sa page', 'label' => 'Matched by page and name']);
        $this->more(['id' => $byName->id])->assertJson(['text' => 'ibang page', 'label' => 'Matched by name only — may be another customer']);
    }

    public function test_see_more_keeps_the_fb_name_request_and_validates_the_input(): void
    {
        $this->conv('5001', 'Cara', 'sa page');

        $this->more(['fb_name' => 'Cara'])->assertJson(['found' => true, 'text' => 'sa page', 'label' => 'Matched by name only — may be another customer']);
        $this->more([])->assertStatus(422);
        $this->more(['id' => 'abc'])->assertStatus(422);
        $this->more(['id' => 999999])->assertJson(['found' => false]);
    }

    public function test_has_pancake_is_true_for_a_psid_only_order_and_the_list_stays_one_query_per_part(): void
    {
        $this->conv('5001', 'Walang Pangalan Dito', 'chat', '12345678901234567');
        $psidOnly = $this->order(['fb_name' => null, 'botcake_psid' => '12345678901234567']);
        $byName = $this->order(['fb_name' => 'Walang Pangalan Dito']);
        $none = $this->order(['fb_name' => 'Wala', 'botcake_psid' => '55555555555555555']);

        DB::enableQueryLog();
        $res = $this->actingAs($this->user())->get('/encoder/checker_1?date=2026-10-20')->assertOk();
        $withExists = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'has_pancake'));
        DB::disableQueryLog();

        $html = $res->getContent();
        foreach ([$psidOnly->id => true, $byName->id => true, $none->id => false] as $id => $expect) {
            $this->assertSame($expect, $this->rowHasSeeMore($html, $id), "order $id");
        }
        // Isang query lang ang humahawak sa pancake_conversations para sa buong list: walang N+1.
        $this->assertCount(1, $withExists);
    }

    private function rowHasSeeMore(string $html, int $id): bool
    {
        preg_match('/<tr[^>]*data-id="' . $id . '".*?<\/tr>/s', $html, $m);

        return str_contains($m[0] ?? '', 'see-more-link');
    }
}
