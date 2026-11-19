<?php

namespace Tests\Feature\Botcake;

use App\Models\BotcakePsid;
use App\Models\BotcakePsidSetting;
use App\Services\BotcakeCustomerMatcher;
use App\Services\BotcakePsidMatcher;

/**
 * Spec 307 FU-3: walang PSID na ikinakabit o kinokopya ayon sa placeholder na pangalan ("Facebook User"), sa sheet
 * (eksakto at case-insensitive), sa reorder copy at sa Botcake customer match. Ang tunay na pangalan ay gaya ng dati.
 */
class PlaceholderPsidMatchTest extends BotcakeApiTestCase
{
    private function setting(): BotcakePsidSetting
    {
        return BotcakePsidSetting::create(['gsheet_name' => 'T', 'sheet_url' => 'https://docs.google.com/spreadsheets/d/T1/edit', 'sheet_range' => 'PSID!A:J']);
    }

    private function available(string $psid, string $name, array $phones = []): BotcakePsid
    {
        return BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => $name, 'psid' => $psid, 'status' => 'available', 'source' => 'api', 'phone_numbers' => $phones]);
    }

    public function test_FU_3_a_sheet_psid_with_a_placeholder_name_is_never_attached_by_name(): void
    {
        $exact = $this->order('Page Uno', 'Facebook User');
        $ci = $this->order('Page Uno', 'facebook user');
        $m = app(BotcakePsidMatcher::class);

        $status = $m->recordSheetRow($this->setting(), 'Page Uno', 'Facebook User', 'psid-fu', '18:00 18-11-2026');

        $this->assertSame(BotcakePsidMatcher::CONFLICT, $status);
        $this->assertNull($exact->fresh()->botcake_psid);
        $this->assertNull($ci->fresh()->botcake_psid);
        $this->assertNull($m->orderQuery('Page Uno', 'Facebook User'));
        $this->assertNull($m->orderQuery('Page Uno', 'Messenger  User'));
    }

    public function test_FU_3_a_waiting_placeholder_record_is_not_attached_on_retry(): void
    {
        $setting = $this->setting();
        BotcakePsid::create(['setting_id' => $setting->id, 'page' => 'Page Uno', 'fb_name' => 'Facebook User', 'psid' => 'psid-fu', 'sheet_date' => 'x',
            'status' => BotcakePsidMatcher::WAITING, 'first_seen_at' => now(), 'source' => 'sheet']);
        $order = $this->order('Page Uno', 'Facebook User');

        $counts = app(BotcakePsidMatcher::class)->retryWaiting();

        $this->assertNull($order->fresh()->botcake_psid);
        $this->assertSame(0, $counts['matched']);
        $this->assertSame(BotcakePsidMatcher::CONFLICT, BotcakePsid::sole()->status);
    }

    public function test_FU_3_N_a_real_name_sheet_psid_is_still_attached(): void
    {
        $order = $this->order('Page Uno', 'juan test');

        $status = app(BotcakePsidMatcher::class)->recordSheetRow($this->setting(), 'Page Uno', 'Juan Test', 'psid-j', '18:00 18-11-2026');

        $this->assertSame(BotcakePsidMatcher::MATCHED, $status);
        $this->assertSame('psid-j', $order->fresh()->botcake_psid);
    }

    public function test_FU_3_a_reorder_never_copies_a_psid_by_a_placeholder_name_and_a_real_name_still_does(): void
    {
        $this->order('Page Uno', 'Facebook User', null, 'psid-mercedita');
        $other = $this->order('Page Uno', 'Facebook User');
        $this->order('Page Uno', 'Juan Test', null, 'psid-j', now()->subDays(5)->toDateString());
        $reorder = $this->order('Page Uno', 'Juan Test');

        $copied = app(BotcakePsidMatcher::class)->copyReorders();

        $this->assertNull($other->fresh()->botcake_psid);
        $this->assertSame('psid-j', $reorder->fresh()->botcake_psid);
        $this->assertSame(1, $copied);
    }

    public function test_FU_3_the_botcake_customer_match_never_uses_a_placeholder_name_and_falls_to_the_phone(): void
    {
        $this->available('psid-mercedita', 'Facebook User');
        $this->available('psid-own', 'Facebook User', ['09913485181']);
        $this->available('psid-ana', 'Ana Cruz');
        $noPhone = $this->order('Page Uno', 'Facebook User');
        $byPhone = $this->order('Page Uno', 'facebook user', '639913485181');
        $real = $this->order('Page Uno', 'Ana Cruz');

        $counts = app(BotcakeCustomerMatcher::class)->matchPage('Page Uno');

        $this->assertNull($noPhone->fresh()->botcake_psid, 'walang phone: walang ikakabit');
        $this->assertSame('psid-own', $byPhone->fresh()->botcake_psid, 'ang phone ang susi, gaya ng walang pangalan');
        $this->assertSame('psid-ana', $real->fresh()->botcake_psid, 'ang tunay na pangalan: gaya ng dati');
        $this->assertSame(['by_name' => 1, 'by_phone' => 1, 'no_match' => 1], array_intersect_key($counts, array_flip(['by_name', 'by_phone', 'no_match'])));
    }

    public function test_FU_3_a_single_facebook_user_customer_is_not_attached_to_a_facebook_user_order_by_name(): void
    {
        $this->available('psid-mercedita', 'Facebook User');
        $order = $this->order('Page Uno', 'Facebook User');

        app(BotcakeCustomerMatcher::class)->matchPage('Page Uno');

        $this->assertNull($order->fresh()->botcake_psid);
    }
}
