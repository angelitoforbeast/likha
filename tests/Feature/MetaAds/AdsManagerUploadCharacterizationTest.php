<?php

namespace Tests\Feature\MetaAds;

use App\Jobs\ProcessAdsManagerReportsUpload;
use App\Models\UploadLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Ang XLSX/CSV upload na kapareho ng upsert ng Meta API sync: ang bagong row ay ini-insert, at ang mas mababang spend
 * sa susunod na upload ay tinatanggihan para sa buong row (protected, hindi updated). Ang starts/ends ay hindi pinupuno ng upload.
 */
class AdsManagerUploadCharacterizationTest extends MetaAdsTestCase
{
    private function upload(string $spend): UploadLog
    {
        Storage::fake('local');
        $path = 'uploads/ads_manager_reports/t.xlsx';
        Storage::disk('local')->makeDirectory('uploads/ads_manager_reports');
        $writer = new \OpenSpout\Writer\XLSX\Writer();
        $writer->openToFile(Storage::disk('local')->path($path));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Day', 'Ad ID', 'Account ID', 'Campaign ID', 'Ad set ID', 'Page name', 'Campaign name',
            'Ad set name', 'Amount spent (PHP)', 'Impressions', 'Results', 'Result type', 'Link clicks']));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['2026-10-20', 'a1', '111', 'c1', 'as1', 'Page P1', 'Camp', 'Adset', $spend, '1500', '12',
            'Messaging conversations started', '33']));
        $writer->close();
        $log = UploadLog::create(['type' => 'ads_manager_reports', 'disk' => 'local', 'path' => $path, 'original_name' => 't.xlsx', 'status' => 'queued']);
        (new ProcessAdsManagerReportsUpload($path, null, $log->id))->handle();

        return $log->fresh();
    }

    public function test_the_upload_inserts_then_keeps_the_higher_spend_and_leaves_starts_empty(): void
    {
        $first = $this->upload('100.50');
        $second = $this->upload('90');

        $this->assertSame(['done', 1, 0], [$first->status, (int) $first->inserted, (int) $first->updated]);
        $this->assertSame(['done', 0, 0, ['amount_spent_php' => 1]], [$second->status, (int) $second->inserted, (int) $second->updated, $second->protected_details]);
        $row = DB::table('ads_manager_reports')->first();
        $this->assertSame([100.5, 'Page P1', null, null], [(float) $row->amount_spent_php, $row->page_name, $row->starts, $row->ends]);
    }
}
