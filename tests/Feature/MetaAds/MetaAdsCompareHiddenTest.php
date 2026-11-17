<?php

namespace Tests\Feature\MetaAds;

use App\Jobs\CompareMetaAds;
use App\Models\UploadLog;
use App\Services\MetaAds\Sync;

/**
 * Compare with uploads is hidden from /ads_manager/report: the API sync is the main source now. The route, job and
 * data stay (MetaAdsCompareTest still covers them), so only the page is checked here.
 */
class MetaAdsCompareHiddenTest extends MetaAdsTestCase
{
    public function test_S_285_the_report_page_shows_sync_now_but_no_compare_even_with_a_finished_compare(): void
    {
        Sync::setWriteMode(true);
        Sync::setAccounts(['111']);
        $this->connect($this->user('Marketing', 'ana@example.test'), self::TOKEN_ANA, ['111']);
        UploadLog::create([
            'type' => CompareMetaAds::LOG_TYPE, 'disk' => 'api', 'path' => 'meta', 'original_name' => 'Compare', 'status' => 'done',
            'protected_details' => ['from' => '2026-10-20', 'to' => '2026-10-20', 'problems' => [], 'accounts' => [
                ['account_id' => '111', 'name' => 'Account 111', 'who' => 'x', 'api_rows' => 7, 'upload_rows' => 6, 'matched' => 6,
                    'api_spend' => 1234.5, 'upload_spend' => 1200.25, 'only_api_count' => 1, 'only_upload_count' => 0, 'differ_count' => 0,
                    'only_api' => [], 'only_upload' => [], 'differ' => []],
            ]],
        ]);
        $this->actingAs($this->user('CEO', 'ceo@example.test'));

        $this->get('/ads_manager/report')->assertOk()
            ->assertSee('Sync now')
            ->assertSee('name="accounts[]" value="111"', false)
            ->assertSee('Ad Accounts: balance, spend cap, payment')
            ->assertDontSee('Compare with uploads')
            ->assertDontSee('API vs uploads')
            ->assertDontSee(route('ads_manager.meta.compare'), false)
            ->assertDontSee('Wala pa sa huling Compare.');
    }
}
