<?php

namespace App\Http\Controllers;

use App\Models\OrderEncode;
use App\Services\OrderEncode\Comparer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * /order-encode (spec 130): ang paghahambing ng sariling encode ng Likha sa DATABASE row ng Google Sheet. Read-only.
 * Parehong access ng /owner/private: CEO, Marketing - OIC at Marketing.
 */
class OrderEncodeController extends Controller
{
    private function getNormalizedRole(): string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';

        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    private function checkAccess(): void
    {
        if (!in_array($this->getNormalizedRole(), ['CEO', 'Marketing - OIC', 'Marketing'], true)) abort(404);
    }

    private function date(mixed $v, Carbon $default): Carbon
    {
        $s = is_string($v) ? trim($v) : '';
        $d = $s !== '' ? \DateTime::createFromFormat('!Y-m-d', $s) : false;

        return $d ? Carbon::instance($d) : $default;
    }

    public function index(Request $request)
    {
        $this->checkAccess();
        if (!Schema::hasTable('order_encodes')) abort(404);

        $to = $this->date($request->query('to'), now()->startOfDay());
        $from = $this->date($request->query('from'), $to->copy()->subDays(6));
        $page = trim((string) (is_string($request->query('page')) ? $request->query('page') : ''));
        $field = is_string($request->query('field')) && isset(Comparer::FIELDS[$request->query('field')]) ? $request->query('field') : '';
        $onlyDiff = (bool) $request->query('only_diff');

        $base = OrderEncode::where('mode', 'shadow')
            ->where('created_at', '>=', $from->copy()->startOfDay())->where('created_at', '<=', $to->copy()->endOfDay())
            ->when($page !== '', fn ($q) => $q->where('page', $page));

        $stats = $this->stats(clone $base);

        $list = clone $base;
        if ($onlyDiff) {
            $list->where(fn ($q) => $q->where('compare_equal', false)->orWhere('compare_state', 'row_mismatch'));
        }
        if ($field !== '') {
            // Ang field na may pagkakaiba: ang hindi "same" sa compare ng field na iyon.
            $list->where('compare_state', 'done')->where('compare->' . $field, '!=', 'same');
        }
        $paginator = $list->orderByDesc('id')->simplePaginate(50)->withQueryString();

        return view('order-encode.index', [
            'stats' => $stats,
            'rows' => collect($paginator->items())->map(fn (OrderEncode $o) => $this->row($o))->all(),
            'paginator' => $paginator,
            'fields' => array_keys(Comparer::FIELDS),
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'page' => $page, 'field' => $field, 'only_diff' => $onlyDiff],
            'pages' => OrderEncode::where('mode', 'shadow')->distinct()->orderBy('page')->pluck('page'),
        ]);
    }

    /** @return array<string, mixed> */
    private function stats($query): array
    {
        $s = ['orders' => 0, 'compared' => 0, 'equal' => 0, 'waiting' => 0, 'sheet_not_done' => 0, 'row_mismatch' => 0, 'failed' => 0,
            'gpt_requests' => 0, 'with_usage' => 0, 'cost' => 0.0];
        $same = array_fill_keys(array_keys(Comparer::FIELDS), 0);
        $prices = (array) config('order_encode.prices');

        foreach ($query->get(['id', 'status', 'compare_state', 'compare_equal', 'compare', 'usage']) as $o) {
            $s['orders']++;
            if ($o->status === 'failed') $s['failed']++;
            elseif ($o->compare_state === 'done') {
                $s['compared']++;
                $s['equal'] += $o->compare_equal ? 1 : 0;
                foreach ((array) $o->compare as $f => $r) if ($r === 'same' && isset($same[$f])) $same[$f]++;
            } elseif ($o->compare_state === 'sheet_not_done') $s['sheet_not_done']++;
            elseif ($o->compare_state === 'row_mismatch') $s['row_mismatch']++;
            else $s['waiting']++;

            if ($o->usage) {
                $s['with_usage']++;
                foreach ($o->usage as $model => $u) {
                    $p = $prices[$model] ?? $prices['default'] ?? ['in' => 0, 'out' => 0];
                    $s['gpt_requests'] += (int) ($u['requests'] ?? 0);
                    $s['cost'] += ((int) ($u['tokens_in'] ?? 0) * $p['in'] + (int) ($u['tokens_out'] ?? 0) * $p['out']) / 1e6;
                }
            }
        }
        $pct = fn (int $n, int $of) => $of > 0 ? round($n / $of * 100, 1) : null;
        $s['equal_pct'] = $pct($s['equal'], $s['compared']);
        $s['field_pct'] = array_map(fn ($n) => $pct($n, $s['compared']), $same);
        $s['calls_per_order'] = $s['with_usage'] > 0 ? $s['gpt_requests'] / $s['with_usage'] : null;
        $s['cost_per_order'] = $s['with_usage'] > 0 ? $s['cost'] / $s['with_usage'] : null;

        return $s;
    }

    /** @return array<string, mixed> */
    private function row(OrderEncode $o): array
    {
        $out = (array) $o->outputs;
        $db = (array) ($o->sheet['database'] ?? []);
        $mirror = (array) ($o->sheet['mirrored'] ?? []);
        $cells = [];
        foreach (Comparer::FIELDS as $f => [$key, $at]) {
            $likha = $key === 'proceed' ? (($out['proceed'] ?? false) ? 'PROCEED' : '') : (string) ($out[$key] ?? '');
            $sheet = $at === 'O' ? ($mirror[0] ?? '') : ($at === 'P' ? ($mirror[1] ?? '') : ($db[$at] ?? ''));
            $cells[$f] = ['likha' => $likha, 'sheet' => (string) $sheet, 'result' => $o->compare[$f] ?? null];
        }

        $state = $o->status === 'failed' ? 'encode failed' : match ($o->compare_state) {
            'done' => $o->compare_equal ? 'equal' : 'different',
            'sheet_not_done' => 'sheet not done',
            'row_mismatch' => 'row mismatch',
            default => $o->status === 'done' ? 'waiting' : 'encoding',
        };

        return [
            'id' => $o->id, 'page' => $o->page, 'row' => $o->row_number, 'at' => $o->created_at?->format('Y-m-d H:i'), 'state' => $state,
            'error' => $o->error, 'cells' => $cells, 'title' => $this->title((array) $o->steps),
        ];
    }

    /** Ang hover: bawat hakbang at tawag sa GPT sa isang linya (walang raw na sagot). */
    private function title(array $steps): string
    {
        return implode("\n", array_map(function ($s) {
            $line = ($s['step'] ?? '') . ': ' . ($s['status'] ?? '');
            if (!empty($s['model'])) $line .= ' (' . $s['model'] . ', ' . (int) ($s['tokens_in'] ?? 0) . '/' . (int) ($s['tokens_out'] ?? 0) . ' tokens, ' . (int) ($s['ms'] ?? 0) . ' ms)';

            return $line;
        }, $steps));
    }
}
