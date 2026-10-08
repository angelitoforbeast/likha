<?php

namespace App\Console\Commands;

use App\Services\AstraAddressRules;
use App\Services\AstraEncoder;
use App\Services\MacroChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Replay ng isang gabi ni Astra: ang mga NAKAIMBAK na sagot ng model ay pinadadaan ulit sa bagong address rules
 * para makita kung ilang row na hinawakan para sa tao ang magiging PROCEED.
 *
 * BASA LANG: puro SELECT, walang sulat sa database, walang cache, walang log, walang file, walang tawag sa model
 * (gumagana kahit walang API key). Hindi nito binabasa ang switch ng rules: pareho ang report naka-on man o hindi.
 *
 * Ang output ay nakapirming English na linya na may bilang at order id LANG. Ang chat, pangalan, address, dahilan
 * at evidence ay galing sa customer o sa model (hindi pinagkakatiwalaan): hindi kailanman inilalabas, pati ang
 * value ng option.
 */
class AstraReplayAddressRules extends Command
{
    protected $signature   = 'astra:replay-address-rules {--night= : Night date, YYYY-MM-DD} {--step= : Night step id}';
    protected $description = 'Read-only: replay a night\'s stored Astra answers through the new address rules';

    private const CHUNK       = 200;
    private const BAD_OPTIONS = 'Give exactly one of --night=YYYY-MM-DD or --step=<id>, and it must be an existing Astra night.';
    private const SIX         = ['FULL NAME', 'PHONE NUMBER', 'ADDRESS', 'PROVINCE', 'CITY', 'BARANGAY'];
    private const FORM_KEYS   = ['name', 'phone', 'house_number', 'purok_sitio', 'address', 'brgy', 'city', 'province', 'landmark', 'price', 'quantity'];
    /** Unang humahawak sa row, ayon sa pagkakasunod ng tingin. */
    private const REASONS = [
        'flag'    => 'the model itself asked for a person',
        'intent'  => 'the customer\'s intent is unclear',
        'no_line' => 'no line from the list',
        'guard'   => 'the barangay is not in the customer\'s text',
        'blank'   => 'a required field is blank (name, phone or address)',
        'gate'    => 'the final check (item, COD, shop details, blacklists)',
        'none'    => 'nothing, the row would proceed',
    ];

    private array $maps = [];
    private ?MacroChecker $checker = null;
    private int $listCrc = 0;

    public function handle(): int
    {
        try {
            $step = $this->findStep();
            if ($step === null) {
                $this->line(self::BAD_OPTIONS);

                return self::FAILURE;
            }
            // Ang buong report ay binubuo muna: kapag may error sa gitna, ang nag-iisang linya ng error lang ang lalabas.
            $lines = $this->report($step);
        } catch (\Throwable $e) {
            // Class lang ng error: ang message ay maaaring may laman ng row o ng path.
            $this->line('Replay stopped: ' . class_basename($e));

            return self::FAILURE;
        }
        foreach ($lines as $line) $this->line($line);

        return self::SUCCESS;
    }

    /** Ang step ng gabi (kind = astra), o null kapag mali ang options o walang ganoong step. */
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

