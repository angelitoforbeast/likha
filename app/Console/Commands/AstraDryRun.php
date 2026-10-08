<?php

namespace App\Console\Commands;

use App\Models\MacroOutput;
use App\Services\AstraAddressRules;
use App\Services\AstraEncoder;
use App\Services\MacroChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Dry run ng BUONG bagong Astra para sa isang nakaraang gabi: bawat piniling row ay pinadadaan sa totoong tawag sa
 * model (may bagong instructions), sa parehong tools, sa bagong address rules at sa huling gate — naka-on man o
 * hindi ang switch — para makita kung ilan sa mga hindi nag-PROCEED noong gabi ang MAGPO-PROCEED sana ngayon.
 *
 * WALANG SULAT sa database: puro SELECT. Ang row ay binabasa, ibinabalik sa memory sa itsura nito bago ang gabi
 * (mula sa `before` ng log ng gabing iyon) at hindi kailanman sine-save. Ang tanging isinusulat ay ang report file.
 * Kapatid ito ng astra:replay-address-rules, na hindi tumatawag sa model; ito ay TUMATAWAG, kaya may gastos.
 *
 * Ang output at ang file ay id, bilang, flag at nakapirming salita ng dahilan LANG. Ang chat, pangalan, phone,
 * address, order number at ang sariling salita ng model ay galing sa labas (hindi pinagkakatiwalaan): hindi inilalabas.
 */
class AstraDryRun extends Command
{
    protected $signature = 'astra:dry-run {--night= : Night date, YYYY-MM-DD} {--step= : Night step id}
        {--rows=held : held (finished without PROCEED that night), proceeded, or all}
        {--limit=200 : Most rows to run}
        {--ids= : Only these macro_output ids, comma separated}
        {--mode=rules : rules (the new address rules) or web-first (the new address rules + web search first)}
        {--compare= : Path to an earlier report JSON of this command; adds a table of outcome then against outcome now}';
    protected $description = 'Dry run: the whole new Astra (real model calls) on a past night\'s rows, nothing is written to the orders';

    private const BAD_OPTIONS = 'Give exactly one of --night=YYYY-MM-DD or --step=<id> (an existing Astra night), --rows=held|proceeded|all, --limit=<1 or more>, --ids=<numbers, comma separated>, --mode=rules|web-first, --compare=<path to an earlier report JSON>.';
    private const SIX         = ['FULL NAME', 'PHONE NUMBER', 'ADDRESS', 'PROVINCE', 'CITY', 'BARANGAY'];
    private const MAX_ERRORS_IN_A_ROW = 3;
    /** Kapareho ng night job, para pareho ang hangganan ng bawat tawag. */
    private const HTTP_TIMEOUT_S = 120;
    /** Bawal magsimula sa oras ng night Astra (Manila): minuto mula hatinggabi. */
    private const QUIET_FROM = 150;   // 02:30
    private const QUIET_TO   = 270;   // 04:30
    /** Unang humahawak sa row, sa parehong salita at pagkakasunod ng replay. */
    private const REASONS = [
        'flag'    => 'the model itself asked for a person',
        'intent'  => 'the customer\'s intent is unclear',
        'no_line' => 'no line from the list',
        'guard'   => 'the barangay is not in the customer\'s text',
        'blank'   => 'a required field is blank (name, phone or address)',
        'gate'    => 'the final check (item, COD, shop details, blacklists)',
    ];
    /** Dagdag na dahilan sa web-first mode lang, para hindi magbago ang report ng --mode=rules. */
    private const WEB_REASONS = [
        'two_lines' => 'two different lines',
        'web'       => 'barangay from the web, waiting for a person',
    ];
    private const WEB_CAVEAT = 'The setting for barangays from the web is not read: in this run no barangay from the web proceeds; the "if allowed" lines count what each value of the setting would let through.';
    private const BASES       = ['official', 'several', 'single', 'none'];
    private const CONFIDENCES = ['high', 'medium', 'low'];
    /** Pinakamalaking report file na babasahin ng --compare. */
    private const COMPARE_MAX_BYTES = 20000000;

    /** --mode=web-first: ang request at ang rules ng mode `2` ng switch, anuman ang nakaimbak. */
    private bool $webFirst = false;

