<?php

namespace Tests\Feature\Botcake;

use App\Models\BotcakePsid;
use App\Services\BotcakeCustomerMatcher;

/** Ang orders (huling 30 araw, walang PSID) ay kinakabitan ng PSID mula sa mga available na Botcake customer ng page. */
class BotcakeCustomerMatchTest extends BotcakeApiTestCase
{
    private function available(string $psid, string $name, array $phones = [], string $page = 'Page Uno', string $status = 'available', ?string $adId = null): BotcakePsid
    {
        return BotcakePsid::create([
            'page' => $page, 'fb_name' => $name, 'psid' => $psid, 'status' => $status, 'source' => 'api',
            'phone_numbers' => $phones, 'ad_id' => $adId, 'ad_ids' => $adId ? [$adId] : [],
        ]);
    }

    public function test_orders_get_a_psid_only_when_exactly_one_customer_fits(): void
    {
        $this->available('psid-a', 'Ana  Cruz');
        $this->available('psid-b1', 'Ben Reyes');
        $this->available('psid-b2', 'ben reyes', ['09181234567']);
        $this->available('psid-z', 'Zeny Uy', ['09195550000']); // ibang tao
        $this->available('psid-in', 'Eva Tan', [], 'Page Uno', 'inactive'); // inactive: hindi kandidato
        $this->available('psid-c', 'Carla S.', ['+63 917 111 2222']);
        $this->available('psid-x', 'Ana Cruz', [], 'Other Page'); // ibang page: hindi kandidato
        // Isang sheet record ng parehong pangalan ay hindi kandidato (available na API rows lang).
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Ana Cruz', 'psid' => 'psid-sheet', 'status' => 'matched']);

        $name     = $this->order(' page uno ', ' ana cruz ');
        $twins    = $this->order('Page Uno', 'Ben Reyes', '09990000000');
        $byPhone  = $this->order('Page Uno', 'Carla Santos', '0917-111-2222');
        $none     = $this->order('Page Uno', 'Dino Lim', '09170000000');
        $decided  = $this->order('Page Uno', 'Ben Reyes', '0918 123 4567');
        $third    = $this->order('Page Uno', 'Ben Reyes', '09195550000');
        $inactive = $this->order('Page Uno', 'Eva Tan');
        $hasPsid  = $this->order('Page Uno', 'Ana Cruz', null, 'kept-psid');
        $old      = $this->order('Page Uno', 'Ana Cruz', null, null, now('Asia/Manila')->subDays(31)->toDateString());

        $counts = app(BotcakeCustomerMatcher::class)->matchPage('Page Uno');

        $this->assertSame('psid-a', $name->fresh()->botcake_psid, 'iisang psid sa pangalan (trim, espasyo, case)');
        $this->assertNull($twins->fresh()->botcake_psid, 'dalawang psid sa parehong pangalan: walang isusulat');
        $this->assertSame('psid-c', $byPhone->fresh()->botcake_psid, 'phone kapag hindi tumugma ang pangalan');
        $this->assertNull($none->fresh()->botcake_psid);
        $this->assertSame('psid-b2', $decided->fresh()->botcake_psid, 'dalawang kandidato sa pangalan: ang phone ang nagpapasya kung isa sa kanila');
        $this->assertNull($third->fresh()->botcake_psid, 'ang phone ng ikatlong tao ay hindi pumapalit sa ambiguous na pangalan');
        $this->assertNull($inactive->fresh()->botcake_psid, 'inactive na customer: hindi ginagamit');
        $this->assertSame('kept-psid', $hasPsid->fresh()->botcake_psid, 'hindi pinapalitan ang may laman');
        $this->assertNull($old->fresh()->botcake_psid, 'lampas 30 araw: hindi ginagalaw');

        $this->assertSame(['by_name' => 1, 'by_phone' => 2, 'ambiguous' => 2, 'no_match' => 2], array_intersect_key($counts, array_flip(['by_name', 'by_phone', 'ambiguous', 'no_match'])));
    }

    public function test_an_api_customer_never_turns_a_sheet_psid_into_a_conflict(): void
    {
        $this->available('psid-api', 'Ana Cruz');
        $order = $this->order('Page Uno', 'Ana Cruz');
        $setting = \App\Models\BotcakePsidSetting::create(['gsheet_name' => 'T', 'sheet_url' => 'https://docs.google.com/spreadsheets/d/T1/edit', 'sheet_range' => 'PSID!A:J']);

        $status = app(\App\Services\BotcakePsidMatcher::class)->recordSheetRow($setting, 'Page Uno', 'Ana Cruz', 'psid-sheet', '2026-10-17');

        $this->assertSame('matched', $status);
        $this->assertSame('psid-sheet', $order->fresh()->botcake_psid);
    }

