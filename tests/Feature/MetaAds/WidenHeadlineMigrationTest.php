<?php

namespace Tests\Feature\MetaAds;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Spec 264: the migration that widens ads_manager_reports.headline to varchar(1000). On MySQL it is a raw in-place
 * ALTER (not run here); on the test driver it uses the schema builder, keeps the column nullable and the rows.
 */
class WidenHeadlineMigrationTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_11_12_110000_widen_ads_manager_reports_headline.php';

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true, '--path' => [
            'database/migrations/2025_07_15_232022_create_ads_manager_reports_table.php',
            'database/migrations/2025_07_17_222429_add_headline_and_body_to_ads_manager_reports_table.php',
        ]]);
        DB::table('ads_manager_reports')->insert(['day' => '2026-11-11', 'page_name' => 'P1', 'ad_id' => 'a1', 'headline' => 'Before']);
        Artisan::call('migrate', ['--force' => true, '--path' => [self::MIGRATION]]);
    }

    private function headlineColumn(): array
    {
        return collect(Schema::getColumns('ads_manager_reports'))->firstWhere('name', 'headline');
    }

    public function test_up_keeps_the_rows_and_the_column_nullable_and_takes_a_1000_character_headline(): void
    {
        $this->assertSame('Before', DB::table('ads_manager_reports')->where('ad_id', 'a1')->value('headline'));
        $this->assertTrue($this->headlineColumn()['nullable']);

        $h = str_repeat('😍', 1000);
        DB::table('ads_manager_reports')->insert(['day' => '2026-11-11', 'page_name' => 'P1', 'ad_id' => 'a2', 'headline' => $h]);
        DB::table('ads_manager_reports')->insert(['day' => '2026-11-11', 'page_name' => 'P1', 'ad_id' => 'a3', 'headline' => null]);

        $this->assertSame($h, DB::table('ads_manager_reports')->where('ad_id', 'a2')->value('headline'));
        $this->assertNull(DB::table('ads_manager_reports')->where('ad_id', 'a3')->value('headline'));
    }

    public function test_down_runs_and_keeps_the_column(): void
    {
        Artisan::call('migrate:rollback', ['--force' => true, '--path' => [self::MIGRATION]]);

        $this->assertTrue($this->headlineColumn()['nullable']);
        $this->assertSame('Before', DB::table('ads_manager_reports')->where('ad_id', 'a1')->value('headline'));
    }
}
