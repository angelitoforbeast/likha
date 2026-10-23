<?php

namespace Tests\Feature\OrderEncode;

use App\Jobs\EncodeOrder;
use App\Jobs\ImportLikhaFromGoogleSheet;
use App\Models\AppSetting;
use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Services\OrderEncode\ImportShadow;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/** Ang Likha import ay gumagawa ng shadow encode sa bawat bagong macro_output row (dispatch lang, walang GPT sa import). */
class ImportShadowTest extends OrderEncodeTestCase
{
    private ImportLikhaFromGoogleSheet $job;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->job = new ImportLikhaFromGoogleSheet(1);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Ang raw na A..I ng TO WEBSITE: oras, page, FB name, phone, lahat ng input, shop details, extracted, presyo, DONE marker. */
    private function raw(string $name = 'Ana Cruz', string $date = '10:15 23-10-2026', string $page = self::PAGE): array
    {
        return [$date, $page, $name, '09171234567', 'Hi po, order ko 2 pcs', "ITEM: GLOW SERUM\nQUANTITY: 2\nPRICE: ₱998", "- Province: Cebu\n- City: Cebu City", '998', ''];
    }

    private function import(array $row, int $sheetRow = 12, ?ImportLikhaFromGoogleSheet $job = null): string
    {
        $m = new \ReflectionMethod(ImportLikhaFromGoogleSheet::class, 'importRow');
        $m->setAccessible(true);

        return $m->invoke($job ?? $this->job, $row, $sheetRow, self::ORDERS);
    }

    private function on(array $more = []): void
    {
        foreach (['order_encode_import_shadow' => 'on'] + $more as $k => $v) AppSetting::set($k, $v);
    }

    public function test_S_133_1_switch_off_the_import_behaves_as_before_and_encodes_nothing(): void
    {
        $this->assertSame('inserted', $this->import($this->raw()));

        $this->assertSame(1, MacroOutput::count());
        $this->assertSame(0, OrderEncode::count());
        Queue::assertNothingPushed();

        AppSetting::set('order_encode_import_shadow', 'off');
        $this->import($this->raw('Ben Reyes'));
        $this->assertSame(0, OrderEncode::count());
    }

    public function test_S_133_2_switch_on_a_created_order_gets_one_encode_with_the_raw_cells_and_the_row_key(): void
    {
        $this->on();
        $raw = $this->raw();
        $raw[4] = "  Hi po\nline two  "; // raw: walang trim, walang apostrophe

        $this->assertSame('inserted', $this->import($raw, 41));

        $macro = MacroOutput::first();
        $o = OrderEncode::sole();
        $this->assertSame(['import', 'queued', self::ORDERS, 'All Orders', 41, self::PAGE, $macro->id], [$o->mode, $o->status, $o->spreadsheet_id, $o->tab, $o->row_number, $o->page, $o->macro_output_id]);
        $this->assertSame(array_slice($raw, 0, 8), $o->input['row']);
        $this->assertSame('import', $o->input['source']);
        Queue::assertPushedOn('encode', EncodeOrder::class, fn ($j) => $j->orderEncodeId === $o->id);
        Queue::assertPushed(EncodeOrder::class, 1);
    }

    public function test_S_133_3_an_update_of_an_existing_order_encodes_nothing(): void
    {
        $this->import($this->raw());
        $this->on();

        $this->assertSame('updated', $this->import($this->raw(), 12, new ImportLikhaFromGoogleSheet(1)));

        $this->assertSame(0, OrderEncode::count());
        Queue::assertNothingPushed();
    }

    public function test_S_133_4_an_order_the_flow_hook_already_encoded_is_not_encoded_twice(): void
    {
        $this->on();
        $flow = OrderEncode::create([
            'page' => self::PAGE, 'psid' => 5, 'spreadsheet_id' => self::ORDERS, 'tab' => 'All Orders', 'row_number' => 41, 'mode' => 'shadow',
            'status' => 'done', 'input' => ['row' => []],
        ]);

        $this->import($this->raw(), 41);

        $this->assertSame(1, OrderEncode::count());
        $this->assertSame(MacroOutput::first()->id, $flow->fresh()->macro_output_id);
        $this->assertSame('shadow', $flow->fresh()->mode);
        Queue::assertNothingPushed();
    }

    public function test_S_133_5_the_daily_cap_and_the_skip_list_hold_back_orders_and_count_them(): void
    {
        $this->on(['order_encode_import_daily_cap' => '2', 'order_encode_import_skip_pages' => ' Page Dos , other ']);

        foreach (['A', 'B', 'C', 'D'] as $i => $n) $this->import($this->raw($n), 10 + $i);
        $this->import($this->raw('E', page: 'page dos'), 20);

        $this->assertSame(2, OrderEncode::where('mode', 'import')->count());
        $this->assertSame(5, MacroOutput::count()); // ang import mismo ay hindi naapektuhan
        Queue::assertPushed(EncodeOrder::class, 2);
        $skipped = fn () => array_intersect_key(app(ImportShadow::class)->skippedToday(), ['cap' => 1, 'pages' => 1]);
        $this->assertSame(['cap' => 2, 'pages' => 1], $skipped());

        // Ang bilang ay para sa araw na ito lang.
        Carbon::setTestNow(now()->addDay());
        $this->assertSame(['cap' => 0, 'pages' => 0], $skipped());
    }

    public function test_S_133_6_an_encode_problem_never_fails_the_import(): void
    {
        $this->on();
        $dispatcher = \Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->andThrow(new \RuntimeException('queue down'));
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $dispatcher);

        $this->assertSame('inserted', $this->import($this->raw()));
        $this->assertSame(1, MacroOutput::count());
        $this->assertSame('failed', OrderEncode::sole()->status); // hindi naiwang queued na hindi tatakbo

        $this->app->forgetInstance(\Illuminate\Contracts\Bus\Dispatcher::class);
        Schema::drop('order_encodes'); // wala pang migration
        $this->assertSame('inserted', $this->import($this->raw('Ben Reyes'), 13, new ImportLikhaFromGoogleSheet(1)));
        $this->assertSame(2, MacroOutput::count());
    }

    public function test_S_133_7_an_import_order_is_encoded_from_its_cells_without_a_sheet_read_and_never_written_to_macro_output(): void
    {
        $this->on();
        Http::fake(['api.openai.com/v1/chat/completions' => Http::response(Fixtures::chat('Blk 5 Lot 3 Lahug', 120, 8))]);
        $raw = $this->raw();
        $raw[6] = "- Name: Ana Cruz\n- Phone Number: 09171234567\n- Province: Cebu\n- City: Cebu City\n- Brgy: Lahug\n- Address: Blk 5 Lot 3 Lahug";
        $this->import($raw, 41);
        $made = MacroOutput::first()->getAttributes();
        $this->google->headerRow = ['x', 'y', 'z']; // kung nabasa ito, magugulo ang mapping

        (new EncodeOrder(OrderEncode::sole()->id))->handle(app(\App\Services\OrderEncode\Runner::class));

        $o = OrderEncode::sole();
        $this->assertSame('done', $o->status);
        $this->assertSame('Ana Cruz', $o->input['fields']['name']);
        $this->assertSame('CEBU', $o->outputs['province']);
        $this->assertSame($made, MacroOutput::first()->getAttributes());
        $this->assertSame(1, MacroOutput::count());
    }
}
