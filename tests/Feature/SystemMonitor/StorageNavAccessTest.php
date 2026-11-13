<?php

namespace Tests\Feature\SystemMonitor;

use App\Services\StorageWarning;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec 243: the Storage view and its banner follow the "Storage" link in /owner/nav-settings (NavAccess), not a role
 * written in code. The base case runs the nav tables migration and the Storage link migration.
 */
class StorageNavAccessTest extends StorageTestCase
{
    private const URL = '/system-monitor/storage';

    private const ROLES = ['CEO', 'Marketing - OIC', 'Marketing', 'Data Encoder', 'Data Encoder - OIC'];

    private function link(): ?object
    {
        return DB::table('nav_links')->where('key', 'storage')->first();
    }

    /** @return array<string, bool> role => is_visible of the Storage link */
    private function visibility(): array
    {
        return DB::table('nav_link_role_visibility')->where('nav_link_id', $this->link()->id)
            ->pluck('is_visible', 'role')->map(fn ($v) => (bool) $v)->all();
    }

    private function page(?string $role): string
    {
        $this->actingAs($this->user($role));

        return (string) $this->blade('<x-layout>body</x-layout>');
    }

    // S1: the nav link

    public function test_S1_the_migration_adds_the_storage_link_last_checked_for_ceo_and_oic_only(): void
    {
        $link = $this->link();

        $this->assertNotNull($link);
        $this->assertSame('Storage', $link->label);
        $this->assertSame('/system-monitor/storage', $link->route_url);
        $this->assertSame('fa-solid fa-hard-drive', $link->icon);
        $this->assertSame('system-monitor/storage*', $link->active_pattern);
        $this->assertTrue((bool) $link->is_active);
        $this->assertSame((int) DB::table('nav_links')->max('sort_order'), (int) $link->sort_order);
        $this->assertSame(1, DB::table('nav_links')->where('sort_order', $link->sort_order)->count(), 'alone at the end');

        $visibility = $this->visibility();
        ksort($visibility);
        $expected = ['CEO' => true, 'Data Encoder' => false, 'Data Encoder - OIC' => false, 'Marketing' => false, 'Marketing - OIC' => true];
        $this->assertSame($expected, $visibility);
    }

    public function test_S1_the_migration_is_idempotent_and_down_removes_the_link(): void
    {
        $migration = require base_path(self::STORAGE_LINK_MIGRATION);
        $links = DB::table('nav_links')->count();

        $migration->up();
        $this->assertSame(1, DB::table('nav_links')->where('key', 'storage')->count());
        $this->assertSame($links, DB::table('nav_links')->count());
        $this->assertSame(5, DB::table('nav_link_role_visibility')->where('nav_link_id', $this->link()->id)->count());

        $id = $this->link()->id;
        $migration->down();
        $this->assertNull($this->link());
        $this->assertSame(0, DB::table('nav_link_role_visibility')->where('nav_link_id', $id)->count());
        $this->assertSame($links - 1, DB::table('nav_links')->count(), 'only the Storage link is removed');

        $migration->down(); // nothing left to remove: no error
        $migration->up();
        $this->assertNotNull($this->link());
    }

    public function test_S1_nav_settings_lists_storage_like_the_other_links(): void
    {
        $id = $this->link()->id;

        $response = $this->actingAs($this->user('CEO'))->get('/owner/nav-settings')->assertOk();

        $response->assertSee('Storage')->assertSee('/system-monitor/storage')->assertSee('fa-solid fa-hard-drive');
        $html = $response->getContent();
        foreach (self::ROLES as $role) {
            $pattern = '#<input[^>]*name="visibility\[' . $id . '\]\[' . preg_quote(e($role), '#') . '\]"[^>]*>#';
            $this->assertSame(1, preg_match($pattern, $html, $input), "a checkbox for {$role}");
            $this->assertSame(in_array($role, ['CEO', 'Marketing - OIC'], true), (bool) preg_match('/\schecked[\s>]/', $input[0]), "{$role} checked or not");
        }
    }

