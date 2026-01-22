<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PancakeRetrieve2Controller extends Controller
{
    public function index(Request $request)
    {
        $tz = config('app.timezone', 'Asia/Manila');

        // Filters
        $pageSearch = trim((string) $request->query('page', ''));
        $nameSearch = trim((string) $request->query('name', ''));

        // Presets: last7 | yesterday | today | month
        $preset = trim((string) $request->query('preset', ''));

        // Dates: by default = yesterday (if user did not specify date_from/date_to AND no preset)
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo   = trim((string) $request->query('date_to', ''));

        $now = Carbon::now($tz);

        if ($preset !== '') {
            if ($preset === 'today') {
                $dateFrom = $now->toDateString();
                $dateTo   = $now->toDateString();
            } elseif ($preset === 'yesterday') {
                $y = $now->copy()->subDay()->toDateString();
                $dateFrom = $y;
                $dateTo   = $y;
            } elseif ($preset === 'last7') {
                $dateFrom = $now->copy()->subDays(6)->toDateString(); // inclusive 7 days (today + 6 back)
                $dateTo   = $now->toDateString();
            } elseif ($preset === 'month') {
                $dateFrom = $now->copy()->startOfMonth()->toDateString();
                $dateTo   = $now->toDateString();
            }
        }

        // Default to yesterday if still empty
        if ($dateFrom === '' && $dateTo === '') {
            $y = $now->copy()->subDay()->toDateString();
            $dateFrom = $y;
            $dateTo   = $y;
            $preset   = 'yesterday';
        }

        // If only one is set, mirror it
        if ($dateFrom !== '' && $dateTo === '') $dateTo = $dateFrom;
        if ($dateTo !== '' && $dateFrom === '') $dateFrom = $dateTo;

        /**
         * Macro pairs: distinct (PAGE + fb_name) normalized
         * NOTE: macro_output has column name PAGE (no spaces) so backticks are safe in MySQL.
         */
        $macroPairs = DB::table('macro_output')
            ->selectRaw("LOWER(TRIM(`PAGE`)) as page_key, LOWER(TRIM(`fb_name`)) as name_key")
            ->whereNotNull('PAGE')
            ->whereNotNull('fb_name')
            ->whereRaw("TRIM(`PAGE`) <> ''")
            ->whereRaw("TRIM(`fb_name`) <> ''")
            ->distinct();

        /**
         * Main query:
         * pc (pancake_conversations)
         * join pi (pancake_id) to translate page_id -> page_name
         * left join macroPairs by normalized (page_name + full_name)
         * keep rows where macro pair doesn't exist (missing in macro_output)
         */
        $q = DB::table('pancake_conversations as pc')
            ->leftJoin('pancake_id as pi', 'pi.pancake_page_id', '=', 'pc.pancake_page_id')
            ->leftJoinSub($macroPairs, 'mo', function ($join) {
                $join->on(DB::raw('LOWER(TRIM(pi.pancake_page_name))'), '=', 'mo.page_key')
                     ->on(DB::raw('LOWER(TRIM(pc.full_name))'), '=', 'mo.name_key');
            })
            ->whereNull('mo.page_key')
            ->select([
                'pc.created_at as date_created',
                'pi.pancake_page_name as page',
                'pc.full_name',
                'pc.customers_chat',
            ])
            ->orderByDesc('pc.created_at');

        // Date filter (based on pancake_conversations.created_at)
        if ($dateFrom !== '' && $dateTo !== '') {
            $q->whereDate('pc.created_at', '>=', $dateFrom)
              ->whereDate('pc.created_at', '<=', $dateTo);
        }

        // Optional text filters
        if ($pageSearch !== '') {
            $q->whereRaw('pi.pancake_page_name LIKE ?', ['%' . $pageSearch . '%']);
        }
        if ($nameSearch !== '') {
            $q->whereRaw('pc.full_name LIKE ?', ['%' . $nameSearch . '%']);
        }

        $rows = $q->paginate(100)->withQueryString();

        return view('pancake.retrieve2', [
            'rows'       => $rows,
            'pageSearch' => $pageSearch,
            'nameSearch' => $nameSearch,
            'dateFrom'   => $dateFrom,
            'dateTo'     => $dateTo,
            'preset'     => $preset,
            'tz'         => $tz,
        ]);
    }
}
