<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * /page-settings/psid-coverage: ilang order ang may Botcake PSID kada page sa isang date range (max 31 araw), at
 * ang mga wala (ambiguous / no match / hindi pa nasusuri), paginated 50. CEO lang (404 sa iba).
 * Malaki ang macro_output: iisang grouped query ang summary, at ang listahan ay laging paginated.
 */
class PsidCoverageController extends Controller
{
    public const MAX_DAYS = 31;
    public const JNT = ['all', 'In Transit', 'Delivering'];
    public const REASONS = ['all', 'ambiguous', 'no_match', 'not_synced'];

    private function getNormalizedRole(): string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';
        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    private function checkAccess(): void
    {
        if ($this->getNormalizedRole() !== 'CEO') abort(404);
    }

    private function trimFn(): string
    {
        return DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
    }

    private function col(string $name): string
    {
        return DB::getDriverName() === 'pgsql' ? 'mo."' . $name . '"' : 'mo.`' . $name . '`';
    }

    private function pageExpr(): string
    {
        return 'LOWER(' . $this->trimFn() . '(' . $this->col('PAGE') . '))';
    }

    /** @return array{0:string, 1:string} [from, to] (Y-m-d, Manila); default huling 7 araw, max 31 araw */
    private function range(Request $request): array
    {
        $today = Carbon::now('Asia/Manila')->startOfDay();
        $parse = function ($v) {
            try {
                return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? Carbon::createFromFormat('Y-m-d', $v, 'Asia/Manila')->startOfDay() : null;
            } catch (\Throwable $e) {
                return null;
            }
        };

        $to   = $parse($request->query('to')) ?? $today;
        $from = $parse($request->query('from')) ?? $to->copy()->subDays(6);
        if ($from->gt($to)) [$from, $to] = [$to, $from];
        if ($from->diffInDays($to) > self::MAX_DAYS - 1) $from = $to->copy()->subDays(self::MAX_DAYS - 1);

        return [$from->toDateString(), $to->toDateString()];
    }

    private function emptyPsid(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('mo.botcake_psid')->orWhere('mo.botcake_psid', ''));
    }

    /** Isang grouped query: bawat page, ilang order, ilan ang may PSID, at ang dahilan ng mga wala. */
    public function summaryQuery(string $from, string $to): Builder
    {
        $empty = "(mo.botcake_psid IS NULL OR mo.botcake_psid = '')";

        return DB::table('macro_output as mo')
            ->leftJoin('botcake_psid_misses as m', 'm.order_id', '=', 'mo.id')
            ->whereBetween('mo.ts_date', [$from, $to])
            ->whereNotNull('mo.PAGE')
            ->selectRaw($this->pageExpr() . ' AS page_key')
            ->selectRaw('MIN(' . $this->trimFn() . '(' . $this->col('PAGE') . ')) AS page_name')
            ->selectRaw('COUNT(*) AS orders')
            ->selectRaw("SUM(CASE WHEN $empty THEN 0 ELSE 1 END) AS with_psid")
            ->selectRaw("SUM(CASE WHEN $empty AND m.reason = 'ambiguous' THEN 1 ELSE 0 END) AS ambiguous")
            ->selectRaw("SUM(CASE WHEN $empty AND m.reason = 'no_match' THEN 1 ELSE 0 END) AS no_match")
            ->groupByRaw($this->pageExpr())
            ->orderBy('page_name');
    }

    /** Ang orders ng isang page na walang PSID, pinakabago muna. */
    public function ordersQuery(string $pageKey, string $from, string $to, string $jnt, string $reason): Builder
    {
        $q = $this->emptyPsid(DB::table('macro_output as mo'))
            ->leftJoin('botcake_psid_misses as m', 'm.order_id', '=', 'mo.id')
            ->whereBetween('mo.ts_date', [$from, $to])
            ->whereRaw($this->pageExpr() . ' = ?', [$pageKey])
            ->select('mo.id', 'mo.ts_date', 'mo.fb_name', 'mo.waybill', 'm.reason')
            ->selectRaw($this->col('PHONE NUMBER') . ' AS phone')
            ->selectSub(fn ($s) => $s->from('from_jnts as js')->whereColumn('js.waybill_number', 'mo.waybill')->selectRaw('MAX(js.status)'), 'jnt_status')
            ->orderByDesc('mo.ts_date')->orderByDesc('mo.id');

        if ($reason === 'not_synced') $q->whereNull('m.id');
        elseif ($reason !== 'all') $q->where('m.reason', $reason);

        if ($jnt !== 'all') {
            // Kapareho ng /jnt status: ang Delivering ay walang RTS reason.
            $q->whereExists(function ($e) use ($jnt) {
                $e->from('from_jnts as j')->whereColumn('j.waybill_number', 'mo.waybill')->where('j.status', $jnt)->selectRaw('1');
                if ($jnt === 'Delivering') {
                    $e->where(fn ($r) => $r->whereNull('j.rts_reason')->orWhereRaw("TRIM(j.rts_reason) = ''"));
                }
            });
        }

        return $q;
    }

    public function index(Request $request)
    {
        $this->checkAccess();
        [$from, $to] = $this->range($request);

        $summary = $this->summaryQuery($from, $to)->get()->map(function ($r) {
            $orders  = (int) $r->orders;
            $with    = (int) $r->with_psid;
            $without = $orders - $with;
            $amb     = (int) $r->ambiguous;
            $nom     = (int) $r->no_match;

            return [
                'page_key'     => (string) $r->page_key,
                'page_name'    => (string) $r->page_name,
                'orders'       => $orders,
                'with_psid'    => $with,
                'without_psid' => $without,
                'ambiguous'    => $amb,
                'no_match'     => $nom,
                'not_synced'   => $without - $amb - $nom,
                'pct'          => $orders ? round($with * 100 / $orders, 1) : 0.0,
            ];
        })->all();

        return view('page_settings.psid_coverage', compact('summary', 'from', 'to'));
    }

    public function orders(Request $request)
    {
        $this->checkAccess();
        [$from, $to] = $this->range($request);

        $pageName = preg_replace('/\s+/u', ' ', trim((string) $request->query('page_name', '')));
        $jnt      = in_array($request->query('jnt'), self::JNT, true) ? $request->query('jnt') : 'all';
        $reason   = in_array($request->query('reason'), self::REASONS, true) ? $request->query('reason') : 'all';

        $orders = $this->ordersQuery(mb_strtolower($pageName, 'UTF-8'), $from, $to, $jnt, $reason)
            ->paginate(50)->withQueryString();

        return view('page_settings.psid_coverage_orders', compact('orders', 'pageName', 'from', 'to', 'jnt', 'reason'));
    }
}
