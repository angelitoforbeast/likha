<?php

namespace Tests\Feature\Botcake;

use App\Models\BotcakePsid;
use App\Services\BotcakeCustomerMatcher;

/** Ang orders (huling 30 araw, walang PSID) ay kinakabitan ng PSID mula sa mga available na Botcake customer ng page. */
class BotcakeCustomerMatchTest extends BotcakeApiTestCase
{
    private function available(string $psid, string $name, array $phones = [], string $page = 'Page Uno', string $status = 'available'): void
    {
        BotcakePsid::create([
            'page' => $page, 'fb_name' => $name, 'psid' => $psid, 'status' => $status, 'source' => 'api',
            'phone_numbers' => $phones,
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

    public function test_each_unmatched_order_keeps_its_reason_until_it_gets_a_psid(): void
    {
        $this->available('psid-b1', 'Ben Reyes');
        $this->available('psid-b2', 'Ben Reyes');
        $twins = $this->order('Page Uno', 'Ben Reyes');
        $dino  = $this->order('Page Uno', 'Dino Lim');
        $m = app(BotcakeCustomerMatcher::class);

        $m->matchPage('Page Uno');
        $this->assertSame([$twins->id => 'ambiguous', $dino->id => 'no_match'],
            \Illuminate\Support\Facades\DB::table('botcake_psid_misses')->orderBy('order_id')->pluck('reason', 'order_id')->all());

        $this->available('psid-d', 'Dino Lim');
        $m->matchPage('Page Uno');
        $this->assertSame('psid-d', $dino->fresh()->botcake_psid);
        $this->assertSame([$twins->id], \Illuminate\Support\Facades\DB::table('botcake_psid_misses')->pluck('order_id')->all());
    }
}