        return $q->orderByDesc('id')->first(['id', 'night_date']);
    }

    private function report(object $step): array
    {
        $this->maps    = MacroChecker::loadAddressMaps();
        $this->listCrc = AstraEncoder::listCrc();
        // Iisang checker para sa buong takbo: ang mga blacklist ay isang beses lang binabasa. Host = app.url, gaya ng night job.
        $this->checker = new MacroChecker();
        $this->checker->setHost((string) config('app.url'));

        $states = ['done' => 0, 'failed' => 0, 'skipped' => 0, 'not_run' => 0, 'waiting' => 0, 'other' => 0];
        $ids = array_fill_keys([
            'no_log', 'no_order', 'unreadable', 'older', 'proceeded', 'proceeded_not_replayed', 'proceeded_would_not',
            'held', 'held_not_replayed', 'address', 'exempt', 'everything',
            'src_model', 'src_program_map', 'src_none', 'program_flag', 'unsaid', 'unsaid_lenient',
            'staff_proceed', 'staff_proceed_also', 'staff_same', 'staff_different',
            'staff_cannot', 'staff_cannot_address', 'staff_cannot_everything',
            'inexact', 'inexact_chat', 'inexact_history', 'inexact_cxd', 'cxd_unchecked', 'inexact_list', 'dup_phone',
        ], []);
        $reasons = array_fill_keys(array_keys(self::REASONS), []);

        $last = 0;
        do {
            $rows = DB::table('night_astra_rows')->where('step_id', $step->id)->where('id', '>', $last)
                ->orderBy('id')->limit(self::CHUNK)->get(['id', 'macro_output_id', 'state', 'proceed', 'log_id']);
            if ($rows->isEmpty()) break;
            $last = (int) $rows->last()->id;

            $done   = $rows->where('state', 'done');
            $logIds = $done->pluck('log_id')->filter()->unique()->values()->all();
            // Ang log na itinuturo ng night row mismo, kahit may iba pang log ang order (retry).
            $logs   = $logIds ? DB::table('ai_checker_logs')->whereIn('id', $logIds)->get(['id', 'detail'])->keyBy('id') : collect();
            $orderIds = $done->pluck('macro_output_id')->unique()->values()->all();
            $orders = $orderIds ? DB::table('macro_output')->whereIn('id', $orderIds)->get()->keyBy('id') : collect();

            foreach ($rows as $row) {
                $state = (string) $row->state;
                if ($state === 'queued' || $state === 'running') $states['waiting']++;
                elseif (isset($states[$state]) && $state !== 'waiting' && $state !== 'other') $states[$state]++;
                else $states['other']++;
                if ($state !== 'done') continue;

                $oid     = (int) $row->macro_output_id;
                $wasHeld = !$row->proceed;
                $ids[$wasHeld ? 'held' : 'proceeded'][] = $oid;

                $log    = $row->log_id !== null ? $logs->get($row->log_id) : null;
                $order  = $orders->get($oid);
                $detail = $log !== null ? json_decode((string) $log->detail, true) : null;
                $stored = is_array($detail) ? $this->storedAnswer($detail) : null;
                if ($log === null) $ids['no_log'][] = $oid;
                elseif ($order === null) $ids['no_order'][] = $oid;
                elseif ($stored === null) $ids['unreadable'][] = $oid;
                if ($log === null || $order === null || $stored === null) {
                    $ids[$wasHeld ? 'held_not_replayed' : 'proceeded_not_replayed'][] = $oid;
                    continue;
                }
                if (!$stored['has_block']) $ids['older'][] = $oid;
                if ($stored['program_flag']) $ids['program_flag'][] = $oid;

                // Ang tatlong text ay binabasa NGAYON: ang replay ay hindi nakakakita ng text gaya noong gabi.
                $texts = [
                    'chat'    => trim((string) $order->all_user_input),
                    'history' => AstraEncoder::pancakeHistory((string) ($order->fb_name ?? '')),
                    'cxd'     => AstraAddressRules::customerBlocks((string) ($order->CXD ?? '')),
                ];
                $this->noteInexact($ids, $oid, $stored, $texts);

                // Ang flag ng model na walang nakasulat na uri ng dahilan ay pinapasyahan nang dalawang beses; ang mahigpit ang bilang ng report.
                $unsaid = $stored['model_needs_human'] && $stored['human_kind'] === '';
                $d = $this->decide($stored, $unsaid ? 'other' : $stored['human_kind'], $order, $texts);
                if ($unsaid) {
                    $ids['unsaid'][] = $oid;
                    if ($this->decide($stored, 'label_not_found', $order, $texts)['proceed']) $ids['unsaid_lenient'][] = $oid;
                }

                if (!$wasHeld) {
                    if (!$d['proceed']) $ids['proceeded_would_not'][] = $oid;
                    continue;
                }

                $guard  = (string) $d['replay']['guard']['result'];
                $source = (string) $d['replay']['label_source'];
                $ids['src_' . $source][] = $oid;
                $passesAddress = $d['line'] !== null;
                if ($passesAddress) $ids['address'][] = $oid;
                if ($passesAddress && $guard === 'exempt_high_confidence') $ids['exempt'][] = $oid;
                if ($d['proceed']) $ids['everything'][] = $oid;

                $reason = match (true) {
                    $d['proceed']                                                           => 'none',
                    $d['replay']['model_needs_human'] && $d['needs_human']                  => 'flag',
                    !in_array($stored['answer']['intent'], ['order', 'cancel', 'inquiry_only'], true) => 'intent',
                    $source === 'none'                                                      => 'no_line',
                    !$passesAddress                                                         => 'guard',
                    !$d['all_filled']                                                       => 'blank',
                    default                                                                 => 'gate',
                };
                $reasons[$reason][] = $oid;

                if ($d['proceed']) {
                    // Impormasyon lang: ang kaparehong phone sa parehong petsa ay hindi na hold sa bagong rules; ang Validate ang huhuli.
                    $withDup = $this->checker->validateRow($order, $d['final'], $this->maps, true);
                    if (count($withDup['hard']) > count($d['gate']['hard'])) $ids['dup_phone'][] = $oid;
                }

                $staff = strtoupper(trim((string) ($order->STATUS ?? '')));
                if ($staff === 'PROCEED') {
                    $ids['staff_proceed'][] = $oid;
                    if ($d['proceed']) {
                        $ids['staff_proceed_also'][] = $oid;
                        $same = true;
                        foreach (['PROVINCE', 'CITY', 'BARANGAY'] as $i => $col) {
                            if (self::plain((string) $d['line'][$i]) !== self::plain((string) ($order->{$col} ?? ''))) $same = false;
                        }
                        $ids[$same ? 'staff_same' : 'staff_different'][] = $oid;
                    }
                } elseif ($staff === 'CANNOT PROCEED') {
                    $ids['staff_cannot'][] = $oid;
                    if ($passesAddress) $ids['staff_cannot_address'][] = $oid;
                    if ($d['proceed']) $ids['staff_cannot_everything'][] = $oid;
                }
            }
        } while ($rows->count() === self::CHUNK);

        $listFile = resource_path('views/macro_output/jnt_address.txt');
        $print    = is_file($listFile) ? substr((string) hash_file('sha256', $listFile), 0, 12) : 'not readable';
        $n = fn (string $label, array $list): string => $label . ': ' . count($list) . ($list ? ' (ids: ' . implode(', ', $list) . ')' : '');

        $out = [
            'Astra address rules replay (read only, nothing is changed)',
            'Night: ' . substr((string) $step->night_date, 0, 10),
            'Step id: ' . (int) $step->id,
            'List fingerprint: ' . $print,
            'Rows that night: ' . array_sum($states),
            '  - finished: ' . $states['done'],
            '  - failed: ' . $states['failed'],
            '  - skipped: ' . $states['skipped'],
            '  - not run: ' . $states['not_run'],
            '  - still waiting or running: ' . $states['waiting'],
            '  - other: ' . $states['other'],
            $n('Finished rows without a log', $ids['no_log']),
            $n('Finished rows without an order', $ids['no_order']),
            $n('Finished rows whose log could not be read', $ids['unreadable']),
            $n('Finished rows with an older log (the model\'s own request for a person is not recorded there, it is inferred)', $ids['older']),
            'Astra proceeded that night: ' . count($ids['proceeded']),
            $n('  - could not be replayed (no log, no order or unreadable log)', $ids['proceeded_not_replayed']),
            $n('  - the new rules would not proceed', $ids['proceeded_would_not']),
            'Held for a person that night: ' . count($ids['held']),
            $n('  - could not be replayed (no log, no order or unreadable log)', $ids['held_not_replayed']),
            $n('Would pass the address rules under the new rules', $ids['address']),
            $n('  - of those, the barangay was accepted on high confidence, not from the customer\'s text', $ids['exempt']),
            $n('Would pass everything under the new rules', $ids['everything']),
            'First thing that would still hold each held row (the parts add up to the held rows):',
        ];
        foreach (self::REASONS as $key => $label) $out[] = $n('  - ' . $label, $reasons[$key]);
        $out[] = '  - not replayed: ' . count($ids['held_not_replayed']);
        array_push($out,
            'Where the line of the held rows came from:',
            '  - the model: ' . count($ids['src_model']),
            '  - the program, from Astra\'s form: ' . count($ids['src_program_map']),
            '  - no line: ' . count($ids['src_none']),
            $n('Older logs where the hold was taken as the program\'s own, not the model\'s', $ids['program_flag']),
            '  If the model itself asked for a person on one of these, that row would stay held: the counts above are an upper bound.',
            "  For an older log, a row where the model wrote a reason but did not itself ask for a person is counted as 'the model itself asked for a person', so that part can be too high.",
            $n('Rows where the model asked for a person and the log does not say why', $ids['unsaid']),
            '  - strict (the request always holds): none of these rows would proceed',
            $n('  - lenient (the request does not hold when the program found the line and the customer\'s text confirms it), would pass everything', $ids['unsaid_lenient']),
            $n('Held rows that staff have since set to PROCEED', $ids['staff_proceed']),
            $n('  - the new rules would also proceed', $ids['staff_proceed_also']),
            $n('  - with the same province, city and barangay as staff', $ids['staff_same']),
            $n('  - with a different province, city or barangay', $ids['staff_different']),
            $n('Held rows that staff have since set to CANNOT PROCEED', $ids['staff_cannot']),
            $n('  - would have passed the address rules', $ids['staff_cannot_address']),
            $n('  - would have passed everything', $ids['staff_cannot_everything']),
            $n('Rows that could not be rebuilt exactly as they were that night', $ids['inexact']),
            $n('  - the chat has a different length now', $ids['inexact_chat']),
            $n('  - the earlier conversation has a different length now', $ids['inexact_history']),
            $n('  - the customer details have a different length now', $ids['inexact_cxd']),
            '  - the customer details cannot be checked for older logs: ' . count($ids['cxd_unchecked']),
            $n('  - the list has changed, or the stored line is no longer in it', $ids['inexact_list']),
            $n('Of the rows that would pass everything, the same phone is on another order of the same date today', $ids['dup_phone']),
            'What a replay cannot know:',
            '  - the earlier conversation as it was that night; it is read as it is today.',
            '  - edits made to the chat or the customer details since that night.',
            '  - earlier conversation the model fetched with its own tool.',
            '  - the version of the list that night, for older logs.',
            '  - item, COD, shop details and blacklists are read as they are today.',
            '  - only nights whose logs still exist can be replayed; logs older than 90 days are deleted.',
        );

        return $out;
    }

    /**
     * Ang nakaimbak na sagot, sa hugis na kailangan ng rules; null kapag kulang ang log.
     * Ang sariling flag ng model: mula sa `replay` block kung meron. Sa mas lumang log, hinuhulaan ito mula sa evidence
     * ARRAY, bawat elemento (hindi sa pinagdugtong na text, kung saan ang line break sa salita ng model ay maaaring
     * magmukhang linya ng program): flag ng program kapag may sariling linya ng program AT sariling salita ng program ang dahilan.
     */
    private function storedAnswer(array $detail): ?array
    {
        $pass   = $detail['passes'][0] ?? null;
        $answer = is_array($pass) ? ($pass['resolve'][0]['answer'] ?? null) : null;
        if (!is_array($answer) || !is_array($pass['before'] ?? null)) return null;

        $str  = fn ($v): string => is_scalar($v) ? (string) $v : '';
        $form = is_array($detail['form'] ?? null) ? $detail['form'] : ['brgy' => $answer['barangay'] ?? '', 'city' => $answer['city'] ?? '', 'province' => $answer['province'] ?? ''];
        $cleanForm = [];
        foreach (self::FORM_KEYS as $k) $cleanForm[$k] = $str($form[$k] ?? '');
        $jnt = is_array($answer['jnt'] ?? null) ? $answer['jnt'] : [];
        $before = [];
        foreach (self::SIX as $col) $before[$col] = trim($str($pass['before'][$col] ?? ''));

        $evidence = array_values(array_filter(is_array($detail['evidence'] ?? null) ? $detail['evidence'] : [], 'is_string'));
        $reason   = $str($answer['human_reason'] ?? '');
        $block    = is_array($detail['replay'] ?? null) ? $detail['replay'] : null;
        $programFlag = false;
        if ($block !== null) {
            $modelFlag = (bool) ($block['model_needs_human'] ?? false);
            $kind      = in_array($block['model_human_kind'] ?? '', ['label_not_found', 'other'], true) ? $block['model_human_kind'] : '';
        } else {
            $kind      = '';
            $modelFlag = (bool) ($answer['needs_human'] ?? false);
            if ($modelFlag) {
                $programLine = false;
                foreach ($evidence as $line) {
                    if (str_starts_with($line, 'GUARD: barangay "') || str_starts_with($line, 'CHECK: existing prov/city/brgy vs list')) $programLine = true;
                }
                if ($programLine && (str_starts_with($reason, 'kumpirmahin ang barangay (') || str_starts_with($reason, 'hindi matukoy ni Astra ang J&T label'))) {
                    $modelFlag = false; $programFlag = true;
                }
            }
        }

        // Ang haba ng history noong gabi: sa block, o sa sariling linya ng program sa evidence ng mas lumang log.
        $historyChars = null;
        if ($block !== null) {
            $historyChars = isset($block['hay_chars']['history']) ? (int) $block['hay_chars']['history'] : null;
        } else {
            foreach ($evidence as $line) {
                if ($line === 'PANCAKE: walang history') $historyChars = 0;
                elseif (preg_match('/\APANCAKE: (\d{1,9}) chars, kasama sa input\z/', $line, $m) === 1) $historyChars = (int) $m[1];
            }
        }

        return [
            'answer' => [
                'form'         => $cleanForm,
                'jnt'          => ['province' => $str($jnt['province'] ?? ''), 'city' => $str($jnt['city'] ?? ''), 'barangay' => $str($jnt['barangay'] ?? '')],
                'intent'       => $str($answer['intent'] ?? ''),
                'issues'       => array_values(array_filter(is_array($answer['issues'] ?? null) ? $answer['issues'] : [], 'is_string')),
                'needs_human'  => $modelFlag,
                // Ang dahilan ay sa model lang kapag ang flag ay sa model; ang sariling dahilan ng program ay muling bubuuin ng rules.
                'human_reason' => $modelFlag ? $reason : '',
                'confidence'   => $str($answer['confidence'] ?? ''),
                'evidence'     => $str($answer['evidence'] ?? ''),
            ],
            'before'            => $before,
            'has_block'         => $block !== null,
            'model_needs_human' => $modelFlag,
            'human_kind'        => $kind,
            'program_flag'      => $programFlag,
            'chat_chars'        => isset($pass['chat_chars']) ? (int) $pass['chat_chars'] : null,
            'history_chars'     => $historyChars,
            'cxd_chars'         => $block !== null && isset($block['hay_chars']['cxd']) ? (int) $block['hay_chars']['cxd'] : null,
            'list_crc'          => $block !== null ? (int) ($block['list_crc'] ?? 0) : null,
            // May line bang ginawa noong gabi? Kung oo, ang tatlong label nito ay nasa `after`.
            'night_line'        => str_starts_with($str($pass['resolve'][0]['map']['note'] ?? ''), 'J&T: ') && is_array($pass['after'] ?? null)
                ? ['province' => $str($pass['after']['PROVINCE'] ?? ''), 'city' => $str($pass['after']['CITY'] ?? ''), 'barangay' => $str($pass['after']['BARANGAY'] ?? '')]
                : null,
        ];
    }

    /** Ang parehong pasya ng checker, sa bagong rules, na may parehong gate (ang order gaya ng ngayon). */
    private function decide(array $stored, string $humanKind, object $order, array $texts): array
    {
        $maps = $this->maps;
        $mc   = $this->checker;

        return AstraAddressRules::decide([
            'rules'           => 'new',
            'answer'          => $stored['answer'] + ['human_kind' => $humanKind],
            'row'             => $stored['before'],
            'chat'            => $texts['chat'],
            'history'         => $texts['history'],
            'customer_blocks' => $texts['cxd'],
            'maps'            => $maps,
            'list_crc'        => $this->listCrc,
        ], fn (array $final, bool $checkDuplicatePhone): array => $mc->validateRow($order, $final, $maps, $checkDuplicatePhone));
    }

    /** Ang mga row na hindi maibabalik nang eksakto: iba na ang haba ng text, o iba na ang list. Isang beses lang sa kabuuan. */
    private function noteInexact(array &$ids, int $oid, array $stored, array $texts): void
    {
        $causes = [];
        if ($stored['chat_chars'] === null || mb_strlen($texts['chat']) !== $stored['chat_chars']) $causes[] = 'inexact_chat';
        if ($stored['history_chars'] === null || mb_strlen($texts['history']) !== $stored['history_chars']) $causes[] = 'inexact_history';
        if (!$stored['has_block']) $ids['cxd_unchecked'][] = $oid;   // walang haba ng customer details sa mas lumang log
        elseif ($stored['cxd_chars'] === null || mb_strlen($texts['cxd']) !== $stored['cxd_chars']) $causes[] = 'inexact_cxd';

        $listChanged = $stored['has_block'] && $stored['list_crc'] !== $this->listCrc;
        if (!$listChanged && $stored['night_line'] !== null) {
            [, , $brgy] = AstraAddressRules::validateJnt($stored['night_line'], $this->maps);
            $listChanged = $brgy === null;
        }
        if ($listChanged) $causes[] = 'inexact_list';

        foreach ($causes as $cause) $ids[$cause][] = $oid;
        if ($causes) $ids['inexact'][] = $oid;
    }

    /** Para sa paghahambing ng line: walang pakialam sa laki ng letra, sa tuldik/ñ, at sa gitling o espasyo. */
    private static function plain(string $s): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_replace('-', ' ', strtolower(Str::ascii($s)))));
    }
}