    private const CAVEATS = [
        'The chat and the earlier conversation are stored as one text without a time per message, so messages written after the night step started could not be left out: the model read both as they are today.',
        'Item, COD, page, shop details, customer details, blacklists and the J&T list are read as they are today; a same-phone order of the same date is not a hold under the new rules and is not checked.',
        'The six fields (name, phone, address, province, city, barangay) are put back from that night\'s log; STATUS is taken as blank, as it was when the night picked the row.',
        'The model does not answer the same way twice: a second run of the same rows can move a few rows either way.',
        'Web searches and the model\'s own fetch of the earlier conversation happen today, not that night.',
    ];

    public function handle(): int
    {
        $t0 = microtime(true);
        $opts = $this->readOptions();
        $step = $opts === null ? null : $this->findStep();
        if ($step === null) {
            $this->line(self::BAD_OPTIONS);

            return self::FAILURE;
        }

        $this->webFirst = $opts['mode'] === 'web-first';
        // Ang naunang report ay binabasa bago ang unang tawag: ang maling path ay hindi dapat matuklasan pagkatapos ng gastos.
        $earlier = $opts['compare'] === null ? null : $this->readEarlier($opts['compare']);
        if ($opts['compare'] !== null && $earlier === null) {
            $this->line('Not started: the --compare file could not be read as a report of this command.');

            return self::FAILURE;
        }

        // ── Mga harang bago ang unang tawag ──
        $now     = now('Asia/Manila');
        $minutes = $now->hour * 60 + $now->minute;
        if ($minutes >= self::QUIET_FROM && $minutes < self::QUIET_TO) {
            $this->line('Not started: between 02:30 and 04:30 Manila time the night Astra uses the model; run it after 04:30.');

            return self::FAILURE;
        }
        if (DB::table('night_run_steps')->where('kind', 'astra')->where('state', 'running')->exists()) {
            $this->line('Not started: a night Astra step is running; wait until it has finished.');

            return self::FAILURE;
        }
        // Ang key ay binabasa gaya ng night job at hindi kailanman inilalabas, kahit bahagi nito.
        if (AstraEncoder::resolveApiKey() === null) {
            $this->line('Not started: no API key set.');

            return self::FAILURE;
        }

        // Ang paghahanap sa J&T list ay nag-iimbak ng list sa cache; sa server ang cache ay maaaring table sa database.
        // Sa process na ito lang: sa memory, para walang kahit isang sulat.
        Cache::setDefaultDriver('array');

        $picked = $this->pickRows($step, $opts);
        $engine = AstraEncoder::engineSettings();
        $report = $this->emptyReport($step, $opts, $engine, $now->format('Y-m-d H:i'));
        $file   = storage_path('app/astra-dry-run/step-' . (int) $step->id . '-' . $now->format('Ymd-His') . '.json');

        $this->line('Astra dry run (nothing is written to the orders or the night tables)');
        $this->line('Night: ' . substr((string) $step->night_date, 0, 10) . ' · step id: ' . (int) $step->id . ' · rows: ' . $opts['rows']
            . ' · selected: ' . count($picked) . ' · model: ' . $engine['model'] . ' · effort: ' . $engine['effort']);
        if ($this->webFirst) $this->line('Mode: the new address rules + web search first');

        // Ang tagal ng mga row na ito noong gabi ang pinakamalapit na tantiya: dito ay sunod-sunod sila, hindi dalawa nang sabay.
        $nightSeconds = (int) round(array_sum(array_map(fn ($r) => (int) $r->duration_ms, $picked)) / 1000);
        $this->line('These rows took ' . $nightSeconds . ' s of model time that night; expect about ' . (int) ceil($nightSeconds / 60) . ' min, one row after another.');

        $maps    = MacroChecker::loadAddressMaps();
        $encoder = (new AstraEncoder())->httpTimeout(self::HTTP_TIMEOUT_S);
        $host    = (string) config('app.url');   // gaya ng night job: ang app.url ang pumipili ng blacklists
        $logIds  = array_values(array_filter(array_map(fn ($r) => $r->log_id, $picked)));
        $logs    = $logIds ? DB::table('ai_checker_logs')->whereIn('id', $logIds)->get(['id', 'detail'])->keyBy('id') : collect();

        $streak = 0;
        foreach ($picked as $nightRow) {
            $oid = (int) $nightRow->macro_output_id;
            $row = ['id' => $oid, 'night' => $nightRow->proceed ? 'proceeded' : 'held'];
            try {
                $row += $this->runRow($encoder, $nightRow, $logs, $maps, $host, $report);
            } catch (\Throwable $e) {
                // Class lang ng error: ang message ay maaaring may laman ng row.
                $row += ['outcome' => 'api_error', 'error' => class_basename($e)];
            }
            $report['rows'][] = $row;

            $this->line('row ' . $oid . ': ' . match ($row['outcome']) {
                'would_proceed' => 'would proceed',
                'held'          => 'held - ' . $this->reasons()[$row['reason']],
                'api_error'     => 'API error (' . $row['error'] . ')',
                default         => 'not tried (' . $row['reason'] . ')',
            });

            $streak = $row['outcome'] === 'api_error' ? $streak + 1 : 0;
            if ($streak >= self::MAX_ERRORS_IN_A_ROW) {
                $report['stopped'] = self::MAX_ERRORS_IN_A_ROW . ' API errors in a row';
            }
            // Isinusulat pagkatapos ng bawat row: ang takbong pinutol mula sa labas ay may maiiwang file.
            $report['elapsed_seconds'] = (int) round(microtime(true) - $t0);
            $this->writeFile($file, $report);
            if ($report['stopped'] !== null) break;
        }

        $report['elapsed_seconds'] = (int) round(microtime(true) - $t0);
        $summary = $this->summarize($report);
        if ($earlier !== null) $summary['compare'] = $this->compareWith($earlier, $report['rows']);
        $this->writeFile($file, $report + ['summary' => $summary]);
        foreach ($this->lines($report, $summary) as $line) $this->line($line);
        $this->line('Report file: storage/app/astra-dry-run/' . basename($file));

        return $report['stopped'] === null ? self::SUCCESS : self::FAILURE;
    }

