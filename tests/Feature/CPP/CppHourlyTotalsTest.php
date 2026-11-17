<?php

namespace Tests\Feature\CPP;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Chatblast\ChatblastTestCase;

/**
 * Spec 291: /ads_manager/cpp/timeline "Hourly totals" view. Rows = date, columns = hour 00–23 (Manila), each cell the
 * day's cumulative total Adspent, Orders and CPP: the Hourly view's Total row for that date, from the same code.
 */
class CppHourlyTotalsTest extends ChatblastTestCase
{
    private const DAY  = '2026-11-09';
    private const PREV = '2026-11-08';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('macro_output', function (Blueprint $t) {
            $t->string('TIMESTAMP')->nullable();
            $t->string('STATUS')->nullable();
        });
        Schema::create('ads_manager_reports', function (Blueprint $t) {
            $t->id();
            $t->date('day')->nullable();
            $t->string('page_name');
            $t->string('ad_id')->nullable();
            $t->decimal('amount_spent_php', 12, 2)->nullable();
            $t->timestamps();
        });
        Schema::create('upload_logs', function (Blueprint $t) {
            $t->id();
            $t->string('type');
            $t->timestamps();
        });
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_05_13_120000_create_cpp_snapshots_table.php']]);
        $this->travelTo(Carbon::parse('2026-11-10 09:00:00', 'Asia/Manila'));
        $this->actingAs($this->user('Marketing', 'mk@example.test'));
    }

    private function hourRow(string $page, int $h, float $spend, ?float $cpi = null, ?float $cpm = null, string $day = self::DAY, string $at = '23:10:00'): void
    {
        DB::table('cpp_snapshots')->insert([
            'snapshot_date' => $day, 'snapshot_bucket' => sprintf('H%02d', $h), 'snapshot_at' => $day . ' ' . $at,
            'page_name' => $page, 'page_key' => strtolower(str_replace(' ', '', $page)), 'amount_spent' => $spend,
            'orders' => 0, 'proceed_orders' => 0, 'cpi' => $cpi, 'cpm' => $cpm, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function sale(string $page, string $hm, string $day = self::DAY, ?string $ts = null): void
    {
        DB::table('macro_output')->insert(['PAGE' => $page, 'TIMESTAMP' => $ts ?? ($hm . ' ' . Carbon::parse($day)->format('d-m-Y')),
            'ts_date' => $day, 'STATUS' => 'PROCEED', 'ITEM_NAME' => 'Tripod', 'COD' => '499']);
    }

    private function spendInReport(string $page, string $day = self::DAY, float $amount = 500): void
    {
        DB::table('ads_manager_reports')->insert(['day' => $day, 'page_name' => $page, 'ad_id' => 'ad-' . uniqid('', true), 'amount_spent_php' => $amount, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Two days with the cases the rules care about: spend carried over an hour without a row, a page with hourly spend
     * but no report spend (Unknown ad), a page spelled differently in each table, an orders-only page, an organic page,
     * a page whose report spend is 0, and order times the parser must skip.
     */
    private function seedTwoDays(): void
    {
        $this->spendInReport('Page P1');
        $this->spendInReport('Page P2');
        $this->spendInReport('Page  Q');
        $this->spendInReport('Zero Page', self::DAY, 0);
        $this->hourRow('Page P1', 9, 100, 100, 20);
        $this->hourRow('Page P1', 10, 180, 100, 22.5);
        $this->hourRow('Page P1', 12, 250, 125, 25);            // no H11 row: 11 carries 10's figures
        for ($h = 13; $h < 24; $h++) $this->hourRow('Page P1', $h, 260, 130, 26);
        $this->hourRow('Unknown ad', 10, 30, 50, null, self::DAY, '23:12:00');
        $this->hourRow('page q', 8, 40, 80, 10);
        $this->sale('Page P1', '7:30');                          // one-digit hour
        $this->sale('Page P1', '09:15');
        $this->sale('Page P1', '09:40');
        $this->sale('PAGE P1', '10:05');                         // other spelling, same page
        $this->sale('Page P2', '11:30');
        $this->sale('Page P2', '', self::DAY, ' 13:00 ' . Carbon::parse(self::DAY)->format('d-m-Y') . ' ');
        $this->sale('PageQ', '08:20');
        $this->sale('Organic Page', '09:00');                    // no ad spend: not counted
        $this->sale('Zero Page', '09:00');                       // report spend 0: not counted
        $this->sale('Page P1', '', self::DAY, '25:00 09-11-2026'); // no such hour
        $this->sale('Page P1', '', self::DAY, '10:00 08-11-2026'); // ts_date and TIMESTAMP disagree
        $this->sale('Page P1', '', self::DAY, 'garbage');

        $this->spendInReport('Page P1', self::PREV);
        $this->hourRow('Page P1', 0, 10, 100, 5, self::PREV);
        $this->hourRow('Page P1', 23, 900, 110, 30, self::PREV);
        $this->sale('Page P1', '00:10', self::PREV);
        $this->sale('Page P1', '22:59', self::PREV);
    }

    /**
     * The Hourly endpoint's output did not change when its rules moved to shared code: the fixtures were recorded from
     * the code before spec 291 on this same data (a past day with every case, the day before, an empty day, and the
     * first day as "today" at 10:20).
     */
    public function test_the_hourly_endpoint_output_is_byte_for_byte_unchanged(): void
    {
        $this->seedTwoDays();
        foreach ([self::DAY, self::PREV, '2026-11-07'] as $d) {
            $this->assertSame(file_get_contents(__DIR__ . "/fixtures/291-hourly-golden-$d.json"),
                $this->get('/ads_manager/cpp/timeline/hourly?date=' . $d)->assertOk()->getContent(), $d);
        }
        $this->travelTo(Carbon::parse(self::DAY . ' 10:20:00', 'Asia/Manila'));
        $this->assertSame(file_get_contents(__DIR__ . '/fixtures/291-hourly-golden-today.json'),
            $this->get('/ads_manager/cpp/timeline/hourly?date=' . self::DAY)->assertOk()->getContent(), 'today');
    }
}
