<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MacroOutput;
use Illuminate\Support\Facades\Schema;

class MacroOutputPageNameController extends Controller
{
    public function index(Request $request)
    {
        $tz = 'Asia/Manila';

        // ✅ Default: yesterday
        $date = $request->filled('date')
            ? $request->date
            : now($tz)->subDay()->toDateString(); // Y-m-d

        $formattedDMY = \Carbon\Carbon::parse($date, $tz)->format('d-m-Y');

        // Detect ts_date type (date vs datetime/timestamp)
        $tsType = null;
        try {
            $tsType = Schema::getColumnType('macro_output', 'ts_date'); // 'date'|'datetime'|'timestamp'...
        } catch (\Throwable $e) {
            $tsType = null;
        }

        // ✅ Filter by date (fast path: ts_date, fallback: TIMESTAMP like %d-m-Y%)
        $query = MacroOutput::query()
            ->where(function ($q) use ($date, $formattedDMY, $tsType, $tz) {

                // A) Preferred: ts_date not null
                $q->where(function ($qq) use ($date, $tsType, $tz) {
                    $qq->whereNotNull('ts_date');

                    if ($tsType === 'date') {
                        $qq->where('ts_date', '=', $date);
                    } else {
                        $start = \Carbon\Carbon::parse($date, $tz)->startOfDay()->toDateTimeString();
                        $end   = \Carbon\Carbon::parse($date, $tz)->endOfDay()->toDateTimeString();
                        $qq->whereBetween('ts_date', [$start, $end]);
                    }
                });

                // B) Legacy fallback: ts_date null -> use TIMESTAMP like "%d-m-Y"
                $q->orWhere(function ($qq) use ($formattedDMY) {
                    $qq->whereNull('ts_date')
                       ->whereNotNull('TIMESTAMP')
                       ->where('TIMESTAMP', 'LIKE', "%{$formattedDMY}%");
                });
            })
            ->whereNotNull('PAGE')
            ->select(['id', 'PAGE', 'fb_name'])
            ->orderBy('PAGE')
            ->orderBy('fb_name')
            ->orderByDesc('id');

        $rows = $query->get();

        return view('macro_output.page-fullname', compact('rows', 'date'));
    }
}
