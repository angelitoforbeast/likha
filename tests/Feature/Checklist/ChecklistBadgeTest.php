<?php

namespace Tests\Feature\Checklist;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Ang badge sa "Checklist" link ng nav: ilan ang kailangang gawin ngayon ng naka-login (late, malapit
 * na ang deadline, walang oras pero bukas pa, at hindi na-approve), at sa CEO at OIC, ilan ang late
 * sa team. Nakikita sa bawat page na gumagamit ng layout.
 */
class ChecklistBadgeTest extends ChecklistTestCase
{
    private const ROLES = ['CEO', 'Marketing - OIC', 'Marketing', 'Data Encoder', 'Data Encoder - OIC'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-16 10:00:00'); // Biyernes, Manila

        Schema::create('nav_links', function (Blueprint $t) {
            $t->id();
            $t->string('key', 80)->unique();
            $t->string('label', 100);
            $t->string('route_url', 255);
            $t->string('icon', 100)->nullable();
            $t->string('active_pattern', 255)->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('nav_link_role_visibility', function (Blueprint $t) {
            $t->id();
            $t->foreignId('nav_link_id');
            $t->string('role', 50);
            $t->boolean('is_visible')->default(false);
            $t->timestamps();
        });
        // Ang key ng Checklist link ay itinakda sa nav settings, kaya URL ang hinahanap ng layout
        $id = DB::table('nav_links')->insertGetId([
            'key' => 'nav_checklist_x', 'label' => 'Checklist', 'route_url' => '/checklist',
            'icon' => 'fa-solid fa-list-check', 'active_pattern' => 'checklist*', 'sort_order' => 1,
        ]);
        foreach (self::ROLES as $role) {
            DB::table('nav_link_role_visibility')->insert(['nav_link_id' => $id, 'role' => $role, 'is_visible' => true]);
        }

        // Isang page na hindi checklist pero gumagamit ng layout
        Route::middleware(['web', 'auth'])->get('/badge-probe', fn () => Blade::render('<x-layout>probe</x-layout>'));
    }

    private function page(User $user, string $uri = '/badge-probe'): string
    {
        return $this->actingAs($user)->get($uri)->assertOk()->getContent();
    }

    /** [count, tone, title] ng badge ng sarili, o null kapag wala. */
    private function badge(string $html): ?array
    {
        if (!preg_match('/<span[^>]*data-badge="checklist"[^>]*>/', $html, $m)) {
            return null;
        }
        preg_match('/data-count="(\d+)"/', $m[0], $c);
        preg_match('/data-tone="(\w+)"/', $m[0], $t);
        preg_match('/title="([^"]*)"/', $m[0], $h);

        return [(int) $c[1], $t[1], html_entity_decode($h[1])];
    }

    private function teamBadge(string $html): ?int
    {
        return preg_match('/data-badge="checklist-team"[^>]*data-count="(\d+)"/', $html, $m) ? (int) $m[1] : null;
    }

    public function test_S_051_16_one_late_task_and_one_due_in_20_minutes_is_2_red(): void
    {
        $me = $this->user('Marketing');
        $this->task(['title' => 'Late na', 'scheduled_time' => '09:00'], [$me]);
        $this->task(['title' => 'Malapit na', 'scheduled_time' => '10:05'], [$me]);   // deadline 10:20

        $this->assertSame([2, 'red', '1 late, 1 due soon'], $this->badge($this->page($me)));
    }

    public function test_S_051_17_only_one_due_in_20_minutes_is_1_neutral(): void
    {
        $me = $this->user('Marketing');
        $this->task(['title' => 'Malapit na', 'scheduled_time' => '09:00', 'deadline_time' => '10:20'], [$me]);
        $this->task(['title' => 'Mamaya pa', 'scheduled_time' => '11:00'], [$me]);    // deadline 11:15: lampas 30 min
        $done = $this->task(['title' => 'Tapos na', 'scheduled_time' => '09:00'], [$me]);
        $this->submission($done, $me, '2026-10-16');

        $this->assertSame([1, 'neutral', '1 due soon'], $this->badge($this->page($me)));
    }

    public function test_S_051_18_a_not_approved_submission_today_counts_1(): void
    {
        $me = $this->user('Marketing');
        $task = $this->task(['title' => 'Naisumite', 'scheduled_time' => '09:00'], [$me]);
        $sub = $this->submission($task, $me, '2026-10-16');
        $this->approval($sub, 'approved', '2026-10-16 09:10:00');
        $this->approval($sub, 'not_approved', '2026-10-16 09:20:00');
        $old = $this->task(['title' => 'Kahapon', 'frequency' => 'weekly', 'frequency_day' => 4], [$me]);
        $this->approval($this->submission($old, $me, '2026-10-15'), 'not_approved', '2026-10-15 09:20:00');

        $this->assertSame([1, 'neutral', '1 not approved'], $this->badge($this->page($me)));
    }

    public function test_S_051_19_with_nothing_open_there_is_no_badge(): void
    {
        $me = $this->user('Marketing');
        $done = $this->task(['title' => 'Tapos na', 'scheduled_time' => '09:00'], [$me]);
        $this->approval($this->submission($done, $me, '2026-10-16'), 'approved', '2026-10-16 09:10:00');
        $this->task(['title' => 'Hindi aktibo', 'scheduled_time' => '09:00', 'is_active' => false], [$me]);
        $this->task(['title' => 'Sa iba', 'scheduled_time' => '09:00'], [$this->user('Marketing')]);

        $html = $this->page($me);

        $this->assertNull($this->badge($html));
        $this->assertNull($this->teamBadge($html));
    }

    public function test_S_051_20_the_badge_shows_on_a_checklist_page_and_on_any_other_page_with_the_layout(): void
    {
        $me = $this->user('Data Encoder');
        $this->task(['title' => 'Walang oras'], [$me]);
        $this->task(['title' => 'Late na', 'scheduled_time' => '08:00'], [$me]);

        foreach (['/badge-probe', '/checklist'] as $uri) {
            $this->assertSame([2, 'red', '1 late, 1 open'], $this->badge($this->page($me, $uri)), $uri);
        }
        // pati sa mobile nav
        $this->assertSame(2, substr_count($this->page($me), 'title="1 late, 1 open"'));
    }

    public function test_S_051_21_a_marketing_oic_sees_the_late_tasks_of_their_department_and_a_marketing_user_does_not(): void
    {
        $oic = $this->user('Marketing - OIC');
        $mkt = $this->user('Marketing');
        $byDept = $this->user('Staff', 'Marketing');
        $encoder = $this->user('Data Encoder');
        $noDept = $this->user('');
        foreach ([$mkt, $byDept, $encoder, $noDept] as $u) {
            $this->task(['title' => "Late ni {$u->id}", 'scheduled_time' => '08:00'], [$u]);
        }
        $group = $this->task(['title' => 'Group, naisumite', 'scheduled_time' => '08:00'], [$mkt, $byDept]);
        $this->submission($group, $byDept, '2026-10-16');

        $this->assertSame(2, $this->teamBadge($this->page($oic)));
        $this->assertNull($this->badge($this->page($oic)));
        $this->assertNull($this->teamBadge($this->page($mkt)));
        $this->assertSame([1, 'red', '1 late'], $this->badge($this->page($mkt)));
    }

    public function test_S_051_22_the_ceos_second_number_covers_all_staff(): void
    {
        $ceo = $this->user('CEO');
        $mkt = $this->user('Marketing');
        $encoder = $this->user('Data Encoder');
        $noDept = $this->user('');
        $gone = $this->user('Marketing');
        DB::table('employee_profiles')->where('user_id', $gone->id)->update(['status' => 'Resigned']);
        foreach ([$mkt, $encoder, $noDept, $gone] as $u) {
            $this->task(['title' => "Late ni {$u->id}", 'scheduled_time' => '08:00'], [$u]);
        }
        $this->task(['title' => 'Hindi pa late', 'scheduled_time' => '11:00'], [$encoder]);

        $html = $this->page($ceo);

        $this->assertSame(3, $this->teamBadge($html));
        $this->assertStringContainsString('title="3 late sa team"', $html);
    }

    public function test_S_051_23_two_page_loads_within_60_seconds_run_the_badge_queries_once(): void
    {
        $me = $this->user('Marketing - OIC');
        $this->task(['title' => 'Late na', 'scheduled_time' => '08:00'], [$me]);
        $queries = 0;
        DB::listen(function ($q) use (&$queries) {
            if (str_contains($q->sql, 'checklist_')) {
                $queries++;
            }
        });

        $this->page($me);
        $first = $queries;
        Carbon::setTestNow('2026-10-16 10:00:59');
        $this->page($me);
        $second = $queries - $first;
        Carbon::setTestNow('2026-10-16 10:01:01');
        $this->page($me);
        $third = $queries - $first;

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second);
        $this->assertGreaterThan(0, $third, 'pagkalipas ng 60 segundo, bilang ulit');
    }

    public function test_a_failing_badge_query_hides_the_badge_logs_the_class_and_keeps_the_page(): void
    {
        $me = $this->user('Marketing');
        $this->task(['title' => 'Late na', 'scheduled_time' => '08:00'], [$me]);
        Schema::table('checklist_task_users', fn (Blueprint $t) => $t->renameColumn('user_id', 'member_id'));
        Log::spy();

        $html = $this->page($me);

        $this->assertNull($this->badge($html));
        $this->assertStringContainsString('probe', $html);
        Log::shouldHaveReceived('warning')->once()->with('Checklist badge failed: Illuminate\Database\QueryException');
    }
}
