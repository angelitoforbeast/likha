<?php

namespace Tests\Feature\MetaAds;

use App\Jobs\ProcessAdsManagerReportsUpload;
use App\Models\UploadLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Spec 264: an Ads Manager upload with a long headline imports (headline is varchar(1000), the importer cuts it to
 * 1000 characters, and ad_catalog.ad_name to its 500), and a failed upload stores one short line in error_message.
 *
 * sqlite does not enforce varchar lengths, so triggers stand in for MySQL's strict "Data too long" (1406) on the
 * live columns: headline 255 before the migration, 1000 after it, ad_catalog.ad_name 500.
 */
class AdsUploadLongHeadlineTest extends MetaAdsTestCase
{
    private const HEADER = ['Day', 'Ad ID', 'Account ID', 'Campaign ID', 'Ad set ID', 'Page name', 'Campaign name', 'Ad set name',
        'Amount spent (PHP)', 'Impressions', 'Results', 'Result type', 'Link clicks', '3-second video plays', 'ThruPlays',
        'Video plays at 50%', 'Headline'];

    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), ['database/migrations/2026_05_22_140000_create_ad_catalog_table.php']);
    }

    /** Like MySQL strict mode: a value longer than the column's length fails the statement. */
    private function limit(string $table, string $column, int $max): void
    {
        foreach (['INSERT', 'UPDATE'] as $op) {
            DB::statement("CREATE TRIGGER limit_{$table}_{$column}_" . strtolower($op) . " BEFORE {$op} ON {$table}
                WHEN length(NEW.{$column}) > {$max}
                BEGIN SELECT RAISE(ABORT, 'Data too long for column ''{$column}'' at row 1'); END");
        }
    }

    /** The live columns after this spec's migration. */
    private function liveLimits(): void
    {
        $this->limit('ads_manager_reports', 'headline', 1000);
        $this->limit('ad_catalog', 'ad_name', 500);
    }

    private function row(string $adId, ?string $headline): array
    {
        return ['2026-11-11', $adId, '111', 'c1', 'as1', 'Page P1', 'Francesca Lim - Eloria Cream 299 - 11.11.26 - ( 113 )', 'Adset',
            '100.50', '1500', '12', 'Messaging conversations started', '33', '', '', '', $headline ?? ''];
    }

    /** @param list<array> $rows */
    private function upload(array $rows, ?\Throwable &$thrown = null): UploadLog
    {
        Storage::fake('local');
        $path = 'uploads/ads_manager_reports/t.xlsx';
        Storage::disk('local')->makeDirectory('uploads/ads_manager_reports');
        $writer = new \OpenSpout\Writer\XLSX\Writer();
        $writer->openToFile(Storage::disk('local')->path($path));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(self::HEADER));
        foreach ($rows as $r) {
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($r));
        }
        $writer->close();
        $log = UploadLog::create(['type' => 'ads_manager_reports', 'disk' => 'local', 'path' => $path, 'original_name' => 't.xlsx', 'status' => 'queued']);

        $thrown = null;
        try {
            (new ProcessAdsManagerReportsUpload($path, null, $log->id))->handle();
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        return $log->fresh();
    }

    /** A headline of exactly $chars characters with emoji and other multibyte text. */
    private function headline(int $chars): string
    {
        $h = '✨ Eloria Cream 299 – pampakinis ng mukha 💯 Ñ ';
        while (mb_strlen($h) < $chars) {
            $h .= 'Sulit na sulit! 😍 Libreng shipping 🚚 ngayong 11.11 – ';
        }

        return mb_substr($h, 0, $chars);
    }

    public function test_S1_1_a_481_character_headline_with_emoji_imports_exactly(): void
    {
        $this->liveLimits();
        $h = $this->headline(481);

        $log = $this->upload([$this->row('a1', $h)], $thrown);

        $this->assertNull($thrown);
        $this->assertSame(['done', 1, 1, 0], [$log->status, (int) $log->processed_rows, (int) $log->inserted, (int) $log->updated]);
        $this->assertSame($h, DB::table('ads_manager_reports')->where('ad_id', 'a1')->value('headline'));
        $this->assertSame($h, DB::table('ad_campaign_creatives')->where('ad_id', 'a1')->value('headline'));
        $this->assertSame($h, DB::table('ad_catalog')->where('ad_id', 'a1')->value('ad_name'));
    }

    public function test_S1_2_a_headline_over_1000_characters_is_stored_cut_to_1000_and_the_upload_is_done(): void
    {
        $this->liveLimits();
        $h = $this->headline(1300);

        $log = $this->upload([$this->row('a1', $h)], $thrown);

        $this->assertNull($thrown);
        $this->assertSame(['done', 1, 1], [$log->status, (int) $log->processed_rows, (int) $log->inserted]);
        $stored = DB::table('ads_manager_reports')->where('ad_id', 'a1')->value('headline');
        $this->assertSame(mb_substr($h, 0, 1000), $stored);
        $this->assertSame(1000, mb_strlen($stored));
        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'));
        $this->assertSame(mb_substr($h, 0, 1000), DB::table('ad_campaign_creatives')->where('ad_id', 'a1')->value('headline'));
        // ad_catalog.ad_name is varchar(500): the label is cut to its length, not failed.
        $this->assertSame(mb_substr($h, 0, 500), DB::table('ad_catalog')->where('ad_id', 'a1')->value('ad_name'));
    }

    public function test_S1_3_short_headlines_import_and_update_exactly_as_before(): void
    {
        $this->liveLimits();

        $first = $this->upload([$this->row('a1', 'Short one'), $this->row('a2', 'Short two'), $this->row('', 'no ad id')]);
        $second = $this->upload([$this->row('a1', 'Short one'), $this->row('a2', 'Short two, edited')]);

        $this->assertSame(['done', 2, 2, 0, 1], [$first->status, (int) $first->processed_rows, (int) $first->inserted, (int) $first->updated, (int) $first->skipped]);
        $this->assertSame(['done', 2, 0, 2, 0], [$second->status, (int) $second->processed_rows, (int) $second->inserted, (int) $second->updated, (int) $second->skipped]);
        $this->assertSame(['a1' => 'Short one', 'a2' => 'Short two, edited'],
            DB::table('ads_manager_reports')->orderBy('ad_id')->pluck('headline', 'ad_id')->all());
        $this->assertNull($second->error_message);
    }

    public function test_S2_1_a_failed_upload_stores_why_in_one_short_line_without_the_sql_or_the_ad_text(): void
    {
        // The live column before the migration: varchar(255).
        $this->limit('ads_manager_reports', 'headline', 255);
        $h = $this->headline(481);

        $log = $this->upload([$this->row('a1', $h)], $thrown);

        $this->assertNotNull($thrown, 'the exception is still rethrown (failed_jobs keeps the trace)');
        $this->assertStringContainsString('(Connection:', $thrown->getMessage());
        $this->assertSame('failed', $log->status);
        $this->assertStringStartsWith('Import failed: SQLSTATE[', $log->error_message);
        $this->assertStringContainsString("Data too long for column 'headline' at row 1", $log->error_message);
        $this->assertStringNotContainsString('(Connection:', $log->error_message);
        $this->assertStringNotContainsString('SQL:', $log->error_message);
        $this->assertStringNotContainsString('Eloria', $log->error_message);
        $this->assertStringNotContainsString("\n", $log->error_message);
    }

    public function test_S2_1_the_message_is_capped_to_the_text_column_and_stays_valid_utf8(): void
    {
        $m = new \ReflectionMethod(ProcessAdsManagerReportsUpload::class, 'failureMessage');

        $long = $m->invoke(null, new \RuntimeException(str_repeat('ñ', 40000)));
        $this->assertLessThanOrEqual(65535, strlen($long));
        $this->assertTrue(mb_check_encoding($long, 'UTF-8'));
        $this->assertStringStartsWith('Import failed: ñ', $long);

        $this->assertSame('Import failed: first line second line', $m->invoke(null, new \RuntimeException("first line\nsecond line")));
        $this->assertSame('Import failed: RuntimeException', $m->invoke(null, new \RuntimeException('')));
    }

    public function test_S2_2_the_counts_are_saved_and_the_exception_is_rethrown(): void
    {
        $this->limit('ads_manager_reports', 'headline', 255);
        // A skipped row, a full first chunk (2000 rows, saved), then a second chunk whose row fails.
        $rows = [$this->row('', 'no ad id')];
        for ($i = 1; $i <= 2000; $i++) {
            $rows[] = $this->row("ok{$i}", 'Short');
        }
        $rows[] = $this->row('bad', $this->headline(481));

        $log = $this->upload($rows, $thrown);

        $this->assertInstanceOf(\Illuminate\Database\QueryException::class, $thrown);
        $this->assertSame(['failed', 2001, 2000, 0, 1],
            [$log->status, (int) $log->processed_rows, (int) $log->inserted, (int) $log->updated, (int) $log->skipped]);
        $this->assertNotEmpty($log->error_message);
        $this->assertNotNull($log->finished_at);
        $this->assertSame(2000, DB::table('ads_manager_reports')->count());
        $this->assertSame(0, DB::table('ads_manager_reports')->where('ad_id', 'bad')->count());
    }
}