    /** ['rows' => held|proceeded|all, 'limit' => int, 'ids' => int[]|null], o null kapag mali. */
    private function readOptions(): ?array
    {
        $rows  = $this->option('rows');
        $limit = $this->option('limit');
        $ids   = $this->option('ids');
        if (!is_string($rows) || !in_array($rows, ['held', 'proceeded', 'all'], true)) return null;
        if (!is_string($limit) || preg_match('/\A[1-9]\d{0,5}\z/', $limit) !== 1) return null;
        if ($ids !== null && (!is_string($ids) || preg_match('/\A\d{1,12}(,\d{1,12})*\z/', $ids) !== 1)) return null;
        $mode    = $this->option('mode');
        $compare = $this->option('compare');
        if (!is_string($mode) || !in_array($mode, ['rules', 'web-first'], true)) return null;
        if ($compare !== null && (!is_string($compare) || trim($compare) === '')) return null;

        return ['rows' => $rows, 'limit' => (int) $limit, 'ids' => $ids === null ? null : array_values(array_unique(array_map('intval', explode(',', $ids)))), 'mode' => $mode, 'compare' => $compare];
    }

    /** Ang step ng gabi (kind = astra), gaya ng replay; null kapag mali ang options o walang ganoong step. */
    private function findStep(): ?object
    {
        $night = $this->option('night');
        $id    = $this->option('step');
        if (($night === null) === ($id === null)) return null;

        $q = DB::table('night_run_steps')->where('kind', 'astra');
        if ($night !== null) {
            if (!is_string($night) || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $night, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return null;
            $q->whereDate('night_date', $night);
        } else {
            if (!is_string($id) || preg_match('/\A\d{1,9}\z/', $id) !== 1) return null;
            $q->where('id', (int) $id);
        }

        return $q->orderByDesc('id')->first(['id', 'night_date', 'started_at']);
    }

    /** Ang mga natapos na row ng gabi na pinili, hanggang --limit: ang hangganan ay nasa query, bago ang unang tawag. */
    private function pickRows(object $step, array $opts): array
    {
        $q = DB::table('night_astra_rows')->where('step_id', $step->id)->where('state', 'done');
        if ($opts['rows'] !== 'all') $q->where('proceed', $opts['rows'] === 'proceeded');
        if ($opts['ids'] !== null) $q->whereIn('macro_output_id', $opts['ids']);

        return $q->orderBy('id')->limit($opts['limit'])->get(['id', 'macro_output_id', 'proceed', 'log_id', 'duration_ms'])->all();
    }

    /** Isang row: ibalik sa itsura bago ang gabi (sa memory lang), ipasya, at ihambing sa row gaya ng ngayon. */
    private function runRow(AstraEncoder $encoder, object $nightRow, $logs, array $maps, string $host, array &$report): array
    {
        $order = MacroOutput::find((int) $nightRow->macro_output_id);
        if ($order === null) return ['outcome' => 'not_tried', 'reason' => 'the order no longer exists'];
        if (trim((string) $order->all_user_input) === '') return ['outcome' => 'not_tried', 'reason' => 'no chat text'];

        // Ang row gaya ng NGAYON (pagkatapos ng staff), bago ito palitan sa memory.
        $staffStatus = strtoupper(trim((string) ($order->STATUS ?? '')));
        $staff = [
            'status' => in_array($staffStatus, ['PROCEED', 'CANNOT PROCEED'], true) ? $staffStatus : 'other',
            'line'   => [(string) ($order->PROVINCE ?? ''), (string) ($order->CITY ?? ''), (string) ($order->BARANGAY ?? '')],
        ];

        $log    = $nightRow->log_id !== null ? $logs->get($nightRow->log_id) : null;
        $detail = $log !== null ? json_decode((string) $log->detail, true) : null;
        $before = is_array($detail) ? ($detail['passes'][0]['before'] ?? null) : null;
        $out    = ['before' => is_array($before) ? 'log' : 'as_today'];
        if (is_array($before)) {
            foreach (self::SIX as $col) $order->setAttribute($col, is_scalar($before[$col] ?? null) ? (string) $before[$col] : '');
        }
        // Blangko ang STATUS noong pinili ng gabi ang row. Hindi sine-save ang model na ito kahit kailan.
        $order->setAttribute('STATUS', null);

        $res = $encoder->dryRunRow($order, $maps, $host, 'new', $this->webFirst);
        $rowSearches = 0;
        foreach ($res['usage'] as $u) {
            $report['model_calls']++;
            $report['tokens_in']    += (int) $u['in'];
            $report['tokens_out']   += (int) $u['out'];
            $report['web_searches'] += (int) $u['searches'];
            $rowSearches            += (int) $u['searches'];
        }
        if ($this->webFirst) $out += ['web_searches' => $rowSearches, 'web_forced' => $res['web_forced'] === true];
        if (!$res['ok']) {
            $e = $res['error'];

            return $out + ['outcome' => 'api_error', 'error' => match (true) {
                $e === null                       => 'no_usable_answer',
                ($e['kind'] ?? '') === 'exception' => 'connection_or_timeout',
                default                            => 'http_' . (int) ($e['status'] ?? 0),
            }];
        }

        $d      = $res['decision'];
        $replay = $d['replay'];
        $out += [
            'staff_status' => $staff['status'],
            'label_source' => (string) $replay['label_source'],
            'guard'        => (string) $replay['guard']['result'],
            'texts'        => $this->textsThen($detail, $replay['hay_chars']),
        ];
        if (!$d['proceed']) {
            $reason = match (true) {
                $replay['model_needs_human'] && $d['needs_human'] => 'flag',
                !in_array($replay['model_intent'], ['order', 'cancel', 'inquiry_only'], true) => 'intent',
                $replay['label_source'] === 'none' => 'no_line',
                !empty($replay['two_lines'])       => 'two_lines',
                $d['line'] === null                => 'guard',
                !$d['all_filled']                  => 'blank',
                !empty($replay['web_only_obstacle']) => 'web',
                default                            => 'gate',
            };
            $held = $out + ['outcome' => 'held', 'reason' => $reason];
            // Ang row na ang barangay mula sa web na lang ang hadlang: ang isinulat sanang line ay ikinukumpara rin sa staff.
            if ($reason === 'web') {
                $held += ['web_basis' => (string) $replay['web_basis'], 'confidence' => (string) $replay['confidence']] + $this->againstStaff($d['line'], $staff['line']);
            }

            return $held;
        }

        return $out + ['outcome' => 'would_proceed'] + $this->againstStaff($d['line'], $staff['line']);
    }

    /** Ang line ng pasya laban sa line ng row ngayon: ['compare' => equal|differs|cannot_compare, 'differs' => mga bahagi]. */
    private function againstStaff(array $line, array $staffLine): array
    {
        $differs = [];
        foreach (['province', 'city', 'barangay'] as $i => $part) {
            if (self::plain((string) $line[$i]) !== self::plain($staffLine[$i])) $differs[] = $part;
        }
        $comparable = !in_array('', array_map('trim', $staffLine), true);

        return ['compare' => !$comparable ? 'cannot_compare' : ($differs ? 'differs' : 'equal'), 'differs' => $comparable ? $differs : []];
    }

    /** Ang mga dahilan ng report: sa web-first mode ay may dalawang dagdag. */
    private function reasons(): array
    {
        return $this->webFirst ? self::REASONS + self::WEB_REASONS : self::REASONS;
    }

    /**
     * Ang naunang report: [id => proceed|web|held|none]. Ang file ay data lang: id at nakapirming salita ang kinukuha,
     * wala nang iba. Null kapag hindi mabasa o hindi report ng command na ito.
     */
    private function readEarlier(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path) || filesize($path) > self::COMPARE_MAX_BYTES) return null;
        $json = json_decode((string) file_get_contents($path), true);
        if (!is_array($json) || ($json['command'] ?? null) !== 'astra:dry-run' || !is_array($json['rows'] ?? null)) return null;

