<?php

namespace App\Http\Controllers;

use App\Models\OrderEncode;
use App\Services\OrderEncode\FinalComparer;
use App\Services\OrderEncode\ImportShadow;
use App\Services\OrderEncode\Settings;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * /order-encode/final: ang encode ng Likha sa lahat ng page (galing sa Likha import) laban sa FINAL na order sa macro_output.
 * Basahin: CEO, Marketing - OIC, Marketing. Ang tatlong setting ng import shadow ay CEO lang ang makakapagbago.
 */
class OrderEncodeFinalController extends Controller
{
    private const SORTS = ['page', 'orders', 'location_pct', 'proceed_pct'];
    private const PER_PAGE = 50;

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

    public function index(Request $request, ImportShadow $shadow)
    {
        $this->checkAccess();
        if (!Schema::hasTable('order_encodes') || !Schema::hasColumn('order_encodes', 'final_compare')) abort(404);

        $to = $this->date($request->query('to'), now()->startOfDay());
        $from = $this->date($request->query('from'), $to->copy()->subDays(6));
        $page = trim((string) (is_string($request->query('page')) ? $request->query('page') : ''));
        $sort = in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'page';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $scope = fn () => OrderEncode::whereIn('mode', [ImportShadow::MODE, 'shadow'])->whereNotNull('macro_output_id');
        $base = $scope()->where('created_at', '>=', $from->copy()->startOfDay())->where('created_at', '<=', $to->copy()->endOfDay())
            ->when($page !== '', fn ($q) => $q->where('page', $page));

        $t = ['orders' => 0, 'compared' => 0, 'waiting' => 0, 'never' => 0, 'failed' => 0, 'location' => 0, 'address' => 0, 'item_cod' => 0, 'cost' => 0.0, 'note_hits' => 0];
        $matrix = ['untouched' => ['proceed' => 0, 'hold' => 0], 'edited' => ['proceed' => 0, 'hold' => 0], 'not' => ['proceed' => 0, 'hold' => 0]];
        $perPage = [];
        $disagree = [];
        $ruleHolds = [];
        $prices = (array) config('order_encode.prices');

        foreach ($base->orderBy('id')->cursor() as $o) {
            $t['orders']++;
            $p = &$perPage[$o->page];
            $p ??= ['page' => $o->page, 'orders' => 0, 'compared' => 0, 'location' => 0, 'agree' => 0];
            $p['orders']++;
            foreach ((array) $o->usage as $model => $u) {
                $pr = $prices[$model] ?? $prices['default'] ?? ['in' => 0, 'out' => 0];
                $t['cost'] += ((int) ($u['tokens_in'] ?? 0) * $pr['in'] + (int) ($u['tokens_out'] ?? 0) * $pr['out']) / 1e6;
            }
            $out = (array) $o->outputs;
            if (!empty($out['note_hit'])) $t['note_hits']++;
            if (!empty($out['hold']['rule'])) $ruleHolds[$out['hold']['rule']] = ($ruleHolds[$out['hold']['rule']] ?? 0) + 1;
            $f = $o->final_compare;
            if ($o->status === 'failed') $t['failed']++;
            elseif (!$f) $t['waiting']++;
            elseif (($f['state'] ?? '') !== 'done') $t['never']++;
            else {
                $r = $f['fields'];
                $t['compared']++;
                $p['compared']++;
                $location = $r['province'] === 'same' && $r['city'] === 'same' && $r['barangay'] === 'same';
                $t['location'] += $location ? 1 : 0;
                $p['location'] += $location ? 1 : 0;
                $t['address'] += $r['address_line'] === 'same' ? 1 : 0;
                $t['item_cod'] += $r['item'] === 'same' && $r['cod'] === 'same' ? 1 : 0;
                $p['agree'] += $f['likha_proceed'] === $f['final_proceed'] ? 1 : 0;
                $row = !$f['final_proceed'] ? 'not' : ($f['edited'] ? 'edited' : 'untouched');
                $matrix[$row][$f['likha_proceed'] ? 'proceed' : 'hold']++;
                if (in_array(true, array_map(fn ($v) => $v !== 'same', $r), true)) $disagree[] = $o->id;
            }
            unset($p);
        }

        $pct = fn (int $n, int $of) => $of > 0 ? round($n / $of * 100, 1) : null;
        $pageRows = array_map(fn ($p) => $p + ['location_pct' => $pct($p['location'], $p['compared']), 'proceed_pct' => $pct($p['agree'], $p['compared'])], array_values($perPage));
        usort($pageRows, fn ($a, $b) => ($dir === 'desc' ? -1 : 1) * ($sort === 'page' ? strcasecmp((string) $a['page'], (string) $b['page']) : ($a[$sort] ?? -1) <=> ($b[$sort] ?? -1)));

        $tiles = ['orders' => $t['orders'], 'compared' => $t['compared'], 'waiting' => $t['waiting'], 'never' => $t['never'], 'failed' => $t['failed'],
            'location_pct' => $pct($t['location'], $t['compared']), 'address_pct' => $pct($t['address'], $t['compared']), 'item_cod_pct' => $pct($t['item_cod'], $t['compared']), 'note_hits' => $t['note_hits']];
        ksort($ruleHolds);

        rsort($disagree);
        $number = max(1, (int) $request->query('p', 1));
        $items = OrderEncode::whereIn('id', array_slice($disagree, ($number - 1) * self::PER_PAGE, self::PER_PAGE))->get()->sortByDesc('id')->values();
        $paginator = (new LengthAwarePaginator($items, count($disagree), self::PER_PAGE, $number, ['path' => $request->url(), 'pageName' => 'p']))->withQueryString();

        return view('order-encode.final', [
            'tiles' => $tiles, 'proceed' => $matrix, 'pageRows' => $pageRows, 'rows' => $items->map(fn (OrderEncode $o) => $this->row($o))->all(), 'paginator' => $paginator,
            'import' => [
                'on' => $shadow->on(), 'cap' => $shadow->cap(), 'skip_pages' => $shadow->skipPagesText(), 'encoded_today' => $shadow->encodedToday(),
                'skipped_cap' => $shadow->skippedToday()['cap'], 'skipped_pages' => $shadow->skippedToday()['pages'], 'cost' => $t['cost'],
                'can_edit' => $this->getNormalizedRole() === 'CEO', 'rules' => app(Settings::class)->rules(),
            ],
            'ruleHolds' => $ruleHolds,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'page' => $page, 'sort' => $sort, 'dir' => $dir],
            'pages' => $scope()->distinct()->orderBy('page')->pluck('page'),
        ]);
    }

    /** Ang tatlong setting ng import shadow: CEO lang. */
    public function save(Request $request)
    {
        if ($this->getNormalizedRole() !== 'CEO') abort(404);
        $data = $request->validate([
            'switch' => ['required', 'in:on,off'],
            'cap' => ['required', 'integer', 'min:0', 'max:100000'],
            'skip_pages' => ['nullable', 'string', 'max:2000'],
            'rules' => ['nullable', 'in:' . implode(',', Settings::RULE_SETS)],
        ]);
        \App\Models\AppSetting::set(ImportShadow::SWITCH, $data['switch']);
        \App\Models\AppSetting::set(ImportShadow::CAP, (string) $data['cap']);
        \App\Models\AppSetting::set(ImportShadow::SKIP_PAGES, trim((string) ($data['skip_pages'] ?? '')));
        if (!empty($data['rules'])) \App\Models\AppSetting::set(Settings::RULES, $data['rules']);

        return redirect()->route('order-encode.final')->with('status', 'Saved.');
    }

    /** @return array<string, mixed> */
    private function row(OrderEncode $o): array
    {
        $out = (array) $o->outputs;
        $f = (array) $o->final_compare;
        $final = (array) ($f['final'] ?? []);
        $cells = [];
        foreach (FinalComparer::FIELDS as $field => [$key]) {
            $cells[$field] = ['likha' => (string) ($out[$key] ?? ''), 'final' => (string) ($final[$field] ?? ''), 'result' => $f['fields'][$field] ?? null];
        }
        $cells['proceed'] = ['likha' => ($f['likha_proceed'] ?? false) ? 'PROCEED' : 'HOLD', 'final' => (string) ($f['status'] ?? ''), 'result' => $f['fields']['proceed'] ?? null];

        return [
            'id' => $o->id, 'page' => $o->page, 'macro_id' => $o->macro_output_id, 'at' => $o->created_at?->format('Y-m-d H:i'), 'source' => $o->mode === ImportShadow::MODE ? 'import' : 'flow',
            'edited' => (bool) ($f['edited'] ?? false), 'edited_flags' => (array) ($f['edited_flags'] ?? []), 'cells' => $cells, 'title' => $this->title((array) $o->steps),
            'note_hit' => !empty($out['note_hit']), 'hold' => (array) ($out['hold'] ?? []),
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
