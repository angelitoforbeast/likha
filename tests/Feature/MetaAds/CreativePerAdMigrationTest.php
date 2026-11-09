<?php

namespace Tests\Feature\MetaAds;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Spec 254: the campaign_id of ad_campaign_creatives is no longer unique (plain index); ad_id stays unique. */
class CreativePerAdMigrationTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_11_23_100000_allow_several_creatives_per_campaign.php';

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true, '--path' => [
            'database/migrations/2025_07_18_154633_create_ad_campaign_creatives_table.php',
            'database/migrations/2025_08_25_231634_add_ad_id_to_ad_campaign_creatives_table.php',
            self::MIGRATION,
        ]]);
    }

    private function insert(?string $ad, string $campaign): void
    {
        DB::table('ad_campaign_creatives')->insert(['ad_id' => $ad, 'campaign_id' => $campaign, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function indexes(): array
    {
        return collect(Schema::getIndexes('ad_campaign_creatives'))
            ->filter(fn ($i) => $i['columns'] === ['campaign_id'])
            ->map(fn ($i) => $i['unique'])->all();
    }

    public function test_two_ads_of_one_campaign_can_have_rows_and_campaign_id_keeps_a_plain_index(): void
    {
        $this->insert('a1', 'c1');
        $this->insert('a2', 'c1');

        $this->assertSame(2, DB::table('ad_campaign_creatives')->where('campaign_id', 'c1')->count());
        $this->assertSame([false], array_values($this->indexes()));
    }

    public function test_ad_id_stays_unique(): void
    {
        $this->insert('a1', 'c1');

        $this->expectException(QueryException::class);
        $this->insert('a1', 'c2');
    }

    public function test_down_restores_the_unique_index_when_there_are_no_duplicates(): void
    {
        $this->insert('a1', 'c1');
        $this->insert('a2', 'c2');

        Artisan::call('migrate:rollback', ['--force' => true, '--path' => [self::MIGRATION]]);

        $this->assertSame([true], array_values($this->indexes()));
    }

    public function test_down_refuses_with_a_clear_message_when_a_campaign_has_several_rows(): void
    {
        $this->insert('a1', 'c1');
        $this->insert('a2', 'c1');

        try {
            Artisan::call('migrate:rollback', ['--force' => true, '--path' => [self::MIGRATION]]);
            $this->fail('down() should refuse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('1 campaign', $e->getMessage());
            $this->assertStringContainsString('more than one row', $e->getMessage());
        }
        // Nothing changed: both rows are still there and the index is still plain.
        $this->assertSame(2, DB::table('ad_campaign_creatives')->count());
        $this->assertSame([false], array_values($this->indexes()));
    }
}
