<?php

namespace Tests\Feature\Botcake;

use App\Models\BotcakePsid;
use App\Services\BotcakeCustomerMatcher;

/** Ang orders (huling 30 araw, walang PSID) ay kinakabitan ng PSID mula sa mga available na Botcake customer ng page. */
class BotcakeCustomerMatchTest extends BotcakeApiTestCase
{
    private function available(string $psid, string $name, array $phones = [], string $page = 'Page Uno'): void
    {
        BotcakePsid::create([
            'page' => $page, 'fb_name' => $name, 'psid' => $psid, 'status' => 'available', 'source' => 'api',
            'phone_numbers' => $phones,
        ]);
    }

    public function test_orders_get_a_psid_only_when_exactly_one_customer_fits(): void
    {
        $this->available('psid-a', 'Ana  Cruz');
        $this->available('psid-b1', 'Ben Reyes');
        $this->available('psid-b2', 'ben reyes');
        $this->available('psid-c', 'Carla S.', ['+63 917 111 2222']);
        $this->available('psid-x', 'Ana Cruz', [], 'Other Page'); // ibang page: hindi kandidato
        // Isang sheet record ng parehong pangalan ay hindi kandidato (available na API rows lang).
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Ana Cruz', 'psid' => 'psid-sheet', 'status' => 'matched']);

        $name     = $this->order(' page uno ', ' ana cruz ');
        $twins    = $this->order('Page Uno', 'Ben Reyes', '09990000000');
        $byPhone  = $this->order('Page Uno', 'Carla Santos', '0917-111-2222');
        $none     = $this->order('Page Uno', 'Dino Lim', '09170000000');
        $hasPsid  = $this->order('Page Uno', 'Ana Cruz', null, 'kept-psid');
        $old      = $this->order('Page Uno', 'Ana Cruz', null, null, now('Asia/Manila')->subDays(31)->toDateString());

        $counts = app(BotcakeCustomerMatcher::class)->matchPage('Page Uno');

        $this->assertSame('psid-a', $name->fresh()->botcake_psid, 'iisang psid sa pangalan (trim, espasyo, case)');
        $this->assertNull($twins->fresh()->botcake_psid, 'dalawang psid sa parehong pangalan: walang isusulat');
        $this->assertSame('psid-c', $byPhone->fresh()->botcake_psid, 'phone kapag hindi tumugma ang pangalan');
        $this->assertNull($none->fresh()->botcake_psid);
        $this->assertSame('kept-psid', $hasPsid->fresh()->botcake_psid, 'hindi pinapalitan ang may laman');
        $this->assertNull($old->fresh()->botcake_psid, 'lampas 30 araw: hindi ginagalaw');

        $this->assertSame(['by_name' => 1, 'by_phone' => 1, 'ambiguous' => 1, 'no_match' => 1], array_intersect_key($counts, array_flip(['by_name', 'by_phone', 'ambiguous', 'no_match'])));
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

    public function test_the_preview_lists_unmatched_orders_and_writes_nothing(): void
    {
        $this->available('psid-b1', 'Ben Reyes');
        $this->available('psid-b2', 'Ben Reyes');
        $this->available('psid-a', 'Ana Cruz');
        $twins = $this->order('Page Uno', 'Ben Reyes');
        $ana   = $this->order('Page Uno', 'Ana Cruz');
        $this->order('Page Uno', 'Dino Lim');

        $res = app(BotcakeCustomerMatcher::class)->matchPage('Page Uno', false);

        $this->assertNull($ana->fresh()->botcake_psid);
        $this->assertSame([['Ben Reyes', 'ambiguous'], ['Dino Lim', 'no_match']], array_map(fn ($r) => [$r['fb_name'], $r['reason']], $res['unmatched']));
        $this->assertSame($twins->id, $res['unmatched'][0]['id']);
    }
}
