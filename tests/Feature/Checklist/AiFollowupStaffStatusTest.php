<?php

namespace Tests\Feature\Checklist;

use App\Models\ChecklistAiFollowup;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MH-1 (spec 310): ang trabaho ng Marketing head sa /api/staff-status. Isang block na ai_followups para sa araw na iyon:
 * mga bilang at ang pinakahuling follow-up (oras, unang pangalan, state), walang message text.
 */
class AiFollowupStaffStatusTest extends AiFollowupTestCase
{
    private const ZERO = [
        'drafted' => 0, 'approved' => 0, 'auto_approved' => 0, 'rejected' => 0, 'waiting' => 0,
        'sent' => 0, 'failed' => 0, 'last_at' => null, 'last' => null,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('users', function (Blueprint $t) {
            $t->timestamp('last_seen_at')->nullable();
            $t->string('last_seen_path')->nullable();
            $t->timestamp('last_login_at')->nullable();
        });
        config(['services.staff_feed.key' => 'feed-key']);
    }

    private function feed(string $query = '', ?string $key = 'feed-key')
    {
        return $this->getJson('/api/staff-status' . $query, $key === null ? [] : ['X-STAFF-FEED-KEY' => $key]);
    }

    /** Ang oras ng paggawa (hindi fillable sa model). */
    private function createdAt(ChecklistAiFollowup $f, string $at): ChecklistAiFollowup
    {
        DB::table('checklist_tasks')->where('id', $f->id)->update(['created_at' => $at]);

        return $f->fresh();
    }

    /** Naipadala sa Likha Chat sa oras na iyon. */
    private function sent(ChecklistAiFollowup $f, string $at): void
    {
        $id = DB::table('chat_messages')->insertGetId([
            'conversation_id' => 1, 'sender_ai' => 'mira', 'body' => 'secret body', 'created_at' => $at, 'updated_at' => $at,
        ]);
        DB::table('checklist_tasks')->where('id', $f->id)->update(['chat_message_id' => $id]);
    }

    private function iso(string $at): string
    {
        return \Carbon\Carbon::parse($at, 'Asia/Manila')->toIso8601String();
    }

    public function test_each_field_counts_that_days_followups(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $ben = $this->marketer('Marketing', 'Ben Cruz');
        $task = $this->lateTask($joy);

        // waiting: draft pa
        $this->createdAt($this->followup($joy, $task, ['description' => 'Draft text']), '2026-11-18 09:00:00');
        // approved ng CEO at naipadala
        $sent = $this->reviewed($this->createdAt($this->followup($ben, $task), '2026-11-18 09:30:00'), $ceo, 'approved',
            ['reviewed_at' => '2026-11-18 10:00:00']);
        $this->sent($sent, '2026-11-18 10:05:00');
        // auto-approved (walang reviewer) pero pumalya ang chat
        $this->createdAt($this->followup($joy, $task), '2026-11-18 10:30:00')->forceFill([
            'review_status' => 'approved', 'reviewed_by' => null, 'reviewed_at' => '2026-11-18 11:00:00',
            'chat_failed_at' => '2026-11-18 11:00:05',
        ])->save();
        // rejected ng CEO, ang pinakahuli
        $this->reviewed($this->createdAt($this->followup($ben, $task), '2026-11-18 11:30:00'), $ceo, 'rejected',
            ['reviewed_at' => '2026-11-18 12:00:00', 'review_note' => 'No']);
        // expired: ginawa ng AI, kasama sa drafted lang
        $this->createdAt($this->followup($joy, $task, ['review_status' => 'expired']), '2026-11-18 08:00:00');
        // ibang araw: hindi kasama
        $this->createdAt($this->followup($joy, $task, ['follows_date' => '2026-11-17']), '2026-11-17 15:00:00');

        $response = $this->feed()->assertOk();

        $this->assertSame([
            'drafted' => 5, 'approved' => 2, 'auto_approved' => 1, 'rejected' => 1, 'waiting' => 1,
            'sent' => 1, 'failed' => 1,
            'last_at' => $this->iso('2026-11-18 12:00:00'),
            'last' => ['at' => $this->iso('2026-11-18 12:00:00'), 'person' => 'Ben', 'state' => 'rejected'],
        ], $response->json('ai_followups'));
        // walang message text, review note o chat body
        $this->assertStringNotContainsString('Draft text', $response->getContent());
        $this->assertStringNotContainsString('Hi Joy', $response->getContent());
        $this->assertStringNotContainsString('secret body', $response->getContent());
    }

    public function test_last_is_the_followup_with_the_newest_activity_and_its_state(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $ben = $this->marketer('Marketing', 'Ben Cruz');
        $task = $this->lateTask($joy);

        $this->createdAt($this->followup($joy, $task), '2026-11-18 12:00:00');
        // mas maagang ginawa pero ang pagpadala ang pinakahuling nangyari
        $f = $this->reviewed($this->createdAt($this->followup($ben, $task), '2026-11-18 09:00:00'), $ceo, 'approved',
            ['reviewed_at' => '2026-11-18 12:30:00']);
        $this->sent($f, '2026-11-18 13:15:00');

        $block = $this->feed()->assertOk()->json('ai_followups');
        $this->assertSame($this->iso('2026-11-18 13:15:00'), $block['last_at']);
        $this->assertSame(['at' => $this->iso('2026-11-18 13:15:00'), 'person' => 'Ben', 'state' => 'sent'], $block['last']);

        // isang bagong draft pagkatapos: siya na ang pinakahuli
        $this->createdAt($this->followup($joy, $task), '2026-11-18 14:00:00');
        $block = $this->feed()->assertOk()->json('ai_followups');
        $this->assertSame(['at' => $this->iso('2026-11-18 14:00:00'), 'person' => 'Joy', 'state' => 'draft'], $block['last']);
    }

