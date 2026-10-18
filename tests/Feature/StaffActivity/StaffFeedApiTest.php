<?php

namespace Tests\Feature\StaffActivity;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Part B: ang read-only staff feed (/api/staff-status, /api/staff-events) sa likod ng X-STAFF-FEED-KEY.
 */
class StaffFeedApiTest extends StaffActivityTestCase
{
    private const KEY = 'test-feed-key-placeholder';

    private const STAFF_KEYS = [
        'user_id', 'name', 'role', 'department', 'last_seen_at', 'last_seen_path', 'last_login_at',
        'online', 'last_event', 'counts', 'checklist_due_not_submitted', 'is_bot', 'checker', 'checklist_approved_today',
        'checklist_late_today',
    ];

    private const EVENT_KEYS = ['user_id', 'name', 'role', 'kind', 'source', 'method', 'status', 'ref', 'created_at', 'is_bot'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.staff_feed.key' => self::KEY, 'session.driver' => 'database']);
        Carbon::setTestNow('2026-10-16 10:00:00'); // Biyernes

        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity');
        });
        Schema::create('checklist_tasks', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->string('frequency')->default('daily');
            $t->unsignedTinyInteger('frequency_day')->nullable();
            $t->boolean('is_active')->default(true);
            $t->integer('sort_order')->default(0);
            $t->string('submission_type')->default('group');
            $t->string('scheduled_time')->nullable();
            $t->string('deadline_time')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('checklist_task_users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('checklist_task_id');
            $t->foreignId('user_id');
            $t->timestamps();
        });
        Schema::create('checklist_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('checklist_task_id');
            $t->foreignId('user_id');
            $t->date('date');
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('checklist_analysis_logs', function (Blueprint $t) {
            $t->id();
            $t->string('log_type')->default('analysis');
            $t->foreignId('submission_id');
            $t->foreignId('user_id')->nullable();
            $t->text('analysis_result');
            $t->string('verdict')->nullable();
            $t->boolean('is_override')->default(false);
            $t->timestamps();
        });
    }

    private function feed(string $uri, ?string $key = self::KEY)
    {
        return $this->getJson($uri, $key === null ? [] : ['X-STAFF-FEED-KEY' => $key]);
    }

    private function event(int $userId, string $kind, string $at, string $role = 'Data Encoder'): void
    {
        DB::table('staff_events')->insert([
            'user_id' => $userId, 'role' => $role, 'kind' => $kind, 'method' => 'POST', 'status' => 302,
            'ref' => '9', 'created_at' => $at,
        ]);
    }

    private function checklistTask(string $title, array $users, array $attrs = []): int
    {
        $id = DB::table('checklist_tasks')->insertGetId(array_merge([
            'title' => $title, 'frequency' => 'daily', 'is_active' => true,
            'created_at' => '2026-10-01 08:00:00', 'updated_at' => '2026-10-01 08:00:00',
        ], $attrs));
        foreach ($users as $u) {
            DB::table('checklist_task_users')->insert(['checklist_task_id' => $id, 'user_id' => $u->id]);
        }

        return $id;
    }

    public static function badKeys(): array
    {
        return [
            'no key'    => [null, null, 401],
            'wrong key' => ['mali-na-key', null, 401],
            'unset key' => [self::KEY, '', 503],
        ];
    }

    #[DataProvider('badKeys')]
    public function test_the_feed_refuses_without_the_right_key(?string $sent, ?string $configured, int $status): void
    {
        if ($configured !== null) {
            config(['services.staff_feed.key' => $configured]);
        }

        $this->feed('/api/staff-status', $sent)->assertStatus($status);
        $this->feed('/api/staff-events?since=2026-10-16', $sent)->assertStatus($status);
    }

    public function test_post_to_the_feed_routes_is_405(): void
    {
        foreach (['/api/staff-status', '/api/staff-events'] as $uri) {
            $this->postJson($uri, [], ['X-STAFF-FEED-KEY' => self::KEY])->assertStatus(405);
        }
    }

    public function test_status_has_exactly_the_documented_keys_and_no_private_data(): void
    {
        $user = $this->staff('Marketing', 'Staff', 'Sales');
        DB::table('employee_profiles')->where('user_id', $user->id)->update(['phone' => '09170000000']);
        DB::table('users')->where('id', $user->id)->update([
            'last_seen_at' => '2026-10-16 09:58:00', 'last_seen_path' => 'checklist', 'last_login_at' => '2026-10-16 08:00:00',
        ]);
        DB::table('sessions')->insert(['id' => 'sess-secret', 'user_id' => $user->id, 'ip_address' => '10.9.8.7',
            'user_agent' => 'AgentX', 'payload' => 'payload-secret', 'last_activity' => now()->subMinutes(3)->timestamp]);
        $this->event($user->id, 'checklist.submit', '2026-10-16 09:30:00');
        $inactive = $this->staff('Marketing');
        DB::table('employee_profiles')->where('user_id', $inactive->id)->update(['status' => 'Inactive']);

        $response = $this->feed('/api/staff-status')->assertOk();

        $this->assertSame(['date', 'generated_at', 'staff', 'totals', 'checklist_late_not_submitted'], array_keys($response->json()));
        $this->assertSame('2026-10-16', $response->json('date'));
        $this->assertCount(1, $response->json('staff'));
        $row = $response->json('staff.0');
        $this->assertSame(self::STAFF_KEYS, array_keys($row));
        $this->assertSame(['kind', 'at'], array_keys($row['last_event']));
        $this->assertSame($user->id, $row['user_id']);
        $this->assertSame('Marketing', $row['role']);
        $this->assertSame('Sales', $row['department']);
        $this->assertSame('checklist', $row['last_seen_path']);
        $this->assertSame('2026-10-16T09:58:00+08:00', $row['last_seen_at']);
        $this->assertSame('2026-10-16T08:00:00+08:00', $row['last_login_at']);
        $this->assertTrue($row['online']);
        $this->assertSame(['kind' => 'checklist.submit', 'at' => '2026-10-16T09:30:00+08:00'], $row['last_event']);

        foreach ([$user->email, '09170000000', 'sess-secret', '10.9.8.7', 'AgentX', 'payload-secret', 'EMP-'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public static function statuses(): array
    {
        return [
            'NULL'         => [null, true],
            'blank'        => ['', true],
            'spaces lang'  => ['  ', true],
            'Active'       => ['Active', true],
            'Inactive'     => ['Inactive', false],
            'Resigned'     => ['Resigned', false],
            'Terminated'   => ['Terminated', false],
            'ibang casing' => [' resigned ', false],
        ];
    }

    #[DataProvider('statuses')]
    public function test_only_explicitly_inactive_statuses_are_left_out(?string $status, bool $listed): void
    {
        $user = $this->staff();
        DB::table('employee_profiles')->where('user_id', $user->id)->update(['status' => $status]);

        $ids = array_column($this->feed('/api/staff-status')->assertOk()->json('staff'), 'user_id');

        $this->assertSame($listed ? [$user->id] : [], $ids);
    }

    public static function botIds(): array
    {
        return [
            'walang laman'        => ['', [false, false]],
            'hindi nakaset'       => [null, [false, false]],
            'malformed'           => ['abc, 1.5, -2, ,', [false, false]],
            'unang user'          => ['{a}', [true, false]],
            'may sira sa paligid' => [' x, {b} ,9x', [false, true]],
        ];
    }

    #[DataProvider('botIds')]
    public function test_is_bot_is_true_only_for_the_configured_user_ids(?string $env, array $expected): void
    {
        $a = $this->staff();
        $b = $this->staff();
        config(['services.staff_feed.bot_user_ids' => $env === null ? null : strtr($env, ['{a}' => $a->id, '{b}' => $b->id])]);
        $this->event($a->id, 'login', '2026-10-16 08:00:00');
        $this->event($b->id, 'login', '2026-10-16 09:00:00');

        $staff = collect($this->feed('/api/staff-status')->assertOk()->json('staff'))->keyBy('user_id');
        $events = collect($this->feed('/api/staff-events?since=2026-10-16')->assertOk()->json('events'))->keyBy('user_id');

        $this->assertSame($expected, [$staff[$a->id]['is_bot'], $staff[$b->id]['is_bot']]);
        $this->assertSame($expected, [$events[$a->id]['is_bot'], $events[$b->id]['is_bot']]);
    }

    public function test_online_is_false_after_10_minutes_and_null_when_sessions_are_not_in_the_database(): void
    {
        $user = $this->staff();
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $user->id, 'payload' => 'x',
            'last_activity' => now()->subMinutes(11)->timestamp]);

        $this->assertFalse($this->feed('/api/staff-status')->json('staff.0.online'));

        config(['session.driver' => 'file']);
        $this->assertNull($this->feed('/api/staff-status')->json('staff.0.online'));
    }

    public function test_counts_per_kind_are_for_the_requested_manila_day(): void
    {
        $user = $this->staff();
        $this->event($user->id, 'orders.save', '2026-10-14 23:59:59');
        $this->event($user->id, 'orders.save', '2026-10-15 00:00:00');
        $this->event($user->id, 'orders.save', '2026-10-15 18:00:00');
        $this->event($user->id, 'login', '2026-10-15 08:00:00');
        $this->event($user->id, 'orders.save', '2026-10-16 00:00:00');

        $row = $this->feed('/api/staff-status?date=2026-10-15')->assertOk()->json('staff.0');

        $this->assertSame(['login' => 1, 'orders.save' => 2], $row['counts']);
        $this->assertSame('2026-10-16T00:00:00+08:00', $row['last_event']['at']);
    }

    public function test_a_bad_date_is_422(): void
    {
        $this->feed('/api/staff-status?date=16-10-2026')->assertStatus(422);
    }

    public function test_checklist_due_not_submitted_lists_due_tasks_without_a_submission_that_day(): void
    {
        $user = $this->staff();
        $other = $this->staff();
        $this->checklistTask('Bilangin ang stock', [$user]);
        $doneId = $this->checklistTask('Linisin ang bodega', [$user]);
        DB::table('checklist_submissions')->insert(['checklist_task_id' => $doneId, 'user_id' => $user->id, 'date' => '2026-10-16']);
        $this->checklistTask('Lingguhang ulat (Biyernes)', [$user], ['frequency' => 'weekly', 'frequency_day' => 5]);
        $this->checklistTask('Lingguhang ulat (Lunes)', [$user], ['frequency' => 'weekly', 'frequency_day' => 1]);
        $this->checklistTask('Buwanang ulat', [$user], ['frequency' => 'monthly', 'frequency_day' => 16]);
        $onceId = $this->checklistTask('Isang beses, tapos na', [$user], ['frequency' => 'once']);
        DB::table('checklist_submissions')->insert(['checklist_task_id' => $onceId, 'user_id' => $user->id, 'date' => '2026-10-10']);
        $this->checklistTask('Hindi aktibo', [$user], ['is_active' => false]);
        $this->checklistTask('Bukas pa gagawin', [$user], ['created_at' => '2026-10-17 08:00:00']);
        $this->checklistTask('Binura na', [$user], ['deleted_at' => '2026-10-15 12:00:00']);
        $this->checklistTask('Sa iba naka-assign', [$other]);
        $groupId = $this->checklistTask('Group: iba ang nag-submit', [$user, $other]);
        DB::table('checklist_submissions')->insert(['checklist_task_id' => $groupId, 'user_id' => $other->id, 'date' => '2026-10-16']);
        $indivId = $this->checklistTask('Individual: iba ang nag-submit', [$user, $other], ['submission_type' => 'individual']);
        DB::table('checklist_submissions')->insert(['checklist_task_id' => $indivId, 'user_id' => $other->id, 'date' => '2026-10-16']);

        $staff = collect($this->feed('/api/staff-status')->assertOk()->json('staff'))->keyBy('user_id');

        $this->assertSame(
            ['Bilangin ang stock', 'Lingguhang ulat (Biyernes)', 'Buwanang ulat', 'Individual: iba ang nag-submit'],
            $staff[$user->id]['checklist_due_not_submitted']
        );
        $this->assertSame(['Sa iba naka-assign'], $staff[$other->id]['checklist_due_not_submitted']);
    }

    public function test_events_since_newest_first_with_exact_keys(): void
    {
        $a = $this->staff('Marketing');
        $b = $this->staff('Data Encoder');
        $this->event($a->id, 'old', '2026-10-15 09:00:00', 'Marketing');
        $this->event($a->id, 'first', '2026-10-16 08:00:00', 'Marketing');
        $this->event($b->id, 'second', '2026-10-16 09:00:00');

        $response = $this->feed('/api/staff-events?since=2026-10-16 00:00:00')->assertOk();

        $this->assertSame(['since', 'count', 'events'], array_keys($response->json()));
        $this->assertSame(['second', 'first'], array_column($response->json('events'), 'kind'));
        $this->assertSame(self::EVENT_KEYS, array_keys($response->json('events.0')));
        $this->assertSame($b->name, $response->json('events.0.name'));
        $this->assertSame('2026-10-16T09:00:00+08:00', $response->json('events.0.created_at'));
        $this->assertStringNotContainsString($a->email, $response->getContent());

        $this->assertSame(['first'], array_column(
            $this->feed("/api/staff-events?since=2026-10-16&user_id={$a->id}")->json('events'), 'kind'
        ));
    }

    public function test_events_limit_defaults_to_200_and_caps_at_1000(): void
    {
        $user = $this->staff();
        $rows = [];
        for ($i = 0; $i < 1001; $i++) {
            $rows[] = ['user_id' => $user->id, 'kind' => 'k', 'created_at' => '2026-10-16 09:00:00'];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('staff_events')->insert($chunk);
        }

        $this->assertCount(200, $this->feed('/api/staff-events?since=2026-10-16')->json('events'));
        $this->assertCount(3, $this->feed('/api/staff-events?since=2026-10-16&limit=3')->json('events'));
        $this->assertCount(1000, $this->feed('/api/staff-events?since=2026-10-16&limit=5000')->json('events'));
    }

    public function test_events_without_a_valid_since_is_422(): void
    {
        $this->feed('/api/staff-events')->assertStatus(422);
        $this->feed('/api/staff-events?since=hindi-petsa')->assertStatus(422);
    }

    private function checkerEvent(int $userId, string $kind, string $ref, string $at, string $source = 'checker_1'): void
    {
        DB::table('staff_events')->insert([
            'user_id' => $userId, 'role' => 'Data Encoder', 'kind' => $kind, 'source' => $source, 'method' => 'POST',
            'status' => 200, 'ref' => $ref, 'created_at' => $at,
        ]);
    }

    public function test_events_show_kind_and_source_of_checker_rows_but_never_their_ref(): void
    {
        $user = $this->staff();
        $this->event($user->id, 'orders.save', '2026-10-16 08:00:00');
        $this->checkerEvent($user->id, 'checker.status', '501', '2026-10-16 09:00:00', 'jnt');

        $events = $this->feed('/api/staff-events?since=2026-10-16')->assertOk()->json('events');

        $this->assertSame(['checker.status', 'jnt', null], [$events[0]['kind'], $events[0]['source'], $events[0]['ref']]);
        $this->assertSame(['orders.save', null, '9'], [$events[1]['kind'], $events[1]['source'], $events[1]['ref']]);
    }

    public function test_checker_counts_per_person_use_the_last_editor_rule_for_the_manila_day(): void
    {
        $a = $this->staff();
        $b = $this->staff();
        $idle = $this->staff();
        // Order 1: si A muna, si B ang huling nagpalit ng status ngayong araw.
        $this->checkerEvent($a->id, 'checker.status', '1', '2026-10-15 09:00:00');
        $this->checkerEvent($b->id, 'checker.status', '1', '2026-10-15 10:00:00');
        // Order 2: si A ang huli ngayong araw; ang edit ni B kinabukasan ng 00:00 ay hindi na kasama.
        $this->checkerEvent($b->id, 'checker.status', '2', '2026-10-15 08:00:00');
        $this->checkerEvent($a->id, 'checker.status', '2', '2026-10-15 23:59:59');
        $this->checkerEvent($b->id, 'checker.status', '2', '2026-10-16 00:00:00');
        // Order 3: edit at validate lang ni A; ang status ni B kahapon ay ibang araw.
        $this->checkerEvent($a->id, 'checker.edit', '3', '2026-10-15 11:00:00');
        $this->checkerEvent($a->id, 'checker.validate', '3', '2026-10-15 11:05:00');
        $this->checkerEvent($b->id, 'checker.status', '3', '2026-10-14 23:59:59');
        // Parehong id pero J&T row: ibang order, hiwalay ang huling editor.
        $this->checkerEvent($a->id, 'checker.status', '1', '2026-10-15 12:00:00', 'jnt');

        $staff = collect($this->feed('/api/staff-status?date=2026-10-15')->assertOk()->json('staff'))->keyBy('user_id');

        $this->assertSame(
            ['status_last_editor' => 2, 'edited_orders' => 4, 'status_changes' => 3, 'validates' => 1],
            $staff[$a->id]['checker']
        );
        $this->assertSame(
            ['status_last_editor' => 1, 'edited_orders' => 2, 'status_changes' => 2, 'validates' => 0],
            $staff[$b->id]['checker']
        );
        $this->assertSame(
            ['status_last_editor' => 0, 'edited_orders' => 0, 'status_changes' => 0, 'validates' => 0],
            $staff[$idle->id]['checker']
        );
    }

    /** Isang submission at ang mga approval verdict nito ayon sa pagkakasunod (huli ang pinakabago). */
    private function submission(int $taskId, int $userId, string $date, array $verdicts): void
    {
        $id = DB::table('checklist_submissions')->insertGetId([
            'checklist_task_id' => $taskId, 'user_id' => $userId, 'date' => $date, 'notes' => 'Lihim na tala',
        ]);
        foreach ($verdicts as $i => $verdict) {
            DB::table('checklist_analysis_logs')->insert([
                'log_type' => 'approval', 'submission_id' => $id, 'analysis_result' => 'Paliwanag ng AI',
                'verdict' => $verdict, 'created_at' => "$date 09:0{$i}:00",
            ]);
        }
    }

    public function test_checklist_approved_today_counts_submissions_whose_latest_verdict_is_approved(): void
    {
        $a = $this->staff();
        $b = $this->staff();
        $idle = $this->staff();
        $group = $this->checklistTask('Group na gawain', [$a, $b]);
        $solo = $this->checklistTask('Sariling gawain', [$a, $b], ['submission_type' => 'individual']);
        $other = $this->checklistTask('Iba pa', [$a, $b]);
        $flip = $this->checklistTask('Inulit', [$a, $b]);
        $none = $this->checklistTask('Walang verdict', [$a, $b]);
        $flop = $this->checklistTask('Binawi', [$a, $b]);

        $this->submission($group, $a->id, '2026-10-15', ['approved']);              // group: unang nagsumite lang
        $this->submission($solo, $a->id, '2026-10-15', ['approved']);               // individual: kanya-kanya
        $this->submission($solo, $b->id, '2026-10-15', ['approved']);
        $this->submission($other, $b->id, '2026-10-15', ['not_approved']);          // hindi approved
        $this->submission($flip, $b->id, '2026-10-15', ['not_approved', 'approved']); // re-check: isang beses lang
        $this->submission($none, $a->id, '2026-10-15', []);                         // walang verdict
        $this->submission($flop, $a->id, '2026-10-15', ['approved', 'not_approved']); // huling verdict ang bilang
        $this->submission($other, $a->id, '2026-10-14', ['approved']);              // ibang araw
        $this->submission($none, $b->id, '2026-10-16', ['approved']);

        $response = $this->feed('/api/staff-status?date=2026-10-15')->assertOk();
        $staff = collect($response->json('staff'))->keyBy('user_id');

        $this->assertSame([2, 2, 0], [
            $staff[$a->id]['checklist_approved_today'],
            $staff[$b->id]['checklist_approved_today'],
            $staff[$idle->id]['checklist_approved_today'],
        ]);
        $this->assertSame(4, $response->json('totals.checklist_approved_today'));
        foreach (['Group na gawain', 'Sariling gawain', 'not_approved'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->assertStringNotContainsString('Lihim na tala', $response->getContent());
        $this->assertStringNotContainsString('Paliwanag', $response->getContent());
    }

    public function test_totals_sum_the_checker_counts_of_everyone_listed(): void
    {
        $a = $this->staff();
        $b = $this->staff();
        $this->checkerEvent($a->id, 'checker.status', '1', '2026-10-16 08:00:00');
        $this->checkerEvent($b->id, 'checker.status', '1', '2026-10-16 09:00:00');
        $this->checkerEvent($b->id, 'checker.edit', '2', '2026-10-16 09:30:00');

        $totals = $this->feed('/api/staff-status')->assertOk()->json('totals');

        $this->assertSame(['checklist_approved_today' => 0, 'status_changes' => 2, 'edited_orders' => 3], $totals);
    }

    // ── checklist_late_today (task na lampas na sa deadline ngayong araw) ──────────────────────

    /** Submission na may oras ng pagsumite at mga approval verdict ayon sa pagkakasunod. */
    private function submittedAt(int $taskId, int $userId, string $at, array $verdicts = []): void
    {
        $id = DB::table('checklist_submissions')->insertGetId([
            'checklist_task_id' => $taskId, 'user_id' => $userId, 'date' => substr($at, 0, 10),
            'notes' => 'Lihim na tala', 'created_at' => $at, 'updated_at' => $at,
        ]);
        foreach ($verdicts as $i => $verdict) {
            DB::table('checklist_analysis_logs')->insert([
                'log_type' => 'approval', 'submission_id' => $id, 'analysis_result' => 'Paliwanag ng AI',
                'verdict' => $verdict, 'created_at' => "2026-10-16 10:2{$i}:30",
            ]);
        }
    }

    private function lateAt(string $now, string $uri = '/api/staff-status')
    {
        Carbon::setTestNow($now);

        return $this->feed($uri)->assertOk();
    }

    public function test_S_051_9_a_task_past_its_deadline_and_not_submitted_is_late_for_each_assigned_person(): void
    {
        $a = $this->staff();
        $b = $this->staff();
        $id = $this->checklistTask('Bilangin ang stock', [$a, $b], ['scheduled_time' => '10:00']);

        $response = $this->lateAt('2026-10-16 10:16:00');
        $staff = collect($response->json('staff'))->keyBy('user_id');

        $entry = ['task_id' => $id, 'title' => 'Bilangin ang stock', 'deadline' => '10:15', 'submitted' => false,
            'submitted_at' => null, 'verdict' => null, 'approval_checks' => 0,
            'rechecks' => 0, 'overridden' => false, 'overridden_by' => null, 'overridden_at' => null];
        $this->assertSame([$entry], $staff[$a->id]['checklist_late_today']);
        $this->assertSame([$entry], $staff[$b->id]['checklist_late_today']);
        $this->assertSame(2, $response->json('checklist_late_not_submitted'));
    }

    public function test_S_051_10_before_the_deadline_the_task_is_not_late(): void
    {
        $a = $this->staff();
        $this->checklistTask('Bilangin ang stock', [$a], ['scheduled_time' => '10:00']);

        $response = $this->lateAt('2026-10-16 10:14:00');

        $this->assertSame([], $response->json('staff.0.checklist_late_today'));
        $this->assertSame(0, $response->json('checklist_late_not_submitted'));
    }

    public function test_S_051_11_a_late_task_submitted_later_shows_when_and_its_latest_verdict_and_check_count(): void
    {
        $a = $this->staff();
        $id = $this->checklistTask('Bilangin ang stock', [$a], ['scheduled_time' => '09:00', 'deadline_time' => '10:15']);
        $this->submittedAt($id, $a->id, '2026-10-16 10:20:00', ['approved', 'not_approved', 'not_approved']);

        $response = $this->lateAt('2026-10-16 10:40:00');

        $this->assertSame([['task_id' => $id, 'title' => 'Bilangin ang stock', 'deadline' => '10:15', 'submitted' => true,
            'submitted_at' => '10:20', 'verdict' => 'not_approved', 'approval_checks' => 3,
            'rechecks' => 2, 'overridden' => false, 'overridden_by' => null, 'overridden_at' => null]],
            $response->json('staff.0.checklist_late_today'));
        $this->assertSame(0, $response->json('checklist_late_not_submitted'));
    }

    public function test_S_051_12_an_inactive_task_or_one_not_due_today_never_appears(): void
    {
        $a = $this->staff();
        $this->checklistTask('Hindi aktibo', [$a], ['scheduled_time' => '08:00', 'is_active' => false]);
        $this->checklistTask('Lunes lang', [$a], ['scheduled_time' => '08:00', 'frequency' => 'weekly', 'frequency_day' => 1]);
        $this->checklistTask('Binura na', [$a], ['scheduled_time' => '08:00', 'deleted_at' => '2026-10-15 12:00:00']);
        $onceId = $this->checklistTask('Isang beses, tapos na', [$a], ['scheduled_time' => '08:00', 'frequency' => 'once']);
        DB::table('checklist_submissions')->insert(['checklist_task_id' => $onceId, 'user_id' => $a->id, 'date' => '2026-10-10']);

        $this->assertSame([], $this->lateAt('2026-10-16 23:00:00')->json('staff.0.checklist_late_today'));
    }

    public function test_S_051_13_a_group_task_submitted_by_one_person_is_submitted_for_everyone_assigned(): void
    {
        $a = $this->staff();
        $b = $this->staff();
        $id = $this->checklistTask('Group: linisin ang bodega', [$a, $b], ['scheduled_time' => '10:00']);
        $this->submittedAt($id, $b->id, '2026-10-16 10:30:00');

        $staff = collect($this->lateAt('2026-10-16 11:00:00')->json('staff'))->keyBy('user_id');

        foreach ([$a, $b] as $u) {
            $late = $staff[$u->id]['checklist_late_today'];
            $this->assertSame([[true, '10:30']], array_map(fn ($e) => [$e['submitted'], $e['submitted_at']], $late));
        }
    }

    public function test_S_051_14_no_note_photo_path_prompt_or_ai_text_anywhere_in_the_feed(): void
    {
        $a = $this->staff();
        $id = $this->checklistTask('Bilangin ang stock', [$a], ['scheduled_time' => '10:00']);
        $this->submittedAt($id, $a->id, '2026-10-16 10:20:00', ['not_approved']);

        $body = $this->lateAt('2026-10-16 10:40:00')->getContent();

        foreach (['Lihim na tala', 'Paliwanag', 'notes', 'file_path', 'prompt', 'analysis'] as $private) {
            $this->assertStringNotContainsString($private, $body);
        }
    }

    public function test_S_051_15_the_existing_checklist_fields_keep_their_meaning_next_to_the_late_list(): void
    {
        $a = $this->staff();
        $this->checklistTask('Late at wala pa', [$a], ['scheduled_time' => '08:00']);
        $done = $this->checklistTask('Late pero naisumite', [$a], ['scheduled_time' => '08:00']);
        $this->submittedAt($done, $a->id, '2026-10-16 09:00:00', ['approved']);
        $this->checklistTask('Wala pang oras', [$a]);

        $response = $this->lateAt('2026-10-16 10:40:00');
        $row = $response->json('staff.0');

        $this->assertSame(['Late at wala pa', 'Wala pang oras'], $row['checklist_due_not_submitted']);
        $this->assertSame(1, $row['checklist_approved_today']);
        $this->assertSame(['checklist_approved_today' => 1, 'status_changes' => 0, 'edited_orders' => 0], $response->json('totals'));
        $this->assertSame(['Late at wala pa', 'Late pero naisumite'], array_column($row['checklist_late_today'], 'title'));
    }

    public function test_S_051_29_the_late_list_shows_rechecks_and_an_override_with_who_and_when(): void
    {
        $a = $this->staff();
        $lead = $this->staff('Marketing - OIC', 'Lead');
        $id = $this->checklistTask('Bilangin ang stock', [$a], ['scheduled_time' => '10:00']);
        $this->submittedAt($id, $a->id, '2026-10-16 10:20:00', ['not_approved', 'not_approved']);
        DB::table('checklist_analysis_logs')->insert([
            'log_type' => 'approval', 'submission_id' => DB::table('checklist_submissions')->value('id'), 'user_id' => $lead->id,
            'analysis_result' => 'Dahilan ng override', 'verdict' => 'approved', 'is_override' => true,
            'created_at' => '2026-10-16 10:45:00',
        ]);

        $response = $this->lateAt('2026-10-16 11:00:00');
        $entry = collect($response->json('staff'))->firstWhere('user_id', $a->id)['checklist_late_today'][0];

        $this->assertSame(['verdict' => 'approved', 'approval_checks' => 2, 'rechecks' => 1, 'overridden' => true,
            'overridden_by' => $lead->name, 'overridden_at' => '10:45'],
            array_intersect_key($entry, array_flip(['verdict', 'approval_checks', 'rechecks', 'overridden', 'overridden_by', 'overridden_at'])));
        $this->assertStringNotContainsString('Dahilan ng override', $response->getContent());
    }

    public function test_a_past_date_lists_every_due_task_with_a_deadline_and_a_future_date_none(): void
    {
        $a = $this->staff();
        $this->checklistTask('Gabi na', [$a], ['scheduled_time' => '23:30']);
        $this->checklistTask('Lampas hatinggabi', [$a], ['scheduled_time' => '23:50']);

        $past = $this->feed('/api/staff-status?date=2026-10-15')->assertOk();
        $future = $this->feed('/api/staff-status?date=2026-10-17')->assertOk();

        $this->assertSame(['Gabi na'], array_column($past->json('staff.0.checklist_late_today'), 'title'));
        $this->assertSame([], $future->json('staff.0.checklist_late_today'));
    }
}