    public function test_S1_the_menu_shows_storage_to_a_checked_role_only(): void
    {
        $this->diskAt(50); // no banner, so the only Storage href is the menu link

        $this->assertStringContainsString('href="/system-monitor/storage"', $this->page('Marketing - OIC'));
        $this->assertStringContainsString('href="/system-monitor/storage"', $this->page('CEO'));
        $this->assertStringNotContainsString('/system-monitor/storage', $this->page('Marketing'));

        $this->setStorageAccess('Marketing', true);
        $this->assertStringContainsString('href="/system-monitor/storage"', $this->page('Marketing'));

        $this->setStorageAccess('Marketing - OIC', false);
        $this->assertStringNotContainsString('/system-monitor/storage', $this->page('Marketing - OIC'));
    }

    // S2: access to the Storage view

    public function test_S2_a_checked_role_opens_the_view_and_the_ceo_always_does(): void
    {
        $this->diskAt(50);

        $this->actingAs($this->user('Marketing - OIC'))->get(self::URL)->assertOk()->assertSee('Disk (/)');

        $this->setStorageAccess('Data Encoder', true);
        $this->actingAs($this->user('Data Encoder'))->get(self::URL)->assertOk();

        $this->setStorageAccess('CEO', false);
        $this->actingAs($this->user('CEO'))->get(self::URL)->assertOk();
    }

    public static function uncheckedRoles(): array
    {
        return [
            'marketing'          => ['Marketing'],
            'data encoder'       => ['Data Encoder'],
            'data encoder oic'   => ['Data Encoder - OIC'],
            'a role not in nav'  => ['Staff'],
            'no role'            => [null],
        ];
    }

    #[DataProvider('uncheckedRoles')]
    public function test_S2_an_unchecked_role_gets_404(?string $role): void
    {
        $this->diskAt(50);

        $this->actingAs($this->user($role))->get(self::URL)->assertNotFound();
    }

    public function test_S2_an_inactive_link_closes_the_view_for_everyone_but_the_ceo(): void
    {
        DB::table('nav_links')->where('key', 'storage')->update(['is_active' => false]);

        $this->actingAs($this->user('Marketing - OIC'))->get(self::URL)->assertNotFound();
        $this->actingAs($this->user('CEO'))->get(self::URL)->assertOk();
    }

    /** The visibility payload of the nav settings form as it is now, with one checkbox changed. */
    private function navSettingsPayload(string $role, bool $checked): array
    {
        $storageId = $this->link()->id;
        $visibility = [];
        foreach (DB::table('nav_link_role_visibility')->where('is_visible', true)->get() as $row) {
            $visibility[$row->nav_link_id][$row->role] = '1';
        }
        if ($checked) {
            $visibility[$storageId][$role] = '1';
        } else {
            unset($visibility[$storageId][$role]);
        }

        return ['visibility' => $visibility];
    }

    public function test_S2_S3_unchecking_the_oic_in_nav_settings_closes_the_view_and_the_banner_at_once(): void
    {
        $this->diskAt(85);
        $ceo = $this->user('CEO');
        $oic = $this->user('Marketing - OIC');

        // Warm the per-role cache first: the save must not leave a stale "yes" behind for 60 seconds.
        $this->actingAs($oic)->get(self::URL)->assertOk()->assertSee('Server storage is at 85%');

        $this->actingAs($ceo)->post('/owner/nav-settings', $this->navSettingsPayload('Marketing - OIC', false))
            ->assertRedirect(route('owner.nav-settings'));
        $this->assertFalse($this->visibility()['Marketing - OIC']);

        $this->actingAs($oic)->get(self::URL)->assertNotFound();
        $this->assertStringNotContainsString('data-storage-banner', (string) $this->blade('<x-layout>body</x-layout>'));
        $this->actingAs($ceo)->get('/system-monitor')->assertOk()->assertSee('Server storage is at 85%');
    }

    public function test_S2_S3_checking_marketing_in_nav_settings_gives_marketing_the_view_and_the_banner(): void
    {
        $this->diskAt(85);
        $ceo = $this->user('CEO');
        $marketing = $this->user('Marketing');

        $this->actingAs($marketing)->get(self::URL)->assertNotFound();
        $this->assertStringNotContainsString('data-storage-banner', (string) $this->blade('<x-layout>body</x-layout>'));

        $this->actingAs($ceo)->post('/owner/nav-settings', $this->navSettingsPayload('Marketing', true))->assertRedirect();
        $this->assertTrue($this->visibility()['Marketing']);

        $this->actingAs($marketing)->get(self::URL)->assertOk()->assertSee('Server storage is at 85% (30.0 GB free).');
        $this->assertStringContainsString('data-storage-banner="amber"', (string) $this->blade('<x-layout>body</x-layout>'));
    }

