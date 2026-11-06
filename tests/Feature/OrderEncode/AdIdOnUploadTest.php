<?php

namespace Tests\Feature\OrderEncode;

use App\Models\BotcakePsid;
use App\Models\MacroOutput;
use App\Services\BotcakeCustomerMatcher;
use App\Services\Chatbot\FlowRun;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang {{ad_id}} at ang "Ad ID (I)" ng Upload to macro: ang ad ng customer (botcake_psids ng parehong page at PSID, ang
 * patakaran ng matcher) ay isinusulat kasabay ng order, at ang orders:fill-ad-id para sa mga order na nauna rito.
 */
class AdIdOnUploadTest extends UploadEncodeTestCase
{
    private const AD = '120250000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_16_400000_create_botcake_psids_table.php',
            'database/migrations/2026_10_17_100100_add_api_columns_to_botcake_psids.php',
            'database/migrations/2026_10_17_100300_add_ad_and_match_keys_to_botcake_psids.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_11_18_100000_add_page_id_to_botcake_psids.php',
        ]]);
        if (!Schema::hasColumn('macro_output', 'botcake_ad_id')) Schema::table('macro_output', fn (Blueprint $t) => $t->string('botcake_ad_id', 64)->nullable());
    }

    /** Isang botcake_psids row ng customer (ang record ng chat: chat_verify). */
    private function adRow(string $ad = self::AD, string $psid = '7001', string $page = self::PAGE, ?string $pageId = self::PAGE_ID, string $source = 'chat_verify'): BotcakePsid
    {
        return BotcakePsid::create(['page' => $page, 'page_id' => $pageId, 'psid' => $psid, 'source' => $source, 'status' => 'available', 'fb_name' => 'Ana Cruz', 'ad_id' => $ad]);
    }

    private function uploadedAd(): ?string
    {
        return MacroOutput::latest('id')->first()->botcake_ad_id;
    }

    /** @return array<string, array{0: string}> ang daan ng flow na gumagawa ng bagong macro_output row */
    public static function newRowPaths(): array
    {
        return ['Upload to macro (Insert)' => ['upload'], 'Encode order (legacy) and the live Sheet step' => ['encode']];
    }

    /** @dataProvider newRowPaths */
    public function test_S_239_1_1_a_new_row_gets_the_ad_of_the_customers_chat_verify_row(string $path): void
    {
        $this->adRow();

        if ($path === 'upload') $this->go($this->uploadGraph());
        else $this->liveOrder($this->details());

        $this->assertSame(1, MacroOutput::count());
        $this->assertSame(self::AD, $this->uploadedAd());
    }

    /** @return array<string, array{0: string, 1: string}> psid, page ng botcake_psids row */
    public static function otherCustomers(): array
    {
        return ['another psid' => ['7002', self::PAGE], 'another page' => [(string) self::PSID, 'Page Dos']];
    }

    /** @dataProvider otherCustomers */
    public function test_S_239_1_2_the_ad_of_another_psid_or_another_page_is_not_written(string $psid, string $page): void
    {
        $this->adRow(self::AD, $psid, $page, $page === self::PAGE ? self::PAGE_ID : '2002');

        $this->go($this->uploadGraph());

        $this->assertNull($this->uploadedAd());
    }

    public function test_S_239_1_3_a_row_of_another_page_id_is_not_used_and_a_row_without_page_id_matches_by_name(): void
    {
        $this->adRow('120250000000000009', (string) self::PSID, self::PAGE, '9999');
        $this->go($this->uploadGraph());
        $this->assertNull($this->uploadedAd(), 'ibang page_id, parehong pangalan: hindi ginagamit');

        $this->adRow(self::AD, (string) self::PSID, ' page uno ', null);
        $this->go($this->uploadGraph(), 'isa pa po', '2026-10-23 11:00');
        $this->assertSame(self::AD, $this->uploadedAd(), 'walang page_id: ayon sa pangalan');
    }

    /** @return array<string, array{0: ?string, 1: ?string, 2: bool}> ang Ad ID ng step (null = walang key), ang naisulat, may tala */
    public static function adFields(): array
    {
        return [
            'no key (existing step)' => [null, self::AD, false],
            'cleared' => ['', null, false],
            'ad id with a tail' => ['{{ad_id}}x', null, true],
            'not digits' => ['abc', null, true],
        ];
    }

    /** @dataProvider adFields */
    public function test_S_239_1_4_the_ad_id_field_decides_what_is_written(?string $field, ?string $written, bool $noted): void
    {
        $this->adRow();

        $run = $this->go($this->uploadGraph($field === null ? [] : ['ad_id' => $field]));

        $this->assertSame($written, $this->uploadedAd());
        $detail = $this->runEvents($run, 'upload_macro')[0]['detail'];
        $this->assertSame('inserted', $detail['result']);
        if ($noted) $this->assertStringContainsString('Ad ID not saved: not an ad id', \App\Services\Chatbot\ChatbotEvents::text('upload_macro', 'n2', $detail));
        else $this->assertArrayNotHasKey('ad_note', $detail);
    }

    /** @return array<string, array{0: ?string, 1: string}> ang ad id ng bukas na row, ang inaasahan pagkatapos ng Update */
    public static function openRows(): array
    {
        return ['has an ad id' => ['120250000000000777', '120250000000000777'], 'has none' => [null, self::AD]];
    }

    /** @dataProvider openRows */
    public function test_S_239_1_5_update_fills_the_ad_id_only_when_the_open_row_has_none(?string $before, string $after): void
    {
        $this->adRow();
        $row = $this->existing(['botcake_ad_id' => $before, 'all_user_input' => 'old order']);

        $this->go($this->uploadGraph(['mode' => 'update']), 'order po');

        $this->assertSame(1, MacroOutput::count());
        $this->assertSame($after, $row->fresh()->botcake_ad_id);
    }

    public function test_S_239_1_6_ad_id_interpolates_in_a_send_and_an_unmentioned_flow_reads_no_botcake_psids(): void
    {
        $this->adRow();
        $run = $this->go($this->graph(['n2' => ['send', ['items' => [['type' => 'text', 'value' => 'Ad: {{ad_id}}']]]], 'n3' => ['end']], [['n1', 'next', 'n2'], ['n2', 'continue', 'n3']]));
        $this->assertSame('Ad: ' . self::AD, $run->sends[0]['messages'][0]['value'] ?? $run->sends[0]['messages'][0]['text'] ?? json_encode($run->sends[0]['messages']));

        $reads = 0;
        DB::listen(function ($q) use (&$reads) {
            if (str_contains($q->sql, 'botcake_psids')) $reads++;
        });
        $plain = new FlowRun(5, $this->graph(['n2' => ['send', ['items' => [['type' => 'text', 'value' => 'Hi {{first_name}}']]]]], [['n1', 'next', 'n2']]),
            self::PAGE, self::PAGE_ID, [], $this->fields(), 13, 'hi', 'Ana Cruz', '2026-10-23 10:15');
        $plain->vars();
        $plain->text('Hi {{first_name}}');
        $this->assertSame(0, $reads);
    }

    public function test_S_239_1_7_fill_ad_id_dry_run_changes_nothing_and_a_real_run_fills_only_empty_ones(): void
    {
        $this->adRow();
        $this->adRow('120250000000000002', '7002');
        $empty = $this->existing(['ts_date' => '2026-11-06', 'TIMESTAMP' => '09:00 06-11-2026']);
        $kept = $this->existing(['ts_date' => '2026-11-06', 'botcake_psid' => '7002', 'botcake_ad_id' => '120250000000000555']);
        $noAd = $this->existing(['ts_date' => '2026-11-06', 'botcake_psid' => '7003']);
        $otherDay = $this->existing(['ts_date' => '2026-11-05']);

        Artisan::call('orders:fill-ad-id', ['--date' => '2026-11-06', '--dry-run' => true]);
        $this->assertStringContainsString('checked 2, filled 1, no ad found 1', Artisan::output());
        $this->assertSame([null, '120250000000000555', null, null], [$empty->fresh()->botcake_ad_id, $kept->fresh()->botcake_ad_id, $noAd->fresh()->botcake_ad_id, $otherDay->fresh()->botcake_ad_id]);

        Artisan::call('orders:fill-ad-id', ['--date' => '2026-11-06', '--page' => self::PAGE]);
        $this->assertStringContainsString('checked 2, filled 1, no ad found 1', Artisan::output());
        $this->assertSame([self::AD, '120250000000000555', null, null], [$empty->fresh()->botcake_ad_id, $kept->fresh()->botcake_ad_id, $noAd->fresh()->botcake_ad_id, $otherDay->fresh()->botcake_ad_id]);
    }

    public function test_S_239_1_8_the_nightly_matcher_keeps_the_uploaded_ad_id(): void
    {
        $this->adRow();
        $this->go($this->uploadGraph(), 'order po', now('Asia/Manila')->format('Y-m-d H:i'));
        $this->adRow('120250000000000999', (string) self::PSID, self::PAGE, self::PAGE_ID, 'api'); // mas bagong API row, ibang ad

        app(BotcakeCustomerMatcher::class)->matchPage(self::PAGE);

        $this->assertSame(self::AD, $this->uploadedAd());
    }
}
