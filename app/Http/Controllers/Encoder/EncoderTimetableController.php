<?php

namespace App\Http\Controllers\Encoder;

use App\Http\Controllers\Controller;
use App\Support\NavAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * /encoder/timetable (spec 265): anong oras natapos ang encoding, ang J&T upload at ang waybill print ng bawat
 * order date. Ang trabaho sa araw D ay para sa orders ng D-1, kaya naka-key sa order date at ang oras ay ang totoong
 * oras (may petsa kapag hindi kinabukasan ng order date).
 *
 * Basa lang, sa live tables: puro SELECT, naka-group sa SQL. Ang staff_events ay sinasala sa created_at range (may
 * index) at isinasama sa macro_output sa primary key; walang bagong table, column o index.
 */
class EncoderTimetableController extends Controller
{
    public const NAV_URL = '/encoder/timetable';

    public const DEFAULT_DAYS = 14;

    public const MAX_DAYS = 62;

    /** Lampas dito (minuto) sa average ng finish ng isang step: naka-highlight ang araw. */
    public const LATE_MINUTES = 30;

    private const CHECKER_KINDS = ['checker.validate', 'checker.edit', 'checker.status'];

    private const JNT_DONE = ['finished', 'done'];

    private const JNT_FAILED = ['failed', 'stopped'];

    public function index(Request $request)
    {
        $role = preg_replace('/\s+/u', ' ', trim((string) (Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '')));
        if (! NavAccess::allows($role, self::NAV_URL)) {
            abort(403);
        }

        [$from, $to, $error] = $this->range($request);

        $encoding = $this->encoding($from, $to);
        $jnt = $this->jnt($from, $to);
        $waybill = $this->waybills($from, $to);
        $excel = $this->excel($from, $to);
        $oldest = Schema::hasTable('staff_events') ? DB::table('staff_events')->min('created_at') : null;
        $oldestDay = $oldest ? Carbon::parse($oldest)->toDateString() : null;

        $rows = [];
        for ($d = $to->copy(); $d->gte($from); $d->subDay()) {
            $date = $d->toDateString();
            $enc = $encoding[$date] ?? null;
            $j = $jnt[$date] ?? null;
            $w = $waybill[$date] ?? null;

            $rows[] = [
                'date' => $date,
                'encoding' => $enc,
                'no_record' => $enc === null && $oldestDay !== null && $date < $oldestDay,
                'jnt' => $j,
                'waybill' => $w,
                'excel' => $excel[$date] ?? null,
                'gap_enc_jnt' => $this->gap($enc['finish'] ?? null, $j['finish'] ?? null),
                'gap_jnt_waybill' => $this->gap($j['finish'] ?? null, $w['finish'] ?? null),
                'pages' => $this->pageBreakdown($enc['pages'] ?? [], $j['pages'] ?? []),
            ];
        }

        $summary = $this->summary($rows);

        return view('encoder.timetable', [
            'rows' => $rows,
            'summary' => $summary,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'error' => $error,
            'oldestDay' => $oldestDay,
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon, 2: ?string} order date range; default ang huling 14 order dates (hanggang kahapon) */
    private function range(Request $request): array
    {
        $parse = function ($v) {
            if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                return null;
            }
            try {
                return Carbon::createFromFormat('!Y-m-d', $v);
            } catch (\Throwable) {
                return null;
            }
        };

        $to = $parse($request->query('date_to')) ?: now()->startOfDay()->subDay();
        $from = $parse($request->query('date_from')) ?: $to->copy()->subDays(self::DEFAULT_DAYS - 1);
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $error = null;
        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            $from = $to->copy()->subDays(self::MAX_DAYS - 1);
            $error = 'Hanggang '.self::MAX_DAYS.' araw lang ang puwede; ipinakita ang huling '.self::MAX_DAYS.' araw hanggang '.$to->format('M j, Y').'.';
        }

        return [$from, $to, $error];
    }

    /**
     * Encoding = Checker 1 actions (validate, edit, status) sa staff_events, isinama sa order (macro_output) para sa
     * order date at page. Isang query na naka-group per order date at page, at isang maliit na query para sa nakatapos.
     *
     * @return array<string, array{start: string, finish: string, orders: int, finisher: ?string, pages: array<string, array>}>
     */
    private function encoding(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('staff_events') || ! Schema::hasTable('macro_output')) {
            return [];
        }

