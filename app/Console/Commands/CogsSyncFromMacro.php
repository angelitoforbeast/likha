<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Cogs;
use Carbon\Carbon;

class CogsSyncFromMacro extends Command
{
    protected $signature = 'cogs:sync-from-macro {--month=}';
    protected $description = 'Insert COGS rows only when item/date exists in macro_output; carry-forward price unless explicitly edited';

    // ⬇️ Edit these if your table/columns differ
    private const MACRO_TABLE = 'macro_output';
    private const COL_ITEM    = 'ITEM_NAME';   // e.g. 'ITEM_NAME' or 'item_name'
    private const COL_DATE    = 'TIMESTAMP';   // literal column name (reserved word)

    public function handle(): int
    {
        $monthArg = $this->option('month') ?? now()->format('Y-m');
        $month = Carbon::parse($monthArg);
        $start = $month->copy()->startOfMonth();
        $end   = $month->copy()->endOfMonth();

        $dateExpr = $this->dateExpr(self::COL_DATE); // portable expr for MySQL / Postgres

        $this->info("Syncing COGS for {$month->format('F Y')} ({$start->toDateString()} to {$end->toDateString()}) using ".self::COL_ITEM." / ".self::COL_DATE);

        // (item, date) pairs present in macro_output this month
        $pairs = DB::table(self::MACRO_TABLE)
            ->select([ self::COL_ITEM.' as item_name', DB::raw($dateExpr.' as d') ])
            ->whereRaw($dateExpr.' BETWEEN ? AND ?', [$start->toDateString(), $end->toDateString()])
            ->orderBy(self::COL_ITEM)
            ->orderByRaw($dateExpr.' ASC')
            ->get();

        $inserted = 0;

        foreach ($pairs as $p) {
            $item = trim($p->item_name);
            $d    = Carbon::parse($p->d)->toDateString();

            // Skip if already exists
            if (Cogs::where('item_name', $item)->whereDate('date', $d)->exists()) {
                continue;
            }

            // Carry-forward last known price
            $prev = Cogs::where('item_name', $item)
                        ->where('date', '<', $d)
                        ->orderByDesc('date')
                        ->first();
            $carry = $prev?->unit_cost;

            Cogs::create([
                'item_name'   => $item,
                'date'        => $d,
                'unit_cost'   => $carry,   // same as kahapon (null if none yet)
                'history_logs'=> null,
            ]);

            $inserted++;
        }

        $this->info("Inserted {$inserted} row(s).");
        return self::SUCCESS;
    }

    /**
     * Portable date extractor for MySQL and Postgres, including string formats like '21:44 09-06-2025'.
     */
    private function dateExpr(string $col): string
    {
        $driver = DB::getDriverName(); // 'mysql' or 'pgsql'

        if ($driver === 'mysql') {
            // MySQL: support TIMESTAMP/DATETIME or string like "21:44 09-06-2025"
            return "COALESCE(
                DATE(`{$col}`),
                DATE(STR_TO_DATE(`{$col}`, '%H:%i %d-%m-%Y')),
                DATE(STR_TO_DATE(`{$col}`, '%d-%m-%Y %H:%i')),
                DATE(STR_TO_DATE(`{$col}`, '%Y-%m-%d %H:%i:%s'))
            )";
        } else {
            // Postgres/Heroku: handle real timestamp/date or text with to_date()
            return "COALESCE(
                (CASE WHEN pg_typeof(\"{$col}\")::text IN ('timestamp without time zone','timestamp with time zone','date')
                      THEN \"{$col}\"::date ELSE NULL END),
                to_date(\"{$col}\", 'HH24:MI DD-MM-YYYY'),
                to_date(\"{$col}\", 'DD-MM-YYYY HH24:MI'),
                to_date(\"{$col}\", 'YYYY-MM-DD HH24:MI:SS')
            )";
        }
    }
}
