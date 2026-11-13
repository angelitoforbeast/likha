<?php

namespace Tests\Feature\EncoderTimetable;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec 265 S2: the timetable opens for the roles checked on its nav link in /owner/nav-settings (NavAccess). The link
 * starts checked for CEO, Marketing and Marketing - OIC; the data encoders get 403 and guests go to the login page.
 */
class EncoderTimetableAccessTest extends EncoderTimetableTestCase
{
    private function link(): ?object
    {
        return DB::table('nav_links')->where('key', 'encoding_timetable')->first();
    }

    public function test_S2_1_the_migration_adds_the_link_checked_for_ceo_marketing_and_oic(): void
    {
        $link = $this->link();

        $this->assertNotNull($link);
        $this->assertSame('Encoding Timetable', $link->label);
        $this->assertSame('/encoder/timetable', $link->route_url);
        $this->assertSame('encoder/timetable*', $link->active_pattern);
        $this->assertTrue((bool) $link->is_active);
        $this->assertSame((int) DB::table('nav_links')->max('sort_order'), (int) $link->sort_order);

        $visibility = DB::table('nav_link_role_visibility')->where('nav_link_id', $link->id)
            ->pluck('is_visible', 'role')->map(fn ($v) => (bool) $v)->all();
        ksort($visibility);
        $this->assertSame(
            ['CEO' => true, 'Data Encoder' => false, 'Data Encoder - OIC' => false, 'Marketing' => true, 'Marketing - OIC' => true],
            $visibility,
        );
    }

    public function test_S2_1_the_migration_is_idempotent_and_down_removes_the_link(): void
    {
        $migration = require base_path(self::LINK_MIGRATION);
        $links = DB::table('nav_links')->count();

        $migration->up();
        $this->assertSame($links, DB::table('nav_links')->count());
        $this->assertSame(5, DB::table('nav_link_role_visibility')->where('nav_link_id', $this->link()->id)->count());

        $id = $this->link()->id;
        $migration->down();
        $this->assertNull($this->link());
        $this->assertSame(0, DB::table('nav_link_role_visibility')->where('nav_link_id', $id)->count());
        $this->assertSame($links - 1, DB::table('nav_links')->count());

        $migration->down(); // nothing left: no error
        $migration->up();
        $this->assertNotNull($this->link());
    }

    #[DataProvider('allowedRoles')]
    public function test_S2_1_allowed_roles_open_the_page_and_see_the_link(string $role): void
    {
        $response = $this->actingAs($this->user($role))->get(self::URL);

        $response->assertOk();
        $response->assertSee('Encoding Timetable');

        $menu = (string) $this->blade('<x-layout>body</x-layout>');
        $this->assertStringContainsString('/encoder/timetable', $menu);
    }

    public static function allowedRoles(): array
    {
        return ['CEO' => ['CEO'], 'Marketing' => ['Marketing'], 'Marketing - OIC' => ['Marketing - OIC']];
    }

    #[DataProvider('deniedRoles')]
    public function test_S2_2_data_encoders_get_403_and_no_link(?string $role): void
    {
        $this->actingAs($this->user($role))->get(self::URL)->assertForbidden();

        $this->assertStringNotContainsString('/encoder/timetable', (string) $this->blade('<x-layout>body</x-layout>'));
    }

    public static function deniedRoles(): array
    {
        return ['Data Encoder' => ['Data Encoder'], 'Data Encoder - OIC' => ['Data Encoder - OIC'], 'no role' => [null]];
    }

    public function test_S2_2_guests_are_sent_to_login(): void
    {
        $this->get(self::URL)->assertRedirect('/login');
    }

    public function test_S2_access_follows_the_nav_settings_checkbox(): void
    {
        $linkId = $this->link()->id;
        DB::table('nav_link_role_visibility')->where('nav_link_id', $linkId)->where('role', 'Marketing')->update(['is_visible' => false]);
        DB::table('nav_link_role_visibility')->where('nav_link_id', $linkId)->where('role', 'Data Encoder - OIC')->update(['is_visible' => true]);

        $this->actingAs($this->user('Marketing'))->get(self::URL)->assertForbidden();
        $this->actingAs($this->user('Data Encoder - OIC'))->get(self::URL)->assertOk();
    }
}