        $base = fn () => DB::table('staff_events as se')
            ->join('macro_output as mo', 'mo.id', '=', DB::raw('CAST(se.ref AS '.$this->intType().')'))
            ->whereIn('se.kind', self::CHECKER_KINDS)
            ->where('se.source', 'checker_1')
            ->whereBetween('mo.ts_date', [$from->toDateString(), $to->toDateString()]);

        // Ang trabaho para sa order date D ay nasa D+1 (minsan mas huli): created_at mula D-1 hanggang D+3 ng range.
        $eventsFrom = $from->copy()->subDay()->toDateTimeString();
        $eventsTo = $to->copy()->addDays(4)->toDateTimeString();

        $groups = $base()
            ->where('se.created_at', '>=', $eventsFrom)
            ->where('se.created_at', '<', $eventsTo)
            ->groupBy('mo.ts_date', 'mo.PAGE')
            // MySQL 8 hint (comment lang sa ibang DB): staff_events muna (created_at range), saka macro_output sa PRIMARY.
            ->selectRaw('/*+ JOIN_ORDER(se, mo) */ mo.ts_date AS d, mo.PAGE AS page, MIN(se.created_at) AS started, MAX(se.created_at) AS finished, COUNT(DISTINCT se.ref) AS orders')
            ->get();

        $out = [];
        foreach ($groups as $g) {
            $date = substr((string) $g->d, 0, 10);
            $page = trim((string) $g->page) !== '' ? (string) $g->page : '(walang page)';
            $out[$date] ??= ['start' => $g->started, 'finish' => $g->finished, 'orders' => 0, 'finisher' => null, 'pages' => []];
            $out[$date]['start'] = min($out[$date]['start'], $g->started);
            $out[$date]['finish'] = max($out[$date]['finish'], $g->finished);
            $out[$date]['orders'] += (int) $g->orders;
            $out[$date]['pages'][$page] = ['start' => $g->started, 'finish' => $g->finished, 'orders' => (int) $g->orders];
        }

        if ($out) {
            // Sino ang huling nag-encode: ang event sa mismong finish time ng bawat araw (created_at IN, gamit ang index).
            $last = $base()
                ->leftJoin('users as u', 'u.id', '=', 'se.user_id')
                ->whereIn('se.created_at', array_values(array_unique(array_column($out, 'finish'))))
                ->orderByDesc('se.id')
                ->selectRaw('/*+ JOIN_ORDER(se, mo) */ mo.ts_date AS d, se.created_at, u.name')
                ->get();
            foreach ($last as $l) {
                $date = substr((string) $l->d, 0, 10);
                if (isset($out[$date]) && $out[$date]['finisher'] === null && $l->created_at === $out[$date]['finish']) {
                    $out[$date]['finisher'] = $l->name ?? '?';
                }
            }
        }

