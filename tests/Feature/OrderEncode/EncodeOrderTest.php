<?php

namespace Tests\Feature\OrderEncode;

use App\Jobs\EncodeOrder;
use App\Models\AppSetting;
use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Services\OrderEncode\LiveOrder;
use Illuminate\Support\Facades\Http;

/** Ang job EncodeOrder: shadow sumusulat sa order_encodes lang; live gumagawa rin ng macro_output row. Fake ang OpenAI. */
class EncodeOrderTest extends OrderEncodeTestCase
{
    private function makeOrder(string $mode = 'shadow', ?array $row = null, int $number = 23823): OrderEncode
    {
        return OrderEncode::create([
            'page' => self::PAGE, 'psid' => 7001, 'chat_id' => 13, 'flow_id' => 5, 'spreadsheet_id' => self::ORDERS, 'tab' => 'All Orders',
            'row_number' => $mode === 'live' ? null : $number, 'mode' => $mode, 'status' => 'queued',
            'input' => ['row' => array_map(fn ($v) => "'" . $v, $row ?? $this->orderRow())],
        ]);
    }

    private function runJob(OrderEncode $o): OrderEncode
    {
        (new EncodeOrder($o->id))->handle(app(\App\Services\OrderEncode\Runner::class));

        return $o->fresh();
    }

    private function openAi(string $address = 'Blk 5 Lot 3 Lahug'): void
    {
        Http::fake(['api.openai.com/v1/chat/completions' => Http::response(Fixtures::chat($address, 120, 8))]);
    }

    public function test_S_130_9_a_shadow_job_writes_the_encode_and_never_macro_output(): void
    {
        $this->configurePage('shadow');
        $this->openAi();

        $o = $this->runJob($this->makeOrder());

        $this->assertSame('done', $o->status);
        $this->assertSame(['Ana Cruz', '09171234567', 'Blk 5 Lot 3 Lahug', 'CEBU', 'CEBU-CITY', 'LAHUG', '2 x GLOW SERUM', '998'],
            array_map(fn ($k) => $o->outputs[$k], ['name', 'phone', 'address_line', 'province', 'city', 'barangay', 'item', 'cod']));
        $this->assertSame(['', '✅', '✅', '✅', '', '✅', true], array_map(fn ($k) => $o->outputs[$k], ['remark', 'icon', 'r', 's', 'w', 'final', 'proceed']));
        $this->assertSame(0, MacroOutput::count());
        $this->assertNull($o->macro_output_id);
        $this->assertSame(['gpt-4o-mini' => ['requests' => 1, 'tokens_in' => 120, 'tokens_out' => 8]], $o->usage);
        $this->assertSame('WROTE_ADDRESS_LINE1', collect($o->steps)->firstWhere('step', 'address_line1')['status']);
        $this->assertSame('Ana Cruz', $o->input['fields']['name']);
    }

    public function test_S_130_10_the_api_key_never_appears_in_the_record_and_goes_only_in_the_request(): void
    {
        $this->configurePage('shadow');
        Http::fake(['*' => Http::response('{"error":{"message":"Incorrect API key provided: ' . Fixtures::KEY . '. Also sk-live-ABCDEFGH12345."}}', 401)]);

        $o = $this->runJob($this->makeOrder());

        $this->assertSame('done', $o->status);
        $this->assertStringNotContainsString(Fixtures::KEY, json_encode($o->toArray()));
        $this->assertStringNotContainsString('sk-live-ABCDEFGH12345', json_encode($o->toArray()));
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer ' . Fixtures::KEY));
    }

    public function test_S_130_11_a_gpt_http_error_is_recorded_as_the_script_records_it_and_is_not_retried(): void
    {
        $this->configurePage('shadow');
        Http::fake(['*' => Http::response('{"error":{"message":"rate limit"}}', 429)]);

        $o = $this->runJob($this->makeOrder());
        $steps = collect($o->steps)->keyBy('step');

        $this->assertSame('done', $o->status);
        $this->assertSame('EMPTY_RESPONSE', $steps['address_line1']['status']); // 429 -> "" gaya ng script
        $this->assertStringContainsString('rate limit', $steps['address_line1']['raw']);
        $this->assertSame('', $o->outputs['address_line']);
        // walang address line kaya tinanong ang GPT ng pangalan/address: 429 din, ERR gaya ng script
        $this->assertStringStartsWith('ERR_NAMEADDR:OpenAI API error: 429', $steps['name_addr']['status']);
        $this->assertCount(0, MacroOutput::all());
    }

    public function test_S_130_12_a_missing_location_list_fails_the_row_without_a_retry_and_without_gpt(): void
    {
        $this->configurePage('shadow', lists: false);
        Http::fake();

        $o = $this->runJob($this->makeOrder());

        $this->assertSame('failed', $o->status);
        $this->assertStringContainsString('order-encode:sync-locations', $o->error);
        Http::assertNothingSent();
    }

