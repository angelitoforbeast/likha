<?php

namespace Tests\Feature\Nav;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * NV-1: the migration gives every nav link its section by route_url, unknown links go to More, and nobody's links
 * change. The nav migrations and the hand-made production rows run first; this test runs the section migration itself.
 */
class NavSectionMigrationTest extends NavTestCase
{
    protected function runsSectionsMigration(): bool
    {
        return false;
    }

    public static function inventory(): array
    {
        $cases = [];
        foreach (self::INVENTORY as $url => $section) $cases[$url] = [$url, $section];

        return $cases;
    }

    #[DataProvider('inventory')]
    public function test_NV1_every_listed_url_lands_in_its_section(string $url, string $section): void
    {
        $this->assertNotSame(0, $this->linkId($url), "no row for $url in the test database");
        $this->migrateSections();

        $this->assertSame($section, DB::table('nav_links')->where('route_url', $url)->value('section'));
    }

    public function test_NV1_an_unknown_link_lands_in_More(): void
    {
        $this->migrateSections();

        $this->assertSame('More', DB::table('nav_links')->where('route_url', '/profit-calculator')->value('section'));
    }

    public function test_NV1_a_url_stored_without_its_leading_slash_still_finds_its_section(): void
    {
        DB::table('nav_links')->where('route_url', '/astra')->update(['route_url' => 'astra']);
        $this->migrateSections();

        $this->assertSame('AI', DB::table('nav_links')->where('route_url', 'astra')->value('section'));
    }

    public function test_NV1_nothing_visible_before_is_hidden_after(): void
    {
        $before = $this->visibleUrls();
        $this->migrateSections();

        $this->assertSame($before, $this->visibleUrls());
        $this->assertSame(0, DB::table('nav_links')->whereNull('section')->count());
    }

    public function test_NV1_NV4_columns_are_added_once_and_the_migration_is_reversible(): void
    {
        $this->migrateSections();
        $this->assertTrue(Schema::hasColumn('nav_links', 'section'));
        $this->assertTrue(Schema::hasColumn('users', 'nav_pins'));

        // Running up() again on a database that has the columns does nothing and fails nothing.
        $migration = require base_path(self::SECTIONS_MIGRATION);
        DB::table('nav_links')->where('route_url', '/astra')->update(['section' => 'Money']);
        $migration->up();
        $this->assertSame('Money', DB::table('nav_links')->where('route_url', '/astra')->value('section'), 'a section the CEO chose is kept');

        Artisan::call('migrate:rollback', ['--path' => [self::SECTIONS_MIGRATION], '--force' => true]);
        $this->assertFalse(Schema::hasColumn('nav_links', 'section'));
        $this->assertFalse(Schema::hasColumn('users', 'nav_pins'));
    }

    public function test_NV1_a_link_added_later_without_a_section_is_still_placed_by_its_url(): void
    {
        $this->migrateSections();
        DB::table('nav_links')->where('route_url', '/checklist/ai-followups')->update(['section' => null]);

        $link = \App\Models\NavLink::where('route_url', '/checklist/ai-followups')->first();
        $this->assertSame('AI', \App\Support\NavSections::of($link));
        $link->section = 'Nonsense';
        $this->assertSame('AI', \App\Support\NavSections::of($link), 'an unknown stored value falls back to the url');
        $link->route_url = '/brand-new-page';
        $this->assertSame('More', \App\Support\NavSections::of($link));
    }
}
