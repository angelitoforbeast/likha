<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdCampaignCreativeController extends Controller
{
    private array $allowedSortColumns = [
        'campaign_name','page_name','body_ad_settings','headline','welcome_message',
        'quick_reply_1','quick_reply_2','quick_reply_3','ad_set_delivery','ad_link',
        'date_created','feedback',
    ];

    private string $accTable;

    public function __construct()
    {
        $this->accTable = 'ad_campaign_creatives';
    }

    public function editMessagingTemplate(Request $request)
    {
        $sortBy        = $this->sanitizeSortColumn($request->get('sort_by', 'date_created'));
        $sortDirection = strtolower($request->get('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage       = max(10, min((int)$request->get('per_page', 50), 200));

        $q         = trim((string)$request->get('q', ''));
        $pageName  = $request->get('page_name');
        if ($pageName === 'all') $pageName = null;
        $delivery  = $request->get('ad_set_delivery'); // 'Active' | 'Inactive' | null

        // earliest day with spend > 0
        $sub = DB::table('ads_manager_reports')
            ->select('campaign_id', DB::raw('MIN(day) as date_created'))
            ->whereNotNull('amount_spent_php')
            ->where('amount_spent_php', '>', 0)
            ->groupBy('campaign_id');

        $query = DB::table($this->accTable.' as acc')
            ->leftJoinSub($sub, 'sub', function ($join) {
                $join->on('acc.campaign_id', '=', 'sub.campaign_id');
            })
            ->select([
                'acc.id','acc.campaign_id','acc.campaign_name','acc.page_name','acc.body_ad_settings',
                'acc.headline','acc.welcome_message','acc.quick_reply_1','acc.quick_reply_2','acc.quick_reply_3',
                'acc.ad_set_delivery','acc.ad_link','acc.feedback',
                DB::raw('sub.date_created'),
            ]);

        if (!empty($pageName)) {
            $query->where('acc.page_name', $pageName);
        }

        if ($delivery === 'Active' || $delivery === 'Inactive') {
            $query->whereRaw('LOWER(COALESCE(acc.ad_set_delivery,"")) = ?', [strtolower($delivery)]);
        }

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $like = "%{$q}%";
                $w->where('acc.campaign_name', 'like', $like)
                  ->orWhere('acc.page_name', 'like', $like)
                  ->orWhere('acc.body_ad_settings', 'like', $like)
                  ->orWhere('acc.headline', 'like', $like)
                  ->orWhere('acc.welcome_message', 'like', $like)
                  ->orWhere('acc.quick_reply_1', 'like', $like)
                  ->orWhere('acc.quick_reply_2', 'like', $like)
                  ->orWhere('acc.quick_reply_3', 'like', $like)
                  ->orWhere('acc.ad_link', 'like', $like);
            });
        }

        // Always place Active first
        $query->orderByRaw("CASE WHEN LOWER(COALESCE(acc.ad_set_delivery,'')) = 'active' THEN 0 ELSE 1 END");

        // Then the selected sort
        if ($sortBy === 'date_created') {
            $query->orderBy('sub.date_created', $sortDirection);
        } else {
            $query->orderBy("acc.$sortBy", $sortDirection);
        }

        $creatives = $query->simplePaginate($perPage)->appends($request->query());

        $pageOptions = DB::table($this->accTable)
            ->whereNotNull('page_name')->where('page_name', '<>', '')
            ->distinct()->orderBy('page_name')->pluck('page_name')->toArray();

        return view('ads_manager.edit-messaging-template', [
            'creatives'      => $creatives,
            'sortBy'         => $sortBy,
            'sortDirection'  => $sortDirection,
            'pageOptions'    => $pageOptions,
        ]);
    }

    private function sanitizeSortColumn(string $requested): string
    {
        return in_array($requested, $this->allowedSortColumns, true) ? $requested : 'date_created';
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'headline'         => ['nullable', 'string', 'max:5000'],
            'welcome_message'  => ['nullable', 'string', 'max:5000'],
            'quick_reply_1'    => ['nullable', 'string', 'max:1000'],
            'quick_reply_2'    => ['nullable', 'string', 'max:1000'],
            'quick_reply_3'    => ['nullable', 'string', 'max:1000'],
            'ad_link'          => ['nullable', 'string', 'max:2048'],
            'feedback'         => ['nullable', 'in:0,1'],
        ]);

        $row = DB::table($this->accTable)->where('id', $id)->first();
        abort_if(!$row, 404);

        $update = [];
        foreach (['headline','welcome_message','quick_reply_1','quick_reply_2','quick_reply_3','ad_link','feedback'] as $f) {
            if ($request->has($f)) {
                $val = $validated[$f] ?? null;
                if ($f === 'feedback' && $val !== null) $val = (int)$val; // 0/1
                $update[$f] = $val;
            }
        }

        if (!empty($update)) {
            $update['updated_at'] = now();
            DB::table($this->accTable)->where('id', $id)->update($update);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Updated.');
    }
}