    public function test_S_130_13_a_done_row_is_not_run_again(): void
    {
        $this->configurePage('shadow');
        $this->openAi();
        $o = $this->runJob($this->makeOrder());
        $at = $o->encoded_at;

        $this->runJob($o);

        Http::assertSentCount(1);
        $this->assertEquals($at, $o->fresh()->encoded_at);
    }

    public function test_S_130_14_the_row_is_read_by_the_header_names_of_the_tab(): void
    {
        $this->configurePage('shadow');
        $this->openAi();
        $this->google->headerRow = ['Row', 'Date', 'Page', 'Name', 'Phone Number', 'All User Input', 'Shop Details', 'Extracted Details', 'Price'];

        $o = $this->runJob($this->makeOrder('shadow', array_merge(['23823'], $this->orderRow())));

        $this->assertSame(['Page Uno', 'Ana Cruz', '2 x GLOW SERUM', 'CEBU'], [$o->input['fields']['page'], $o->outputs['name'], $o->outputs['item'], $o->outputs['province']]);
    }

    public function test_S_130_15_a_live_job_creates_the_macro_output_row_from_the_encode(): void
    {
        $this->configurePage('live');
        $this->openAi();

        $o = $this->runJob($this->makeOrder('live'));

        $m = MacroOutput::sole();
        $this->assertSame($m->id, $o->macro_output_id);
        $this->assertSame(['10:15 23-10-2026', 'Page Uno', 'Ana Cruz', 'Ana Cruz', '09171234567', '7001'],
            [$m->TIMESTAMP, $m->PAGE, $m->{'FULL NAME'}, $m->fb_name, $m->{'PHONE NUMBER'}, $m->botcake_psid]);
        $this->assertSame(['Blk 5 Lot 3 Lahug', 'CEBU', 'CEBU-CITY', 'LAHUG', '2 x GLOW SERUM', '998'],
            [$m->ADDRESS, $m->PROVINCE, $m->CITY, $m->BARANGAY, $m->ITEM_NAME, $m->COD]);
        $this->assertSame(['✅', 'PROCEED', null], [$m->{'APP SCRIPT CHECKER'}, $m->STATUS, $m->{'AI ANALYZE'}]);
        $this->assertStringStartsWith("FB NAME: Ana Cruz\nPHONE NUMBER: 09171234567\n", $m->all_user_input);
        $this->assertSame($this->orderRow()[5], $m->shop_details);
        $this->assertSame($this->orderRow()[6], $m->CXD);
    }

    public function test_S_130_16_a_live_order_is_never_inserted_twice(): void
    {
        $this->configurePage('live');
        $this->openAi();

        $first = $this->runJob($this->makeOrder('live'));
        $second = $this->runJob($this->makeOrder('live'));

        $this->assertSame(1, MacroOutput::count());
        $this->assertSame($first->macro_output_id, $second->macro_output_id);
    }

    public function test_S_130_17_live_keeps_the_chat_name_and_phone_and_blanks_status_when_not_a_check(): void
    {
        $o = OrderEncode::create([
            'page' => self::PAGE, 'psid' => 0, 'spreadsheet_id' => self::ORDERS, 'tab' => 'All Orders', 'mode' => 'live', 'status' => 'done',
            'input' => ['fields' => ['date' => '2026-10-23 09:05:00', 'page' => 'Page Uno', 'name' => '', 'phone' => '', 'input' => 'call me at 0917 111 2222', 'shop' => '', 'cxd' => '', 'price' => '']],
            'outputs' => ['name' => 'Ben', 'phone' => '09990001111', 'address_line' => 'Street 1', 'province' => 'CEBU', 'city' => 'X', 'barangay' => 'Y',
                'item' => '', 'cod' => '', 'remark' => 'Province and City', 'final' => 'Barangay', 'proceed' => false],
        ]);

        $m = app(LiveOrder::class)->create($o);

        $this->assertSame(['09:05 23-10-2026', 'Ben', '09171112222', 'Province and City', 'Barangay', null], [$m->TIMESTAMP, $m->{'FULL NAME'}, $m->{'PHONE NUMBER'}, $m->{'AI ANALYZE'}, $m->{'APP SCRIPT CHECKER'}, $m->STATUS]);
    }

    public function test_S_130_18_the_models_come_from_app_settings(): void
    {
        $this->configurePage('shadow');
        AppSetting::set('order_encode_model_extract', 'gpt-4.1-nano');
        $this->openAi();

        $o = $this->runJob($this->makeOrder());

        Http::assertSent(fn ($r) => $r['model'] === 'gpt-4.1-nano' && $r['temperature'] === 0.2);
        $this->assertArrayHasKey('gpt-4.1-nano', $o->usage);
    }
}
