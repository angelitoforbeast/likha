<?php

namespace Tests\Feature\OwnerPrivate;

use App\Http\Controllers\OwnerPrivateController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 261: whereDayIn (range sa column + ang dating DATE(col) BETWEEN) ay pareho ang tinatamaang row,
 * para sa date at datetime na column, sa mga hangganan ng range.
 */
class DayRangeFilterTest extends OwnerPrivateTestCase
{
    public function test_the_indexed_range_keeps_exactly_the_rows_of_date_between(): void
    {
        Schema::create('day_range_probe', function (Blueprint $t) {
            $t->id();
            $t->date('d')->nullable();
            $t->dateTime('t')->nullable();
        });
        foreach ([
            ['2026-09-30', '2026-09-30 23:59:59'], ['2026-10-01', '2026-10-01 00:00:00'], ['2026-10-05', '2026-10-05 12:30:00'],
            ['2026-10-09', '2026-10-09 23:59:59'], ['2026-10-10', '2026-10-10 00:00:00'], [null, null],
        ] as [$d, $t]) {
            DB::table('day_range_probe')->insert(['d' => $d, 't' => $t]);
        }

        $m = new \ReflectionMethod(OwnerPrivateController::class, 'whereDayIn');
        foreach (['d', 't'] as $col) {
            $old = DB::table('day_range_probe')->whereRaw("DATE($col) BETWEEN ? AND ?", ['2026-10-01', '2026-10-09'])->orderBy('id')->pluck('id')->all();
            $new = $m->invoke(null, DB::table('day_range_probe'), $col, "DATE($col)", '2026-10-01', '2026-10-09')->orderBy('id')->pluck('id')->all();
            $this->assertSame([2, 3, 4], $old, $col);
            $this->assertSame($old, $new, $col);
        }
    }
}