    public function test_approved_and_failed_states_of_the_last_one(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $task = $this->lateTask($joy);
        $f = $this->reviewed($this->createdAt($this->followup($joy, $task), '2026-11-18 09:00:00'), $ceo, 'approved',
            ['reviewed_at' => '2026-11-18 09:10:00']);

        $this->assertSame('approved', $this->feed()->json('ai_followups.last.state'));

        $f->forceFill(['chat_failed_at' => '2026-11-18 09:10:02'])->save();
        $block = $this->feed()->json('ai_followups');
        $this->assertSame('failed', $block['last']['state']);
        $this->assertSame(1, $block['failed']);
        $this->assertSame(0, $block['sent']);
        // ang palya ay hindi bagong aktibidad: ang huling oras ay ang review pa rin
        $this->assertSame($this->iso('2026-11-18 09:10:00'), $block['last_at']);
    }

    public function test_a_day_with_no_followups_gives_zeros_and_nulls(): void
    {
        $this->marketer();

        $this->assertSame(self::ZERO, $this->feed()->assertOk()->json('ai_followups'));
        $this->assertSame(self::ZERO, $this->feed('?date=2025-01-01')->assertOk()->json('ai_followups'));
    }

    public function test_the_date_parameter_picks_that_day(): void
    {
        $joy = $this->marketer();
        $task = $this->lateTask($joy);
        $this->createdAt($this->followup($joy, $task, ['follows_date' => '2026-11-17']), '2026-11-17 15:00:00');
        $this->createdAt($this->followup($joy, $task), '2026-11-18 09:00:00');
        $this->createdAt($this->followup($joy, $task), '2026-11-18 09:30:00');

        $this->assertSame(2, $this->feed()->json('ai_followups.drafted'));
        $yesterday = $this->feed('?date=2026-11-17')->assertOk()->json('ai_followups');
        $this->assertSame(1, $yesterday['drafted']);
        $this->assertSame($this->iso('2026-11-17 15:00:00'), $yesterday['last_at']);
        $this->assertSame(0, $this->feed('?date=2026-11-19')->json('ai_followups.drafted'));
        $this->feed('?date=bukas')->assertStatus(422);
    }

    public function test_without_follows_date_the_created_day_in_manila_counts(): void
    {
        $joy = $this->marketer();
        $task = $this->lateTask($joy);
        $f = $this->createdAt($this->followup($joy, $task), '2026-11-17 23:30:00');
        DB::table('checklist_tasks')->where('id', $f->id)->update(['follows_date' => null]);

        $this->assertSame(1, $this->feed('?date=2026-11-17')->json('ai_followups.drafted'));
        $this->assertSame(0, $this->feed('?date=2026-11-18')->json('ai_followups.drafted'));
    }

    public function test_soft_deleted_followups_do_not_count(): void
    {
        $joy = $this->marketer();
        $task = $this->lateTask($joy);
        $f = $this->createdAt($this->followup($joy, $task), '2026-11-18 09:00:00');
        DB::table('checklist_tasks')->where('id', $f->id)->update(['deleted_at' => '2026-11-18 09:05:00']);

        $this->assertSame(self::ZERO, $this->feed()->assertOk()->json('ai_followups'));
    }

    public function test_regular_checklist_tasks_are_not_followups(): void
    {
        $joy = $this->marketer();
        $this->lateTask($joy, ['created_at' => '2026-11-18 08:00:00']);

        $this->assertSame(self::ZERO, $this->feed()->assertOk()->json('ai_followups'));
    }

    public function test_the_key_check_is_unchanged(): void
    {
        $joy = $this->marketer();
        $this->followup($joy, $this->lateTask($joy));

        $this->feed('', null)->assertStatus(401);
        $this->feed('', 'mali-na-key')->assertStatus(401);
        config(['services.staff_feed.key' => '']);
        $this->feed()->assertStatus(503);
    }

    public function test_the_old_keys_and_values_are_the_same_with_followups_present(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $task = $this->lateTask($joy);
        $this->feed(); // ang unang bisita ang nagsusulat ng last_seen; pareho na iyon sa susunod

        $before = $this->feed()->assertOk()->json();
        $this->assertSame(
            ['date', 'generated_at', 'staff', 'totals', 'checklist_late_not_submitted', 'ai_followups'],
            array_keys($before)
        );

        $this->reviewed($this->followup($joy, $task), $ceo, 'approved');
        $this->followup($joy, $task);
        $after = $this->feed()->assertOk()->json();

        $this->assertSame(2, $after['ai_followups']['drafted']);
        unset($before['ai_followups'], $after['ai_followups']);
        $this->assertSame($before, $after);
    }
}
