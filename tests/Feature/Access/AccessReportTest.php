<?php

namespace Tests\Feature\Access;

use App\Models\AppSetting;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/** AC-3: /owner/access-report, CEO only: one row per role and page over the chosen days. */
class AccessReportTest extends AccessTestCase
{
    private function row(int $userId, string $role, string $pattern, string $decision, string $day, int $hits, string $lastAt): void
    {
        DB::table('access_records')->insert([
            'user_id' => $userId, 'role' => $role, 'path_pattern' => $pattern, 'decision' => $decision,
            'day' => $day, 'hits' => $hits, 'last_at' => $lastAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedRows(): void
    {
        $today = now()->toDateString();
        $this->row(11, 'Marketing', '/finance/supply', 'would_block', $today, 3, "$today 09:15:00");
        $this->row(12, 'Marketing', '/finance/supply', 'would_block', $today, 2, "$today 10:40:00");
        $this->row(11, 'Marketing', '/finance/supply', 'would_block', now()->subDays(2)->toDateString(), 4, now()->subDays(2)->toDateString() . ' 08:00:00');
        $this->row(13, 'Data Encoder', '/test-access/no-home/{id}', 'unmapped', $today, 1, "$today 11:00:00");
        // Outside the default 7 days.
        $this->row(14, 'Data Encoder', '/old/page', 'would_block', now()->subDays(10)->toDateString(), 9, now()->subDays(10)->toDateString() . ' 08:00:00');
    }

    public function test_the_ceo_sees_one_row_per_role_and_page_with_hits_people_and_last_time(): void
    {
        $this->seedRows();

        $response = $this->actingAs($this->staff('CEO'))->get('/owner/access-report')->assertOk()
            ->assertSee('Recording only: nobody is blocked yet')
            ->assertSee('/finance/supply')
            ->assertSee('Supply Finance')          // the nav link it maps to
            ->assertSee('/test-access/no-home/{id}')
            ->assertSee('No nav link')
            ->assertDontSee('/old/page');

        $rows = $response->viewData('rows');
        $supply = $rows->firstWhere('path_pattern', '/finance/supply');
        $this->assertSame(9, (int) $supply->hits);
        $this->assertSame(2, (int) $supply->people);
        $this->assertStringContainsString('10:40', (string) $supply->last_at);
        $this->assertSame('Supply Finance', $supply->nav_label);
        $this->assertNull($rows->firstWhere('path_pattern', '/test-access/no-home/{id}')->nav_label);
        // Most hits first.
        $this->assertSame('/finance/supply', $rows->first()->path_pattern);
    }

    public function test_the_days_can_be_chosen_between_1_and_30(): void
    {
        $this->seedRows();
        $ceo = $this->staff('CEO');

        $rows = $this->actingAs($ceo)->get('/owner/access-report?days=30')->assertOk()->viewData('rows');
        $this->assertNotNull($rows->firstWhere('path_pattern', '/old/page'));

        $rows = $this->actingAs($ceo)->get('/owner/access-report?days=1')->assertOk()->viewData('rows');
        $this->assertSame(5, (int) $rows->firstWhere('path_pattern', '/finance/supply')->hits);

        $this->actingAs($ceo)->get('/owner/access-report?days=999')->assertOk()->assertViewHas('days', 30);
        $this->actingAs($ceo)->get('/owner/access-report?days=0')->assertOk()->assertViewHas('days', 1);
        $this->actingAs($ceo)->get('/owner/access-report?days=abc')->assertOk()->assertViewHas('days', 7);
    }

    public function test_filters_by_role_and_decision(): void
    {
        $this->seedRows();
        $ceo = $this->staff('CEO');

        $rows = $this->actingAs($ceo)->get('/owner/access-report?role=Data%20Encoder')->assertOk()->viewData('rows');
        $this->assertSame(['/test-access/no-home/{id}'], $rows->pluck('path_pattern')->all());

        $rows = $this->actingAs($ceo)->get('/owner/access-report?decision=would_block')->assertOk()->viewData('rows');
        $this->assertSame(['/finance/supply'], $rows->pluck('path_pattern')->all());

        // An unknown decision is ignored, not an error.
        $this->actingAs($ceo)->get('/owner/access-report?decision=drop')->assertOk();
    }

    public function test_the_mode_line_says_when_blocking_is_on(): void
    {
        AppSetting::set('access.mode', 'enforce');

        $this->actingAs($this->staff('CEO'))->get('/owner/access-report')->assertOk()
            ->assertSee('Blocking is on')
            ->assertDontSee('Recording only');
    }

    public function test_an_empty_report_says_so(): void
    {
        $this->actingAs($this->staff('CEO'))->get('/owner/access-report')->assertOk()->assertSee('Nothing recorded');
    }

    public static function others(): array
    {
        return ['oic' => ['Marketing - OIC'], 'staff' => ['Data Encoder'], 'no role' => [null], 'no profile' => [false]];
    }

    #[DataProvider('others')]
    public function test_anyone_but_the_ceo_gets_403(string|null|false $role): void
    {
        $this->actingAs($this->staff($role))->get('/owner/access-report')->assertForbidden();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/owner/access-report')->assertRedirect();
    }
}
