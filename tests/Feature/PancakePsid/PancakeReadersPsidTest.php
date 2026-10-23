<?php

namespace Tests\Feature\PancakePsid;

use App\Models\MacroOutput;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Retrieve2 (index + export), Retrieve Orders at ang conversations list: PSID muna, pangalan kapag walang PSID. */
class PancakeReadersPsidTest extends PancakePsidTestCase
{
    private const RANGE = ['date_from' => '2026-10-20', 'date_to' => '2026-10-20'];

    private ?User $staff = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapPage('Page Uno', '5001');
        $this->mapPage('Page Dos', '5002');
        // Walang CONVERT_TZ sa sqlite: ang petsa ng test ay nasa Manila day na ng row.
        DB::connection()->getPdo()->sqliteCreateFunction('CONVERT_TZ', fn ($d) => $d, 3);
    }

    private function me(): User
    {
        return $this->staff ??= $this->user();
    }

    private function order(array $extra): MacroOutput
    {
        return MacroOutput::create($extra + ['PAGE' => 'Page Uno', 'ts_date' => '2026-10-20']);
    }

    private function convOn(string $pageId, string $name, ?string $psid): void
    {
        DB::table('pancake_conversations')->insert([
            'pancake_page_id' => $pageId, 'full_name' => $name, 'psid' => $psid,
            'customers_chat' => 'chat 09171234567', 'created_at' => '2026-10-20 10:00:00', 'updated_at' => '2026-10-20 10:00:00',
        ]);
    }

    private function seedRetrieve2(): void
    {
        $this->convOn('5001', 'Ana Nameless', '11111111111111111');   // may order sa PSID, walang pangalan ang order
        $this->convOn('5001', 'Ben Same', '22222222222222222');       // kapangalan ng ibang customer na may order
        $this->convOn('5001', 'Cara Elsewhere', '33333333333333333'); // ang order ng PSID ay nasa ibang page
        $this->convOn('5001', 'Dan Old', null);                       // walang PSID, may order sa pangalan
        $this->convOn('5001', 'Eli Missing', null);                   // walang PSID, walang order
        $this->order(['fb_name' => null, 'botcake_psid' => '11111111111111111']);
        $this->order(['fb_name' => 'Ben Same', 'botcake_psid' => '99999999999999999']);
        $this->order(['PAGE' => 'Page Dos', 'fb_name' => 'Cara Elsewhere', 'botcake_psid' => '33333333333333333']);
        $this->order(['fb_name' => 'Dan Old']);
    }

    public function test_retrieve2_export_hides_orders_found_by_psid_and_keeps_the_name_rule_without_psid(): void
    {
        $this->seedRetrieve2();

        $res = $this->actingAs($this->me())->getJson('/pancake/retrieve2/export?' . http_build_query(self::RANGE))->assertOk();

        $names = collect($res->json('rows'))->pluck('psid', 'full_name')->all();
        $this->assertSame(['Ben Same' => '22222222222222222', 'Cara Elsewhere' => '33333333333333333', 'Eli Missing' => null], $names);
        $this->assertSame(3, $res->json('count'));
    }

    public function test_retrieve2_page_lists_the_same_rows_with_a_psid_column_in_one_query(): void
    {
        $this->seedRetrieve2();

        DB::enableQueryLog();
        $res = $this->actingAs($this->me())->get('/pancake/retrieve2?' . http_build_query(self::RANGE))->assertOk();
        $conversationQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "pancake_conversations"'))->count();
        DB::disableQueryLog();

        $res->assertSeeText('PSID')->assertSeeText('22222222222222222')->assertSeeText('Ben Same')->assertSeeText('Eli Missing');
        $res->assertDontSeeText('Ana Nameless')->assertDontSeeText('Dan Old');
        // Isa para sa bilang ng rows at isa para sa mismong rows (paginate): hindi lumalaki kapag dumami ang rows.
        $this->assertLessThanOrEqual(2, $conversationQueries);
    }

    public function test_retrieve_orders_matches_a_pasted_psid_by_botcake_psid_and_names_by_fb_name(): void
    {
        $this->order(['fb_name' => null, 'botcake_psid' => '11111111111111111', 'SHOP DETAILS' => 'Shop A']);
        $this->order(['fb_name' => 'Ben Same']);

        $res = $this->actingAs($this->me())->postJson('/pancake/retrieve-orders/check', [
            'senders' => ['11111111111111111', 'Ben Same', '55555555555555555', 'Walang Order'],
        ])->assertOk();

        $this->assertEqualsCanonicalizing(['11111111111111111', 'Ben Same'], $res->json('existing'));
        $this->assertEquals(['11111111111111111' => 'psid', 'Ben Same' => 'name'], $res->json('matched'));
        $this->assertSame(['Shop A'], $res->json('shops')['11111111111111111']);
        $this->assertSame(['Page Uno'], $res->json('pages')['11111111111111111']);
    }

    public function test_conversations_list_shows_the_psid_and_the_search_matches_a_psid_exactly(): void
    {
        $this->convOn('5001', 'Ana Cruz', '12345678901234567');
        $this->convOn('5001', 'Ben Reyes', '76543210987654321');

        $hit = $this->actingAs($this->me())->get('/pancake/index?q=12345678901234567')->assertOk();
        $hit->assertSeeText('Ana Cruz')->assertDontSeeText('Ben Reyes')->assertSeeText('PSID');

        // Exact lang ang PSID: kapiranggot na digits ay hindi tumutugma.
        $this->actingAs($this->me())->get('/pancake/index?q=1234567890123456')->assertOk()->assertDontSeeText('Ana Cruz');
    }
}
