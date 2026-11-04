<?php

namespace Tests\Feature\NightRun;

use App\Models\MacroOutput;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ang Order Summary (/encoder/summary) ay pumipili ng order ayon sa ts_date (may index), hindi sa TIMESTAMP LIKE.
 * Pareho ang bilang: ang order sa piniling araw lang, may PAGE lang; walang petsa = wala sa summary.
 */
class OrderSummaryByDateTest extends NightRunTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('downloaded_macro_output_logs', function (Blueprint $t) {
            $t->id();
            $t->string('timestamp')->nullable();
            $t->string('page')->nullable();
            $t->string('downloaded_by')->nullable();
            $t->timestamp('downloaded_at')->nullable();
            $t->timestamps();
        });
        // Binabasa ng layout ng page (bilang ng pending task ng user).
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('from_jnts', function (Blueprint $t) {
            $t->id();
            $t->string('waybill_number')->nullable();
            $t->timestamps();
        });
    }

    private function row(string $timestamp, ?string $tsDate, ?string $page, ?string $status): void
    {
        MacroOutput::create(['TIMESTAMP' => $timestamp, 'ts_date' => $tsDate, 'PAGE' => $page, 'STATUS' => $status]);
    }

    public function test_counts_only_orders_of_the_chosen_days_with_a_page(): void
    {
        $this->row('09:15 03-11-2026', '2026-11-03', 'Likha Shop', 'PROCEED');
        $this->row('10:20 03-11-2026', '2026-11-03', 'Likha Shop', 'CANNOT PROCEED');
        $this->row('23:59 03-11-2026', '2026-11-03', 'Other Page', null);
        $this->row('00:01 04-11-2026', '2026-11-04', 'Likha Shop', 'PROCEED');   // ibang araw
        $this->row('11:00 03-11-2026', '2026-11-03', null, 'PROCEED');           // walang PAGE
        $this->row('', null, 'Likha Shop', 'PROCEED');                           // walang petsa

        $response = $this->actingAs($this->user())->get('/encoder/summary?start_date=2026-11-03&end_date=2026-11-03');

        $response->assertOk();
        $summary = $response->viewData('summary');
        $totals = $response->viewData('totalCounts');
        $this->assertSame(['2026-11-03'], array_keys($summary));
        $this->assertSame(2, $summary['2026-11-03']['Likha Shop']['TOTAL']);
        $this->assertSame(1, $summary['2026-11-03']['Likha Shop']['PROCEED']);
        $this->assertSame(1, $summary['2026-11-03']['Other Page']['BLANK']);
        $this->assertSame(3, $totals['TOTAL']);
    }

    public function test_a_range_spans_each_day(): void
    {
        $this->row('09:15 03-11-2026', '2026-11-03', 'Likha Shop', 'PROCEED');
        $this->row('00:01 04-11-2026', '2026-11-04', 'Likha Shop', 'PROCEED');
        $this->row('00:01 05-11-2026', '2026-11-05', 'Likha Shop', 'PROCEED');

        $response = $this->actingAs($this->user())->get('/encoder/summary?start_date=2026-11-03&end_date=2026-11-04');

        $response->assertOk();
        $this->assertEqualsCanonicalizing(['2026-11-03', '2026-11-04'], array_keys($response->viewData('summary')));
        $this->assertSame(2, $response->viewData('totalCounts')['TOTAL']);
    }
}
