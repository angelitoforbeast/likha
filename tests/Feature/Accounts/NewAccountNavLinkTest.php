<?php

namespace Tests\Feature\Accounts;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * AR-5: CEO and Marketing - OIC find the new-account page in the nav (a nav_links row, as every role-gated link is
 * shown), and the CEO also from /owner/users; no other role sees the link.
 */
class NewAccountNavLinkTest extends AccountsTestCase
{
    private const MIGRATION = 'database/migrations/2026_11_29_298100_add_new_account_nav_link.php';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth'])->get('/accounts-nav-probe', fn () => Blade::render('<x-layout><x-slot name="heading">Probe</x-slot><p>probe body</p></x-layout>'));
    }

    public static function visibility(): array
    {
        return [
            'CEO'                => ['CEO', true],
            'Marketing - OIC'    => ['Marketing - OIC', true],
            'Marketing'          => ['Marketing', false],
            'Data Encoder - OIC' => ['Data Encoder - OIC', false],
            'Data Encoder'       => ['Data Encoder', false],
        ];
    }

    #[DataProvider('visibility')]
    public function test_AR5_only_ceo_and_marketing_oic_see_the_link(string $role, bool $sees): void
    {
        $html = $this->actingAs($this->user($role))->get('/accounts-nav-probe')->assertOk()->getContent();

        $this->assertStringContainsString('probe body', $html);
        $this->assertSame($sees, str_contains($html, '/accounts/create'), $role);
    }

    public function test_AR5_the_migration_adds_one_link_visible_to_the_two_roles(): void
    {
        $links = DB::table('nav_links')->where('route_url', '/accounts/create')->get();
        $this->assertCount(1, $links);
        $this->assertSame('New Account', $links[0]->label);
        $this->assertTrue((bool) $links[0]->is_active);

        $visible = DB::table('nav_link_role_visibility')->where('nav_link_id', $links[0]->id)->where('is_visible', true)->pluck('role')->all();
        $this->assertEqualsCanonicalizing(['CEO', 'Marketing - OIC'], $visible);
        $hidden = DB::table('nav_link_role_visibility')->where('nav_link_id', $links[0]->id)->where('is_visible', false)->pluck('role')->all();
        $this->assertContains('Marketing', $hidden);
        $this->assertContains('Data Encoder - OIC', $hidden);
    }

    public function test_AR5_the_migration_is_idempotent_and_down_removes_the_link(): void
    {
        (require base_path(self::MIGRATION))->up();
        $this->assertSame(1, DB::table('nav_links')->where('route_url', '/accounts/create')->count());

        $id = DB::table('nav_links')->where('route_url', '/accounts/create')->value('id');
        (require base_path(self::MIGRATION))->down();
        $this->assertSame(0, DB::table('nav_links')->where('route_url', '/accounts/create')->count());
        $this->assertSame(0, DB::table('nav_link_role_visibility')->where('nav_link_id', $id)->count());
    }

    public function test_AR5_the_link_goes_in_settings_when_the_nav_has_sections(): void
    {
        if (!Schema::hasColumn('nav_links', 'section')) {
            $this->markTestSkipped('This line has no nav sections (spec 295).');
        }

        $this->assertSame('Settings', DB::table('nav_links')->where('route_url', '/accounts/create')->value('section'));
    }

    public function test_AR5_the_migration_works_without_a_section_column(): void
    {
        (require base_path(self::MIGRATION))->down();
        if (Schema::hasColumn('nav_links', 'section')) {
            Schema::table('nav_links', fn ($t) => $t->dropColumn('section'));
        }

        (require base_path(self::MIGRATION))->up();

        $this->assertSame(1, DB::table('nav_links')->where('route_url', '/accounts/create')->count());
    }

    public function test_AR5_owner_users_links_to_the_page_for_the_ceo(): void
    {
        $html = $this->actingAs($this->user('CEO'))->get('/owner/users')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<a [^>]*href="[^"]*\/accounts\/create"[^>]*data-new-account/', $html);
    }
}