        $earlier = [];
        foreach ($json['rows'] as $r) {
            if (!is_array($r) || !is_int($r['id'] ?? null)) continue;
            $earlier[$r['id']] = self::verdict($r);
        }

        return $earlier;
    }

    /** Ang kinalabasan ng isang row ng report sa apat na salita: proceed | web (barangay mula sa web, naghihintay) | held | none (walang pasya). */
    private static function verdict(array $r): string
    {
        return match (true) {
            ($r['outcome'] ?? null) === 'would_proceed' => 'proceed',
            ($r['outcome'] ?? null) === 'held'          => ($r['reason'] ?? null) === 'web' ? 'web' : 'held',
            default                                     => 'none',
        };
    }

    /** Para sa mga row na nasa parehong report: kinalabasan noon laban sa kinalabasan ngayon, ayon sa id. */
    private function compareWith(array $earlier, array $rows): array
    {
        $c = ['in_both' => 0, 'same' => [], 'no_verdict' => [], 'changes' => ['held>proceed' => [], 'held>web' => [], 'proceed>held' => []]];
        foreach ($rows as $r) {
            if (!isset($earlier[$r['id']])) continue;
            $c['in_both']++;
            [$then, $now] = [$earlier[$r['id']], self::verdict($r)];
            if ($then === 'none' || $now === 'none') $c['no_verdict'][] = $r['id'];
            elseif ($then === $now)                 $c['same'][] = $r['id'];
            else                                    $c['changes'][$then . '>' . $now][] = $r['id'];
        }

        return $c;
    }

    /**
     * Pareho pa ba ang haba ng tatlong text sa haba noong gabi? 'same' | 'changed' | 'unknown' (mas lumang log na walang haba).
     * Haba lang ang nakaimbak, kaya ito ang tanging paraan para malaman kung may nagbago mula noon.
     */
    private function textsThen(?array $detail, array $nowChars): string
    {
        $then = is_array($detail['replay']['hay_chars'] ?? null) ? $detail['replay']['hay_chars'] : null;
        if ($then === null) {
            $chat = $detail['passes'][0]['chat_chars'] ?? null;

            return $chat !== null && (int) $chat !== (int) $nowChars['chat'] ? 'changed' : 'unknown';
        }
        foreach (['chat', 'history', 'cxd'] as $k) {
            if (!isset($then[$k]) || (int) $then[$k] !== (int) $nowChars[$k]) return 'changed';
        }

        return 'same';
    }

    private function emptyReport(object $step, array $opts, array $engine, string $startedAt): array
    {
        return [
            'command'         => 'astra:dry-run',
            'night'           => substr((string) $step->night_date, 0, 10),
            'step_id'         => (int) $step->id,
            'step_started_at' => $step->started_at !== null ? (string) $step->started_at : null,
            'rows_option'     => $opts['rows'],
            'limit'           => $opts['limit'],
            'ids_option'      => $opts['ids'],
            'started_at'      => $startedAt,
            'model'           => $engine['model'],
            'effort'          => $engine['effort'],
            'model_calls'     => 0,
            'tokens_in'       => 0,
            'tokens_out'      => 0,
            'web_searches'    => 0,
            'elapsed_seconds' => 0,
            'stopped'         => null,
            'caveats'         => $this->webFirst ? array_merge(self::CAVEATS, [self::WEB_CAVEAT]) : self::CAVEATS,
            'rows'            => [],
        ] + ($this->webFirst ? ['mode' => 'web-first'] : []);
    }

    /** Ang mga bilang at listahan ng id ng report, mula sa mga row na natapos na. */
    private function summarize(array $report): array
    {
        $s = [
            'tried' => 0, 'not_tried' => [], 'would_proceed' => [], 'held' => [], 'api_errors' => [], 'api_error_classes' => [],
            'held_by_reason' => array_fill_keys(array_keys($this->reasons()), []),
            'held_that_night' => ['tried' => 0, 'would_proceed' => [], 'still_held' => [], 'still_held_staff_proceed' => []],
            'proceeded_that_night' => ['tried' => 0, 'would_proceed' => [], 'would_hold' => [], 'would_hold_by_reason' => array_fill_keys(array_keys($this->reasons()), [])],
            'would_proceed_staff' => ['PROCEED' => [], 'CANNOT PROCEED' => [], 'other' => []],
            'would_proceed_line' => ['equal' => [], 'differs' => [], 'cannot_compare' => [], 'equal_and_staff_proceed' => []],
            'would_proceed_differs_in' => ['province' => 0, 'city' => 0, 'barangay' => 0],
            'before_not_in_log' => [], 'texts_changed' => [], 'texts_unknown' => [],
        ];
        foreach ($report['rows'] as $r) {
            $id = $r['id'];
            if ($r['outcome'] === 'not_tried') { $s['not_tried'][] = $id; continue; }
            $s['tried']++;
            if (($r['before'] ?? '') === 'as_today') $s['before_not_in_log'][] = $id;
            if ($r['outcome'] === 'api_error') {
                $s['api_errors'][] = $id;
                $s['api_error_classes'][$r['error']] = ($s['api_error_classes'][$r['error']] ?? 0) + 1;
                continue;
            }
            if ($r['texts'] === 'changed') $s['texts_changed'][] = $id;
            if ($r['texts'] === 'unknown') $s['texts_unknown'][] = $id;
            $then = $r['night'] === 'held' ? 'held_that_night' : 'proceeded_that_night';
            $s[$then]['tried']++;

            if ($r['outcome'] === 'held') {
                $s['held'][] = $id;
                $s['held_by_reason'][$r['reason']][] = $id;
                if ($r['night'] === 'held') {
                    $s[$then]['still_held'][] = $id;
                    if ($r['staff_status'] === 'PROCEED') $s[$then]['still_held_staff_proceed'][] = $id;
                } else {
                    $s[$then]['would_hold'][] = $id;
                    $s[$then]['would_hold_by_reason'][$r['reason']][] = $id;
                }
                continue;
            }
            $s['would_proceed'][] = $id;
            $s[$then]['would_proceed'][] = $id;
            $s['would_proceed_staff'][$r['staff_status']][] = $id;
            $s['would_proceed_line'][$r['compare']][] = $id;
            if ($r['compare'] === 'equal' && $r['staff_status'] === 'PROCEED') $s['would_proceed_line']['equal_and_staff_proceed'][] = $id;
            foreach ($r['differs'] as $part) $s['would_proceed_differs_in'][$part]++;
        }
        if ($this->webFirst) $s['web_first'] = $this->summarizeWebFirst($report['rows']);

        return $s;
    }

    /** Ang dagdag ng web-first mode: ang mga row na may barangay mula sa web, ayon sa basehan at sa confidence ng model. */
    private function summarizeWebFirst(array $rows): array
    {
        $group = fn (): array => ['rows' => [], 'equal' => [], 'differs' => [], 'differs_in' => ['province' => 0, 'city' => 0, 'barangay' => 0], 'cannot_compare' => [], 'staff_cannot_proceed' => []];
        $w = [
            'proceed_outright' => [], 'web_wait' => [], 'not_forced' => [],
            'by_basis'      => array_map($group, array_fill_keys(self::BASES, null)),
            'by_confidence' => array_map($group, array_fill_keys(self::CONFIDENCES, null)),
            'if_allowed'    => array_fill_keys(['official', 'several', 'single'], ['would_proceed' => 0, 'equal_to_staff' => 0]),
            'web_searches_per_row' => ['rows' => 0, 'average' => 0.0, 'maximum' => 0],
        ];
        $searches = [];
        foreach ($rows as $r) {
            if (!in_array($r['outcome'], ['would_proceed', 'held'], true)) continue;
            $searches[] = (int) $r['web_searches'];
            if (!$r['web_forced']) $w['not_forced'][] = $r['id'];
            $web = $r['outcome'] === 'held' && $r['reason'] === 'web';
            if ($r['outcome'] === 'would_proceed') $w['proceed_outright'][] = $r['id'];
            if ($r['outcome'] === 'would_proceed' || $web) {
                foreach ($w['if_allowed'] as $setting => $_) {
                    if ($web && !AstraAddressRules::webMayProceed($r['web_basis'], $r['confidence'], $setting)) continue;
                    $w['if_allowed'][$setting]['would_proceed']++;
                    if ($r['compare'] === 'equal') $w['if_allowed'][$setting]['equal_to_staff']++;
                }
            }
            if (!$web) continue;
            $w['web_wait'][] = $r['id'];
            foreach ([['by_basis', $r['web_basis']], ['by_confidence', $r['confidence']]] as [$split, $key]) {
                $w[$split][$key]['rows'][] = $r['id'];
                $w[$split][$key][$r['compare']][] = $r['id'];
                foreach ($r['differs'] as $part) $w[$split][$key]['differs_in'][$part]++;
                if ($r['staff_status'] === 'CANNOT PROCEED') $w[$split][$key]['staff_cannot_proceed'][] = $r['id'];
            }
        }
        if ($searches) {
            $w['web_searches_per_row'] = ['rows' => count($searches), 'average' => round(array_sum($searches) / count($searches), 1), 'maximum' => max($searches)];
        }

        return $w;
    }

    private function lines(array $report, array $s): array
    {
        $n   = fn (string $label, array $list): string => $label . ': ' . count($list) . ($list ? ' (ids: ' . implode(', ', $list) . ')' : '');
        $out = [
            '',
            'Astra dry run report (nothing was written to the orders or the night tables)',
            'Night: ' . $report['night'] . ' · step id: ' . $report['step_id'] . ' · rows: ' . $report['rows_option'],
        ];
        if ($report['stopped'] !== null) $out[] = 'STOPPED EARLY: ' . $report['stopped'] . '. The counts below cover the rows done before the stop.';
        array_push($out,
            'Rows tried: ' . $s['tried'],
            $n('  - WOULD PROCEED with the new process', $s['would_proceed']),
            $n('  - would stay held', $s['held']),
            $n('  - failed with an API error', $s['api_errors']),
            $n('Rows not tried (the order no longer exists, or it has no chat text)', $s['not_tried']),
            'What would hold each held row (the first reason that stops it):',
        );
        foreach ($this->reasons() as $key => $label) $out[] = $n('  - ' . $label, $s['held_by_reason'][$key]);

        if ($report['rows_option'] !== 'proceeded') {
            array_push($out,
                'Rows Astra held that night, tried: ' . $s['held_that_night']['tried'],
                '  - would proceed now: ' . count($s['held_that_night']['would_proceed']),
                '  - would stay held: ' . count($s['held_that_night']['still_held']),
                $n('  - of those that stay held, staff have since set to PROCEED', $s['held_that_night']['still_held_staff_proceed']),
            );
        }
        if ($report['rows_option'] !== 'held') {
            array_push($out,
                'Rows Astra proceeded that night, tried: ' . $s['proceeded_that_night']['tried'],
                '  - would proceed again: ' . count($s['proceeded_that_night']['would_proceed']),
                $n('  - the new process would hold instead', $s['proceeded_that_night']['would_hold']),
            );
            foreach ($this->reasons() as $key => $label) $out[] = $n('      - ' . $label, $s['proceeded_that_night']['would_hold_by_reason'][$key]);
        }
        if (isset($s['web_first'])) {
            $w = $s['web_first'];
            array_push($out,
                'Web search first:',
                $n('  - would PROCEED outright', $w['proceed_outright']),
                $n('  - would be written with a barangay from the web and wait for a person', $w['web_wait']),
            );
            foreach (['by_basis' => 'by web_basis', 'by_confidence' => 'by the model\'s confidence'] as $split => $title) {
                $out[] = '  Those rows ' . $title . ', against the row as it is now (after staff):';
                foreach ($w[$split] as $key => $g) {
                    $out[] = $n('    - ' . $key, $g['rows']);
                    if (!$g['rows']) continue;
                    $out[] = '        province, city and barangay equal to the row now: ' . count($g['equal'])
                        . ' · a part differs: ' . count($g['differs']) . ' (province ' . $g['differs_in']['province'] . ', city ' . $g['differs_in']['city'] . ', barangay ' . $g['differs_in']['barangay'] . ')'
                        . ' · cannot compare: ' . count($g['cannot_compare']);
                    $out[] = $n('        staff status now CANNOT PROCEED', $g['staff_cannot_proceed']);
                }
            }
            $out[] = '  If barangays from the web were allowed to proceed (low confidence never is):';
            foreach ($w['if_allowed'] as $setting => $t) {
                $out[] = '    - at ' . $setting . ': ' . $t['would_proceed'] . ' would proceed, ' . $t['equal_to_staff'] . ' of those equal to the row now';
            }
            $out[] = $n('  - rows where web search could not be forced', $w['not_forced']);
            $out[] = '  - web searches per row: average ' . number_format($w['web_searches_per_row']['average'], 1) . ', maximum ' . $w['web_searches_per_row']['maximum'];
        }
        if (isset($s['compare'])) {
            $c = $s['compare'];
            $words = ['proceed' => 'would proceed', 'web' => 'barangay from the web', 'held' => 'held'];
            $out[] = 'Against the earlier report (rows in both: ' . $c['in_both'] . '):';
            foreach ($c['changes'] as $change => $ids) {
                [$then, $now] = explode('>', $change);
                $out[] = $n('  - ' . $words[$then] . ' then, ' . $words[$now] . ' now', $ids);
            }
            $out[] = '  - same outcome: ' . count($c['same']);
            $out[] = $n('  - no verdict in one of the two (API error or not tried)', $c['no_verdict']);
        }

        array_push($out,
            'The rows that would proceed, against the row as it is now (after staff):',
            '  - staff status now PROCEED: ' . count($s['would_proceed_staff']['PROCEED']),
            $n('  - staff status now CANNOT PROCEED', $s['would_proceed_staff']['CANNOT PROCEED']),
            '  - staff status now something else or blank: ' . count($s['would_proceed_staff']['other']),
            '  - province, city and barangay all equal to the row now: ' . count($s['would_proceed_line']['equal']),
            '      - of those, staff status now PROCEED: ' . count($s['would_proceed_line']['equal_and_staff_proceed']),
            $n('  - a part differs from the row now', $s['would_proceed_line']['differs']),
            '      - province differs: ' . $s['would_proceed_differs_in']['province'],
            '      - city differs: ' . $s['would_proceed_differs_in']['city'],
            '      - barangay differs: ' . $s['would_proceed_differs_in']['barangay'],
            $n('  - cannot compare (the row now has a blank province, city or barangay)', $s['would_proceed_line']['cannot_compare']),
            'Model: ' . $report['model'] . ' · effort: ' . $report['effort'],
            'Model calls: ' . $report['model_calls'] . ' · tokens in: ' . $report['tokens_in'] . ' · tokens out: ' . $report['tokens_out'] . ' · web searches: ' . $report['web_searches'],
        );
        $classes = [];
        foreach ($s['api_error_classes'] as $class => $count) $classes[] = $class . ' x' . $count;
        $out[] = 'API errors: ' . count($s['api_errors']) . ($classes ? ' (' . implode(', ', $classes) . ')' : '');
        $out[] = 'Elapsed: ' . $report['elapsed_seconds'] . ' s';
        $out[] = 'Caveats:';
        foreach ($report['caveats'] as $c) $out[] = '  - ' . $c;
        array_push($out,
            $n('  - Rows whose six fields could not be put back (no readable log of that night): judged as the row is today', $s['before_not_in_log']),
            $n('  - Rows whose chat, earlier conversation or customer details have a different length now than that night', $s['texts_changed']),
            $n('  - Rows with an older log, where only the length of the chat could be checked', $s['texts_unknown']),
        );

        return $out;
    }

    private function writeFile(string $file, array $report): void
    {
        File::ensureDirectoryExists(dirname($file));
        File::put($file, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** Para sa paghahambing ng line: walang pakialam sa laki ng letra, sa tuldik/ñ, at sa gitling o espasyo (gaya ng replay). */
    private static function plain(string $s): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_replace('-', ' ', strtolower(Str::ascii($s)))));
    }
}
