<?php

namespace Tests\Unit;

use App\Services\ChecklistDueToday;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Effective deadline ng checklist task at kung kailan ito late. */
class ChecklistDeadlineTest extends TestCase
{
    /** [scheduled_time, deadline_time, deadline (minuto mula hatinggabi ng araw ng task) o null] */
    public static function deadlines(): array
    {
        return [
            'S-051.5 scheduled 10:00, walang deadline' => ['10:00', null, 10 * 60 + 30],
            'S-051.5 scheduled may segundo'           => ['10:00:00', null, 10 * 60 + 30],
            'S-051.6 deadline 10:45'                  => ['10:00', '10:45', 10 * 60 + 45],
            'S-051.6 deadline lang, walang oras'      => [null, '10:45', 10 * 60 + 45],
            'S-051.7 wala pareho'                     => [null, null, null],
            'S-051.7 blangko pareho'                  => ['', '', null],
            'S-051.8 11:50 PM → 12:20 AM kinabukasan' => ['23:50', null, 24 * 60 + 20],
            'hindi oras ang scheduled'                => ['umaga', null, null],
            'hindi oras ang deadline: scheduled + 30' => ['10:00', '25:00', 10 * 60 + 30],
        ];
    }

    #[DataProvider('deadlines')]
    public function test_the_effective_deadline_is_the_field_else_scheduled_plus_30_minutes(?string $scheduled, ?string $deadline, ?int $expected): void
    {
        $this->assertSame($expected, ChecklistDueToday::deadlineMinutes($scheduled, $deadline));
    }

    /** [ngayon (Manila), deadline, late?] para sa task ng 2026-10-16 */
    public static function moments(): array
    {
        return [
            'S-051.9 10:16, deadline 10:15'          => ['2026-10-16 10:16:00', 10 * 60 + 15, true],
            'S-051.10 10:14, deadline 10:15'         => ['2026-10-16 10:14:00', 10 * 60 + 15, false],
            'eksaktong 10:15:00 ay hindi pa late'    => ['2026-10-16 10:15:00', 10 * 60 + 15, false],
            'S-051.8 23:59, deadline 00:05 bukas'    => ['2026-10-16 23:59:59', 24 * 60 + 5, false],
            'walang deadline, gabi na'               => ['2026-10-16 23:59:00', null, false],
            'nakaraang araw: lampas na lahat'        => ['2026-10-17 08:00:00', 23 * 60 + 59, true],
            'nakaraang araw: kinabukasan na deadline' => ['2026-10-17 08:00:00', 24 * 60 + 5, false],
            'darating na araw: hindi pa'             => ['2026-10-15 23:00:00', 60, false],
        ];
    }

    #[DataProvider('moments')]
    public function test_a_task_is_late_once_now_is_past_its_deadline_on_the_tasks_day(string $now, ?int $deadline, bool $late): void
    {
        $day = Carbon::parse('2026-10-16', 'Asia/Manila');

        $this->assertSame($late, ChecklistDueToday::isLate($deadline, $day, Carbon::parse($now, 'Asia/Manila')));
    }

    /** Ang 4PM report: naka-schedule 15:45, walang sariling deadline → late mula 16:15:01. */
    public function test_S_079_a_task_scheduled_at_1545_is_late_only_after_1615(): void
    {
        $day = Carbon::parse('2026-10-16', 'Asia/Manila');
        $deadline = ChecklistDueToday::deadlineMinutes('15:45', null);

        $this->assertSame('16:15', ChecklistDueToday::hhmm($deadline));
        foreach (['2026-10-16 16:00:00' => false, '2026-10-16 16:10:00' => false, '2026-10-16 16:15:00' => false, '2026-10-16 16:15:01' => true] as $now => $late) {
            $this->assertSame($late, ChecklistDueToday::isLate($deadline, $day, Carbon::parse($now, 'Asia/Manila')), $now);
        }
    }
}
