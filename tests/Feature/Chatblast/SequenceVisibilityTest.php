<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\ResponseSpeed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Spec 215, ang makikita: /chatbot/speed Sequences (avg, p90, max kada oras) na mula sa padala na sinagot ng API, ang
 * read-only na chatbot:sequence-lateness, at ang ran ng step na may ilang padala.
 */
class SequenceVisibilityTest extends SequenceHandoffTestCase
{
    private function speed(): array
    {
        Cache::flush();

        return app(ResponseSpeed::class)->sequencesOutput(['date' => '2026-11-02'])['sequences'];
    }

    /** Hand the step off at 10:00 and let the API answer at $answerAt. */
    private function sendAt(string $psid, string $answerAt): void
    {
        $this->roundsUntil(count($this->calls) + 1);
        $this->travelTo($answerAt);
        $this->pool->beat();
        $this->replyPool->beat();
        $this->answerAll();
        $this->resumes();
    }

    public function test_S_215f_1_and_f_3_speed_shows_avg_p90_and_max_per_hour_measured_to_the_answered_send_not_the_hand_off(): void
    {
        $seq = $this->sequence('Late', [$this->stepFlow(['Hi'])->id]);
        $this->join($seq, '7001');
        $this->join($seq, '8001', '1002');
        $this->steps(); // handed off at 10:00:00
        $this->sendAt('7001', '2026-11-02 10:05:00');
        $this->travelTo('2026-11-02 10:30:00');

        $h = $this->speed()['hours'][10];
        $this->assertSame([2, 2], [$h['due'], $h['sent']]);
        $this->assertSame(['n' => 2, 'avg' => 300.0, 'p90' => 300, 'max' => 300], $h['scheduled_to_pushed'], 'due 10:00 → answered 10:05, not → hand-off');
        $view = file_get_contents(resource_path('views/chatbot/partials/speed-seq-cells.blade.php'));
        $this->assertStringContainsString("['avg', 'p90', 'max']", $view, 'the hourly avg, p90 and max are on the screen');
    }

    public function test_S_215f_2_and_f_4_the_lateness_command_shows_each_hour_and_leaves_empty_hours_blank(): void
    {
        $seq = $this->sequence('Cmd', [$this->stepFlow(['Hi'])->id]);
        $this->join($seq, '7001');
        $this->join($seq, '7002');
        $this->steps();
        $this->sendAt('7001', '2026-11-02 10:02:00'); // one answered; the other still waits for the page's slot in the pool
        $this->join($seq, '7003'); // due 10:02, not handed off yet
        $this->travelTo('2026-11-02 11:10:00');

        $this->assertSame(0, Artisan::call('chatbot:sequence-lateness', ['--date' => '2026-11-02']));
        $out = Artisan::output();
        $line = collect(explode("\n", $out))->first(fn ($l) => str_contains($l, '10:00'));
        $cells = array_map('trim', array_slice(explode('|', $line), 1, -1));
        $this->assertSame('10:00', $cells[0]);
        $this->assertSame(['1', '2'], [$cells[1], $cells[2]], 'sent; due but not sent (one still in the pool, one not handed off)');
        $this->assertSame('4200 s', $cells[3], 'oldest overdue: due 10:00, now 11:10');
        $this->assertSame(['120 s', '120 s'], [$cells[4], $cells[5]]);
        $this->assertSame('1', $cells[7], 'one send waiting in the pool');
        $empty = collect(explode("\n", $out))->first(fn ($l) => str_contains($l, '09:00'));
        $this->assertSame(['09:00', '', '', '', '', '', '', ''], array_map('trim', array_slice(explode('|', $empty), 1, -1)));
        $this->assertSame(0, DB::table('chatbot_sequence_subscriptions')->where('updated_at', '>', '2026-11-02 11:00:00')->count(), 'read only');
    }

    public function test_S_215f_5_a_step_with_several_sends_is_counted_with_its_first_sends_lateness(): void
    {
        // 7001: three sends answered at 10:00:30, 10:04:00 and 10:07:10 (400 s apart from first to last).
        $seq = $this->sequence('Three', [$this->stepFlow(['One', 'Two', 'Three'])->id]);
        $this->join($seq, '7001');
        $this->steps();
        foreach (['10:00:30', '10:04:00', '10:07:10'] as $t) $this->sendAt('7001', '2026-11-02 ' . $t);
        // 8001: due 10:10, its second send answered 700 s after the first.
        $this->travelTo('2026-11-02 10:10:00');
        $this->pool->beat();
        $this->replyPool->beat();
        $long = $this->sequence('Long', [$this->stepFlow(['A', 'B'])->id]);
        $this->join($long, '8001', '1002');
        $this->steps();
        foreach (['10:10:30', '10:22:10'] as $t) $this->sendAt('8001', '2026-11-02 ' . $t);
        $this->travelTo('2026-11-02 10:30:00');

        $this->assertSame(['sent: One', 'sent: Two', 'sent: Three'], $this->replies('7001'));
        $this->assertSame(['sent: A', 'sent: B'], $this->replies('8001'));
        $h = $this->speed()['hours'][10];
        $this->assertSame([2, 2, 0], [$h['due'], $h['sent'], $h['no_message']], 'both steps are matched to their sends');
        $this->assertSame(['n' => 2, 'avg' => 30.0, 'p90' => 30, 'max' => 30], $h['scheduled_to_pushed'], 'each with its first send\'s lateness, not the last');
    }
}
