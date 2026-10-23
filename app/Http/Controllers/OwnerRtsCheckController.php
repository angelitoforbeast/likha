<?php

namespace App\Http\Controllers;

use App\Services\AnalystRts;
use App\Services\ColFormatRules;
use App\Services\RtsProjection;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only feed para sa assistant ng owner: Set RTS% laban sa Projected RTS% ng bawat page+item (≥30 matched na
 * order); ang `level` (none/yellow/red) ay galing sa saved Conditional Formatting rules ng Mira RTS column (ColFormatRules),
 * red muna, saka yellow, saka ang laki ng gap. Sariling key (X-RTS-CHECK-KEY, services.rts_check.key);
 * walang key o mali = 401. Walang customer data: bilang at porsiyento lang.
 */
class OwnerRtsCheckController extends Controller
{
    private const TZ = 'Asia/Manila';
    private const DEFAULT_DAYS = 21;
    private const FIELDS = ['page', 'item', 'orders', 'unmatched', 'matched', 'delivered', 'returned', 'for_return',
        'in_transit', 'in_transit_pct', 'projected_rts', 'actual_rts', 'set_rts', 'gap', 'error_pts', 'gap_ratio', 'level', 'flag', 'set_by', 'set_at',
        'analyst_rts', 'analyst_reason', 'analyst_at'];
    private const LEVEL_RANK = ['red' => 0, 'yellow' => 1, 'none' => 2];

    public function index(Request $request, RtsProjection $projection): JsonResponse
    {
        $expected = (string) config('services.rts_check.key');
        $key = (string) $request->header('X-RTS-CHECK-KEY');
        abort_unless($expected !== '' && $key !== '' && hash_equals($expected, $key), 401, 'Unauthorized');

        $today = Carbon::now(self::TZ);
        $from = $request->query('from', $today->copy()->subDays(self::DEFAULT_DAYS)->toDateString());
        $to = $request->query('to', $today->toDateString());
        foreach ([$from, $to] as $d) {
            if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d)) {
                return response()->json(['message' => 'from and to must be YYYY-MM-DD'], 422);
            }
        }

        $rules = (new OwnerColumnSettingsController())->loadColFormat('owner_private')['byCol']['mira_rts'] ?? [];
        $analyst = (new AnalystRts())->all();
        $rows = [];
        foreach ($projection->rows($from, $to) as $key => $r) {
            if ($r['matched'] < RtsProjection::MIN_MATCHED_FLAG) continue;
            // Parehong pangalan ng column id ng Page Summary para tumama ang compare_col ng rules.
            $hit = ColFormatRules::firstHit($rules, 'mira_rts', [
                'mira_rts' => $r['projected_rts'], 'rts_set' => $r['set_rts'], 'rts_gap_pts' => $r['gap'], 'rts_gap_abs' => $r['gap_abs'],
                'mira_rts_error_pts' => $r['error_pts'], 'rts_gap_ratio' => $r['gap_ratio'], 'mira_rts_matched' => $r['matched'],
            ]);
            $r['level'] = ColFormatRules::level($hit['bg'] ?? null);
            $r['flag'] = $r['level'] === 'red';
            $r['analyst_rts'] = $analyst[$key]['pct'] ?? null;
            $r['analyst_reason'] = $analyst[$key]['reason'] ?? null;
            $r['analyst_at'] = $analyst[$key]['at'] ?? null;
            $rows[] = $r;
        }
        usort($rows, fn ($a, $b) => [self::LEVEL_RANK[$a['level']], -abs($a['gap'] ?? 0), -$a['matched']] <=> [self::LEVEL_RANK[$b['level']], -abs($b['gap'] ?? 0), -$b['matched']]);

        return response()->json([
            'from' => $from,
            'to' => $to,
            'rates_learned_at' => $projection->rates()['learned_at'] ?? null,
            'rows' => array_map(fn ($r) => array_intersect_key(array_replace(array_flip(self::FIELDS), $r), array_flip(self::FIELDS)), $rows),
        ]);
    }
}