    public function test_the_customers_ad_id_is_written_with_the_psid_and_never_changed_afterwards(): void
    {
        $ana = $this->available('psid-a', 'Ana Cruz', [], 'Page Uno', 'available', '120250000000000001');
        $this->available('psid-c', 'Carla S.', ['09171112222'], 'Page Uno', 'available', '120250000000000003');
        $this->available('psid-n', 'Nina Go'); // walang ad
        $byName  = $this->order('Page Uno', 'Ana Cruz');
        $byPhone = $this->order('Page Uno', 'Carla Santos', '09171112222');
        $noAd    = $this->order('Page Uno', 'Nina Go');
        $m = app(BotcakeCustomerMatcher::class);

        $m->matchPage('Page Uno');

        $this->assertSame(['psid-a', '120250000000000001'], [$byName->fresh()->botcake_psid, $byName->fresh()->botcake_ad_id]);
        $this->assertSame(['psid-c', '120250000000000003'], [$byPhone->fresh()->botcake_psid, $byPhone->fresh()->botcake_ad_id]);
        $this->assertSame(['psid-n', null], [$noAd->fresh()->botcake_psid, $noAd->fresh()->botcake_ad_id]);

        // Bagong ad ng customer sa susunod na sync: hindi nagbabago ang naitala sa order, kahit ma-match ulit.
        $ana->update(['ad_id' => '120250000000000009', 'ad_ids' => ['120250000000000001', '120250000000000009']]);
        $m->matchPage('Page Uno');
        $this->assertSame('120250000000000001', $byName->fresh()->botcake_ad_id);

        \Illuminate\Support\Facades\DB::table('macro_output')->where('id', $byName->id)->update(['botcake_psid' => null]);
        $m->matchPage('Page Uno');
        $this->assertSame(['psid-a', '120250000000000001'], [$byName->fresh()->botcake_psid, $byName->fresh()->botcake_ad_id]);
    }

    public function test_an_order_with_a_psid_but_no_ad_id_gets_the_ad_id_of_its_page_customer(): void
    {
        $this->available('psid-s', 'Ana Cruz', [], 'Page Uno', 'inactive', '120250000000000001');
        $this->available('psid-n', 'Nina Go'); // walang ad
        $this->available('psid-o', 'Olga Ty', [], 'Other Page', 'available', '120250000000000099');
        $withAd = fn (string $psid, ?string $ad, ?string $date = null) => tap($this->order('Page Uno', 'Buyer', null, $psid, $date),
            fn ($o) => \Illuminate\Support\Facades\DB::table('macro_output')->where('id', $o->id)->update(['botcake_ad_id' => $ad]));

        $sheet  = $withAd('psid-s', null);     // PSID mula sa lumang sheet, walang ad id
        $blank  = $withAd('psid-s', '');
        $kept   = $withAd('psid-s', '120250000000000005');
        $other  = $withAd('psid-o', null);     // customer ng ibang page: hindi ginagamit
        $noAd   = $withAd('psid-n', null);
        $noRow  = $withAd('psid-wala', null);
        $old    = $withAd('psid-s', null, now('Asia/Manila')->subDays(31)->toDateString());

        $m = app(BotcakeCustomerMatcher::class);
        $counts = $m->matchPage('Page Uno');

        $got = fn ($o) => [$o->fresh()->botcake_psid, $o->fresh()->botcake_ad_id];
        $this->assertSame(['psid-s', '120250000000000001'], $got($sheet));
        $this->assertSame(['psid-s', '120250000000000001'], $got($blank), 'ang blangkong ad id ay walang laman din');
        $this->assertSame(['psid-s', '120250000000000005'], $got($kept), 'ang may laman ay hindi pinapalitan');
        $this->assertSame(['psid-o', null], $got($other));
        $this->assertSame(['psid-n', null], $got($noAd));
        $this->assertSame(['psid-wala', null], $got($noRow));
        $this->assertSame(['psid-s', null], $got($old), 'lampas 30 araw: hindi ginagalaw');
        $this->assertSame(2, $counts['ad_filled']);

        $this->assertSame(0, $m->matchPage('Page Uno')['ad_filled'], 'isang beses lang');
        $this->assertArrayHasKey('ad_filled', $m->matchPagesWithTokens());
    }

    public function test_a_psid_from_the_sheet_import_leaves_the_ad_id_empty(): void
    {
        $this->available('psid-a', 'Ana Cruz', [], 'Page Uno', 'available', '120250000000000001');
        $order = $this->order('Page Uno', 'Ana Cruz');
        $setting = \App\Models\BotcakePsidSetting::create(['gsheet_name' => 'T', 'sheet_url' => 'https://docs.google.com/spreadsheets/d/T1/edit', 'sheet_range' => 'PSID!A:J']);

        app(\App\Services\BotcakePsidMatcher::class)->recordSheetRow($setting, 'Page Uno', 'Ana Cruz', 'psid-a', '2026-10-17');

        $this->assertSame(['psid-a', null], [$order->fresh()->botcake_psid, $order->fresh()->botcake_ad_id]);
    }
}