        return $out;
    }

    /**
     * J&T upload = in-app J&T booking runs (jnt_batch_runs), isang run kada page kada araw; date at page nasa filters JSON.
     *
     * @return array<string, array{finish: ?string, runs: int, done: int, failed: int, pages: array<string, array>}>
     */
    private function jnt(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('jnt_batch_runs')) {
            return [];
        }

        $date = $this->json('filters', 'date');
        $page = $this->json('filters', 'page');
        $done = $this->inList(self::JNT_DONE);
        $failed = $this->inList(self::JNT_FAILED);

        $groups = DB::table('jnt_batch_runs')
            // Walang run para sa order date na ginawa bago ang araw bago nito; binabawasan lang ang JSON na binabasa.
            ->where('created_at', '>=', $from->copy()->subDay()->toDateTimeString())
            ->whereRaw("$date BETWEEN ? AND ?", [$from->toDateString(), $to->toDateString()])
            ->groupByRaw('d, page')
            ->selectRaw("$date AS d, $page AS page, COUNT(*) AS runs,
                SUM(CASE WHEN status IN ($done) THEN 1 ELSE 0 END) AS done_runs,
                SUM(CASE WHEN status IN ($failed) THEN 1 ELSE 0 END) AS failed_runs,
                MAX(CASE WHEN status IN ($done) THEN finished_at END) AS finished")
            ->get();

        $out = [];
        foreach ($groups as $g) {
            $d = substr((string) $g->d, 0, 10);
            $p = trim((string) $g->page) !== '' ? (string) $g->page : '(lahat ng page)';
            $out[$d] ??= ['finish' => null, 'runs' => 0, 'done' => 0, 'failed' => 0, 'pages' => []];
            $out[$d]['finish'] = $g->finished !== null ? max((string) $out[$d]['finish'], $g->finished) : $out[$d]['finish'];
            $out[$d]['runs'] += (int) $g->runs;
            $out[$d]['done'] += (int) $g->done_runs;
            $out[$d]['failed'] += (int) $g->failed_runs;
            $out[$d]['pages'][$p] = ['finish' => $g->finished, 'runs' => (int) $g->runs, 'done' => (int) $g->done_runs, 'failed' => (int) $g->failed_runs];
        }

        return $out;
    }

    /**
     * Waybill = bulk waybill print runs (jnt_waybill_print_runs). Ang finished_at ng run ay ang oras na handa ang file;
     * ang mismong pag-download ng PDF ay hindi naitatala.
     *
     * @return array<string, array{finish: ?string, runs: int, done: int, failed: int, items: array<int, array>}>
     */
    private function waybills(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('jnt_waybill_print_runs')) {
            return [];
        }

        $groups = DB::table('jnt_waybill_print_runs')
            ->where('date', '>=', $from->toDateString())
            ->where('date', '<', $to->copy()->addDay()->toDateString())
            ->groupByRaw('DATE(date), filter_by, filter_value')
            ->selectRaw("DATE(date) AS d, filter_by, filter_value, COUNT(*) AS runs,
                SUM(CASE WHEN status = 'finished' THEN 1 ELSE 0 END) AS done_runs,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_runs,
                MAX(CASE WHEN status = 'finished' THEN finished_at END) AS finished")
            ->get();

        $out = [];
        foreach ($groups as $g) {
            $d = substr((string) $g->d, 0, 10);
            $out[$d] ??= ['finish' => null, 'runs' => 0, 'done' => 0, 'failed' => 0, 'items' => []];
            $out[$d]['finish'] = $g->finished !== null ? max((string) $out[$d]['finish'], $g->finished) : $out[$d]['finish'];
            $out[$d]['runs'] += (int) $g->runs;
            $out[$d]['done'] += (int) $g->done_runs;
            $out[$d]['failed'] += (int) $g->failed_runs;
            $out[$d]['items'][] = [
                'label' => trim((string) $g->filter_value) !== '' ? (string) $g->filter_value : '(lahat)',
                'by' => (string) $g->filter_by,
                'finish' => $g->finished,
                'runs' => (int) $g->runs,
                'done' => (int) $g->done_runs,
                'failed' => (int) $g->failed_runs,
            ];
        }
        foreach ($out as &$day) {
            usort($day['items'], fn ($a, $b) => strcmp((string) $b['finish'], (string) $a['finish']));
        }
        unset($day);

        return $out;
    }

    /** Excel download ng orders (bihira): ang pinakahuling download kada order date. */
    private function excel(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('downloaded_macro_output_logs')) {
            return [];
        }

        $dates = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        $out = [];
        $logs = DB::table('downloaded_macro_output_logs')->whereIn('timestamp', $dates)
            ->orderBy('downloaded_at')->get(['timestamp', 'page', 'downloaded_by', 'downloaded_at']);
        foreach ($logs as $l) {
            $out[(string) $l->timestamp] = ['at' => $l->downloaded_at, 'by' => $l->downloaded_by, 'page' => $l->page];
        }

        return $out;
    }

    /** Bawat page: encoding finish at J&T finish, ang pinakahuling natapos ang nasa itaas. */
    private function pageBreakdown(array $encPages, array $jntPages): array
    {
        $pages = [];
        foreach (array_unique(array_merge(array_keys($encPages), array_keys($jntPages))) as $p) {
            $e = $encPages[$p] ?? null;
            $j = $jntPages[$p] ?? null;
            $pages[] = [
                'page' => (string) $p,
                'encoding' => $e,
                'jnt' => $j,
                'latest' => max((string) ($e['finish'] ?? ''), (string) ($j['finish'] ?? '')),
            ];
        }
        usort($pages, fn ($a, $b) => strcmp($b['latest'], $a['latest']) ?: strcmp($a['page'], $b['page']));

        return $pages;
    }

    /**
     * Average finish at ang pinakahuling araw kada step. Ang finish ay sinusukat sa minuto mula hatinggabi ng
     * kinabukasan ng order date, kaya ang tapos sa ikalawang araw ay mas huli, hindi mas maaga.
     */
    private function summary(array &$rows): array
    {
        $steps = ['encoding' => 'Encoding', 'jnt' => 'J&T upload', 'waybill' => 'Waybill'];
        $summary = [];
        foreach ($steps as $key => $label) {
            $offsets = [];
            foreach ($rows as $i => $r) {
                $finish = $r[$key]['finish'] ?? null;
                if ($finish) {
                    $offsets[$i] = self::offsetMinutes($r['date'], $finish);
                }
            }
            $avg = $offsets ? array_sum($offsets) / count($offsets) : null;
            $worstIdx = $offsets ? array_search(max($offsets), $offsets, true) : null;
            foreach ($offsets as $i => $m) {
                $rows[$i]['late'][$key] = $m > $avg + self::LATE_MINUTES;
            }
            $summary[$key] = [
                'label' => $label,
                'days' => count($offsets),
                'avg' => $avg === null ? null : (int) round($avg),
                'worst' => $worstIdx === null ? null : $offsets[$worstIdx],
                'worst_date' => $worstIdx === null ? null : $rows[$worstIdx]['date'],
                'worst_at' => $worstIdx === null ? null : $rows[$worstIdx][$key]['finish'],
            ];
        }

        return $summary;
    }

    /** Minuto mula 00:00 ng kinabukasan ng order date. */
    public static function offsetMinutes(string $orderDate, string $at): int
    {
        $base = Carbon::createFromFormat('!Y-m-d', $orderDate)->addDay();

        return (int) floor(($base->diffInSeconds(Carbon::parse($at), false)) / 60);
    }

    /** Oras lang kapag kinabukasan ng order date; may petsa kapag ibang araw (hal. "Nov 14 09:12"). */
    public static function clock(?string $orderDate, ?string $at): string
    {
        if (! $at) {
            return '—';
        }
        $t = Carbon::parse($at);
        $next = Carbon::createFromFormat('!Y-m-d', (string) $orderDate)->addDay()->toDateString();

        return $t->toDateString() === $next ? $t->format('H:i') : $t->format('M j H:i');
    }

    /** Average na offset (minuto) bilang oras; "+1 araw" kapag lumampas sa kinabukasan. */
    public static function offsetClock(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        $days = (int) floor($minutes / 1440);
        $rest = $minutes - $days * 1440;
        $text = sprintf('%02d:%02d', intdiv($rest, 60), $rest % 60);
        if ($days > 0) {
            $text .= ' (+'.$days.' araw)';
        }
        if ($days < 0) {
            $text .= ' (order date mismo)';
        }

        return $text;
    }

    private function gap(?string $a, ?string $b): ?int
    {
        if (! $a || ! $b) {
            return null;
        }

        return (int) round(Carbon::parse($a)->diffInSeconds(Carbon::parse($b), false) / 60);
    }

    private function json(string $column, string $key): string
    {
        return match (DB::getDriverName()) {
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT($column, '$.$key'))",
            'pgsql' => "($column::json->>'$key')",
            default => "json_extract($column, '$.$key')",
        };
    }

    private function intType(): string
    {
        return DB::getDriverName() === 'pgsql' ? 'BIGINT' : 'SIGNED INTEGER';
    }

    /** @param string[] $values fixed na listahan (constants), hindi galing sa request */
    private function inList(array $values): string
    {
        return implode(', ', array_map(fn ($v) => "'".$v."'", $values));
    }
}
