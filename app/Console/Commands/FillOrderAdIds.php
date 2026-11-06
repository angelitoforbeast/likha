<?php

namespace App\Console\Commands;

use App\Models\MacroOutput;
use App\Services\BotcakeCustomerMatcher;
use App\Services\PageSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Isang beses: pinupunan ang botcake_ad_id ng mga order ng isang araw na may PSID pero walang ad id, sa parehong patakaran
 * ng gabi-gabing matcher (BotcakeCustomerMatcher::fillAds): ang ad ng parehong page at PSID, at ang may ad id na ay hindi
 * kailanman pinapalitan. Kada 1000 ayon sa id. Bilang lang ang ipinapakita. --dry-run: walang isinusulat.
 */
class FillOrderAdIds extends Command
{
    protected $signature = 'orders:fill-ad-id
        {--date= : The order day (ts_date), YYYY-MM-DD; default today in Manila}
        {--page= : Only this page}
        {--dry-run : Count only, write nothing}';
    protected $description = 'Fill botcake_ad_id of the orders of a day that have a PSID and no ad id, from botcake_psids (never replaces an ad id)';

    public function handle(BotcakeCustomerMatcher $matcher, PageSettings $settings): int
    {
        $date = trim((string) $this->option('date')) ?: now('Asia/Manila')->toDateString();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('--date must be YYYY-MM-DD.');

            return self::FAILURE;
        }
        if (!Schema::hasColumn('macro_output', 'botcake_ad_id') || !Schema::hasTable('botcake_psids')) {
            $this->error('macro_output.botcake_ad_id or botcake_psids is missing: run the migrations first.');

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');
        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        $pageCol = DB::getQueryGrammar()->wrap('PAGE');
        $day = fn () => MacroOutput::query()->where('ts_date', '>=', $date)->where('ts_date', '<=', $date . ' 23:59:59')
            ->whereNotNull('botcake_psid')->where('botcake_psid', '<>', '')->whereRaw(BotcakeCustomerMatcher::EMPTY_AD);

        $only = trim((string) $this->option('page'));
        $pages = $only !== '' ? [$only] : $day()->distinct()->pluck('PAGE')->filter(fn ($p) => trim((string) $p) !== '')->all();

        $checked = $filled = 0;
        $seen = [];
        foreach ($pages as $page) {
            $key = $settings->pageKey((string) $page);
            if (isset($seen[$key])) continue; // iisang page sa ibang anyo (case, espasyo): isang beses lang
            $seen[$key] = true;
            $pageId = $matcher->adPageId((string) $page);
            $day()->whereRaw("LOWER($trim($pageCol)) = ?", [$key])->select('id')
                ->chunkById(1000, function ($orders) use (&$checked, &$filled, $matcher, $page, $pageId, $dry) {
                    $checked += $orders->count();
                    $filled += $matcher->fillAds((string) $page, $pageId, $orders->pluck('id')->all(), $dry);
                }, 'id');
        }

        $this->info(sprintf('%s%s: checked %d, filled %d, no ad found %d', $date, $dry ? ' (dry run: nothing written)' : '', $checked, $filled, $checked - $filled));

        return self::SUCCESS;
    }
}
