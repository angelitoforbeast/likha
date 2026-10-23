<?php

namespace App\Http\Controllers;

use App\Services\RtsProjection;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only feed para sa assistant ng owner: Set RTS% laban sa Projected RTS% ng bawat page+item (≥30 matched na
 * order), flagged (|set − projected| > 5 puntos) muna. Sariling key (X-RTS-CHECK-KEY, services.rts_check.key);
 * walang key o mali = 401. Walang customer data: bilang at porsiyento lang.
 */
class OwnerRtsCheckController extends Controller
{
    private const TZ = 'Asia/Manila';
    private const DEFAULT_DAYS = 21;
    private const FIELDS = ['page', 'item', 'orders', 'unmatched', 'matched', 'delivered', 'returned', 'for_return',
        'in_transit', 'in_transit_pct', 'projected_rts', 'actual_rts', 'set_rts', 'gap', 'flag', 'set_by', 'set_at'];

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

        $rows = array_values(array_filter($projection->rows($from, $to), fn ($r) => $r['matched'] >= RtsProjection::MIN_MATCHED_FLAG));
        usort($rows, fn ($a, $b) => [$b['flag'], abs($b['gap'] ?? 0), $b['matched']] <=> [$a['flag'], abs($a['gap'] ?? 0), $a['matched']]);

        return response()->json([
            'from' => $from,
            'to' => $to,
            'rates_learned_at' => $projection->rates()['learned_at'] ?? null,
            'rows' => array_map(fn ($r) => array_intersect_key(array_replace(array_flip(self::FIELDS), $r), array_flip(self::FIELDS)), $rows),
        ]);
    }
}