    public function test_S2_S3_reset_in_nav_settings_also_drops_the_cached_answers(): void
    {
        $this->diskAt(85);
        $this->setStorageAccess('Marketing', true);
        $marketing = $this->user('Marketing');
        $this->actingAs($marketing)->get(self::URL)->assertOk()->assertSee('Server storage is at 85%');

        // Reset re-applies the default catalog; the Storage link is not in it, so its rows stay as they are.
        $this->setStorageAccess('Marketing', false);
        $this->actingAs($this->user('CEO'))->post('/owner/nav-settings/reset')->assertRedirect();

        $this->actingAs($marketing);
        $this->assertStringNotContainsString('data-storage-banner', (string) $this->blade('<x-layout>body</x-layout>'));
    }

    // S3: the banner follows the same check, cached

    public function test_S3_the_banner_shows_to_exactly_the_roles_that_may_open_the_view(): void
    {
        $this->diskAt(95);
        $this->setStorageAccess('Data Encoder - OIC', true);

        foreach (['CEO' => true, 'Marketing - OIC' => true, 'Data Encoder - OIC' => true, 'Marketing' => false, 'Data Encoder' => false] as $role => $sees) {
            $user = $this->user($role);
            $html = $this->page($role);
            $this->assertSame($sees, str_contains($html, 'data-storage-banner="red"'), "banner for {$role}");
            $this->actingAs($user)->get(self::URL)->assertStatus($sees ? 200 : 404);
        }
    }

    public function test_S3_under_80_percent_the_banner_runs_no_query_at_all(): void
    {
        $this->diskAt(79.9);
        $user = $this->user('Marketing - OIC')->load('employeeProfile');
        $queries = [];
        DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });

        $this->assertNull(StorageWarning::banner($user));
        $this->assertNull(StorageWarning::banner($user));

        $this->storage->disk = null; // a failed disk read too
        cache()->flush();
        $this->assertNull(StorageWarning::banner($user));

        $this->assertSame([], $queries);
    }

    public function test_S3_the_access_answer_is_cached_per_role_for_60_seconds(): void
    {
        $this->diskAt(85);
        $oic = $this->user('Marketing - OIC')->load('employeeProfile');
        $oic2 = $this->user('  Marketing  - OIC ')->load('employeeProfile');
        $marketing = $this->user('Marketing')->load('employeeProfile');
        $queries = [];
        DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });
        // By reference: an arrow function would copy $queries when it is made.
        $navQueries = function () use (&$queries) { return count(array_filter($queries, fn ($sql) => str_contains($sql, 'nav_link'))); };

        $this->assertNotNull(StorageWarning::banner($oic));
        $first = $navQueries();
        $this->assertGreaterThan(0, $first, 'the first view asks the nav settings');

        $this->assertNotNull(StorageWarning::banner($oic));
        $this->assertNotNull(StorageWarning::banner($oic2), 'the same role after normalizing spaces shares the answer');
        $this->assertSame($first, $navQueries(), 'no nav query for the same role within 60 seconds');

        $this->assertNull(StorageWarning::banner($marketing));
        $afterMarketing = $navQueries();
        $this->assertGreaterThan($first, $afterMarketing, 'another role is asked once');
        $this->assertNull(StorageWarning::banner($marketing));
        $this->assertSame($afterMarketing, $navQueries(), 'a "no" is cached too');

        $this->travel(61)->seconds();
        $this->assertNotNull(StorageWarning::banner($oic));
        $this->assertGreaterThan($afterMarketing, $navQueries(), 'asked again once the 60 seconds are over');
    }

    public function test_S3_without_the_nav_tables_only_the_ceo_sees_it(): void
    {
        $this->diskAt(85);
        Artisan::call('migrate:rollback', ['--path' => [self::NAV_MIGRATION, self::STORAGE_LINK_MIGRATION], '--force' => true]);

        $this->assertStringContainsString('data-storage-banner', $this->page('CEO'));
        $this->assertStringNotContainsString('data-storage-banner', $this->page('Marketing - OIC'));
    }
}
