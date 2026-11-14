<?php

namespace Tests\Feature\MetaAds;

use App\Jobs\CompareMetaAds;
use App\Models\UploadLog;
use App\Services\MetaAds\DataHealth;
use App\Services\MetaAds\Sync;

/**
 * Ang "Upload Logs (latest 50)" table ng /ads_manager/report: ang mga upload at ang mga Meta API sync sa iisang table,
 * pinakabago muna, 50 lang lahat. May "Meta API" badge ang API row at "—" ang file dates nito (walang file).
 */
class AdsReportLogsMetaApiTest extends MetaAdsTestCase
{
    private function uploadLog(array $extra = []): UploadLog
    {
        return UploadLog::create($extra + [
            'type' => 'ads_manager_reports', 'disk' => 'local', 'path' => 'uploads/ads_manager_reports/x.xlsx',
            'original_name' => 'report.xlsx', 'status' => 'done', 'processed_rows' => 10, 'inserted' => 4, 'updated' => 6,
            'skipped' => 0, 'error_rows' => 0, 'started_at' => '2026-11-14 09:00:00', 'finished_at' => '2026-11-14 09:01:00',
            'file_created_at' => '2026-11-14 00:30:00', 'file_modified_at' => '2026-11-14 00:45:00',
            'protected_details' => ['impressions' => 2],
        ]);
    }

    private function apiLog(array $extra = []): UploadLog
    {
        return UploadLog::create($extra + [
            'type' => Sync::LOG_TYPE, 'disk' => 'api', 'path' => 'meta', 'original_name' => 'Meta API 2026-11-09 to 2026-11-15',
            'status' => 'done', 'processed_rows' => 7, 'inserted' => 3, 'updated' => 4, 'skipped' => 1, 'error_rows' => 0,
            'error_message' => 'No page name, skipped: ad 9 (page 8)',
            'started_at' => '2026-11-15 09:00:00', 'finished_at' => '2026-11-15 09:02:00',
        ]);
    }

    /** Ang file date gaya ng pagpapakita ng page (Asia/Manila mula sa timezone ng app). */
    private static function shown(string $at): string
    {
        return \Carbon\Carbon::parse($at)->setTimezone('Asia/Manila')->format('M j, Y g:i A');
    }

