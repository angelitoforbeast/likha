<?php

namespace App\Http\Controllers;

use App\Services\AnalystRts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Sulatan ng analyst ng Mira RTS% ng bawat page+item, may dahilan (header X-ANALYST-RTS-KEY, services.analyst_rts.key);
 * walang key o mali = 401. Body: {"rows":[{page, item, rts_pct 0–100, reason ≤500}]} (puwede ring diretsong list).
 * Per-row ang error; ang iba ay nase-save pa rin. Hindi ginagalaw ang rts_pct (Set RTS ng staff).
 */
class AnalystRtsController extends Controller
{
    private const MAX_ROWS = 200;

    public function store(Request $request, AnalystRts $analyst): JsonResponse
    {
        $expected = (string) config('services.analyst_rts.key');
        $key = (string) $request->header('X-ANALYST-RTS-KEY');
        abort_unless($expected !== '' && $key !== '' && hash_equals($expected, $key), 401, 'Unauthorized');

        $rows = $request->json()->all();
        if (isset($rows['rows'])) $rows = $rows['rows'];
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::MAX_ROWS) {
            return response()->json(['message' => 'Body must be a list of at most '.self::MAX_ROWS.' rows'], 422);
        }

        $saved = 0;
        $errors = [];
        foreach ($rows as $i => $row) {
            $v = Validator::make(is_array($row) ? $row : [], [
                'page'    => 'required|string|max:255',
                'item'    => 'required|string|max:255',
                'rts_pct' => 'required|numeric|between:0,100',
                'reason'  => 'required|string|max:500',
            ]);
            $error = $v->fails() ? $v->errors()->first() : $analyst->save($row['page'], $row['item'], (float) $row['rts_pct'], $row['reason']);
            if ($error === null) {
                $saved++;
            } else {
                $errors[] = ['index' => $i, 'page' => $row['page'] ?? null, 'item' => $row['item'] ?? null, 'error' => $error];
            }
        }
        if ($saved > 0) OwnerPrivateController::bumpCacheVersion();

        return response()->json(['saved' => $saved, 'failed' => count($errors), 'errors' => $errors]);
    }
}