    /** Ang logs table lang (hindi ang Meta panel sa itaas, na may sariling listahan ng API runs). */
    private function table(string $html): string
    {
        $start = strpos($html, 'Upload Logs (latest 50)');
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, '</table>', $start) - $start);
    }

    /** @return array<int, string> log id => ang <tr> nito, sa ayos ng table */
    private function rows(string $table): array
    {
        preg_match_all('#<tr class="border-t" data-log-id="(\d+)".*?</tr>#s', $table, $m, PREG_SET_ORDER);

        return collect($m)->mapWithKeys(fn ($x) => [(int) $x[1] => $x[0]])->all();
    }

    public function test_an_api_sync_and_an_upload_both_show_in_the_table_and_the_api_one_is_marked(): void
    {
        $upload = $this->uploadLog();
        $api    = $this->apiLog();
        // Isang tinanggihang row ng Sync run: lumalabas din sa API row.
        UploadLog::create(['type' => DataHealth::REFUSED_TYPE, 'disk' => 'api', 'path' => 'log:' . $api->id,
            'original_name' => 'refused', 'status' => 'done', 'processed_rows' => 1, 'error_rows' => 1,
            'protected_details' => ['source' => 'sync', 'log_id' => $api->id, 'count' => 1, 'rows' => []]]);
        // Hindi kasama sa table ang compare at event logs.
        UploadLog::create(['type' => CompareMetaAds::LOG_TYPE, 'disk' => 'api', 'path' => 'meta', 'original_name' => 'Compare X', 'status' => 'done']);

        $rows = $this->rows($this->table($this->actingAs($this->user('CEO'))->get('/ads_manager/report')->assertOk()->getContent()));

        $this->assertSame([$api->id, $upload->id], array_keys($rows), 'newest first, only the upload and the API sync');

        $apiRow = $rows[$api->id];
        $this->assertStringContainsString('data-source="api"', $apiRow);
        $this->assertStringContainsString('Meta API 2026-11-09 to 2026-11-15', $apiRow);
        $this->assertMatchesRegularExpression('#data-col="source_badge"[^>]*>Meta API</span>#', $apiRow);
        $this->assertMatchesRegularExpression('#data-col="file_created_at">\s*—\s*</td>#u', $apiRow);
        $this->assertMatchesRegularExpression('#data-col="file_modified_at">\s*—\s*</td>#u', $apiRow);
        $this->assertStringContainsString('DONE', $apiRow);
        foreach (['processed_rows' => 7, 'inserted' => 3, 'updated' => 4, 'skipped' => 1, 'error_rows' => 0] as $col => $n) {
            $this->assertStringContainsString('data-col="' . $col . '">' . $n . '</td>', $apiRow);
        }
        $this->assertStringContainsString('No page name, skipped: ad 9 (page 8)', $apiRow);
        $this->assertStringContainsString('2026-11-15 09:00:00', $apiRow);
        $this->assertStringContainsString('2026-11-15 09:02:00', $apiRow);
        $this->assertStringContainsString('data-refused="1"', $apiRow);

        $uploadRow = $rows[$upload->id];
        $this->assertStringNotContainsString('data-source', $uploadRow);
        $this->assertStringNotContainsString('source_badge', $uploadRow);
        $this->assertStringContainsString('data-col="original_name">report.xlsx</td>', $uploadRow);
        $this->assertStringContainsString(self::shown('2026-11-14 00:30:00'), $uploadRow);
        $this->assertStringContainsString(self::shown('2026-11-14 00:45:00'), $uploadRow);
        $this->assertStringContainsString('⚠ 2 protected', $uploadRow);

        $logs = $this->getJson('/ads_manager/report/status')->assertOk()->json('logs');
        $this->assertSame([$api->id, $upload->id], array_column($logs, 'id'));
        $this->assertSame(['api', 'upload'], array_column($logs, 'source'));
        $this->assertSame(['Meta API 2026-11-09 to 2026-11-15', 3, 1, null, null, null],
            [$logs[0]['original_name'], $logs[0]['inserted'], $logs[0]['skipped'], $logs[0]['file_created_at'], $logs[0]['file_modified_at'], $logs[0]['protected_details']]);
        $this->assertSame(['report.xlsx', self::shown('2026-11-14 00:30:00'), self::shown('2026-11-14 00:45:00'), ['impressions' => 2]],
            [$logs[1]['original_name'], $logs[1]['file_created_at'], $logs[1]['file_modified_at'], $logs[1]['protected_details']]);
    }

    public function test_the_50_limit_counts_uploads_and_api_syncs_together(): void
    {
        $ids = [];
        for ($i = 0; $i < 30; $i++) {
            $ids[] = $this->uploadLog(['original_name' => "report-{$i}.xlsx"])->id;
            $ids[] = $this->apiLog(['original_name' => "Meta API run {$i}"])->id;
        }
        $newest50 = array_slice(array_reverse($ids), 0, 50);

        $rows = $this->rows($this->table($this->actingAs($this->user('CEO'))->get('/ads_manager/report')->assertOk()->getContent()));
        $this->assertSame($newest50, array_keys($rows));
        $this->assertCount(25, array_filter($rows, fn ($tr) => str_contains($tr, 'data-source="api"')));

        $logs = $this->getJson('/ads_manager/report/status')->assertOk()->json('logs');
        $this->assertSame($newest50, array_column($logs, 'id'));
        $this->assertSame(['api' => 25, 'upload' => 25], array_count_values(array_column($logs, 'source')));
    }
}
