<?php

namespace Tests\Feature\NightRun;

use App\Models\MacroOutput;
use App\Models\NightAstraRow;
use App\Models\NightRunStep;
use App\Services\AstraEncoder;
use App\Services\MacroChecker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Ang replay command ng address rules: basa lang, nakapirming linya, bilang at order id lang.
 * Ang mga gabi ay binubuo sa pamamagitan ng totoong night job na may pekeng sagot ng model (naka-off ang switch,
 * gaya ng mga gabing ire-replay), kaya totoong log ang binabasa ng command. Gawa-gawa ang lahat ng customer.
 */
class AstraReplayCommandTest extends NightAstraTestCase
{
    private const COMMAND = 'astra:replay-address-rules';
    private const BAD_OPTIONS = "Give exactly one of --night=YYYY-MM-DD or --step=<id>, and it must be an existing Astra night.\n";
    private const TABLES = ['macro_output', 'ai_checker_logs', 'night_astra_rows', 'night_run_steps', 'app_settings', 'pancake_conversations'];
    private const EMPTY_LINE = ['province' => '', 'city' => '', 'barangay' => ''];
    private const MARKER = 'ZZMARKER';
    private const SQL_TEXT = "x'; DROP TABLE macro_output; --";

    private const ADDRESS    = 'Would pass the address rules under the new rules: ';
    private const EVERYTHING = 'Would pass everything under the new rules: ';
    private const HELD       = 'Held for a person that night: ';

    /** Mga request na ipinadala sa model. */
    private array $sent = [];
    private array $respond = [];

    // ═════════════════════════════════════════════════════════════════════
    //  Mga helper
    // ═════════════════════════════════════════════════════════════════════

    private function assertInList(string $province, string $city, string $barangay): void
    {
        $labels = MacroChecker::loadAddressMaps()['brgysByCityProv'][MacroChecker::normPlace($city) . '|' . MacroChecker::normProv($province)] ?? [];
        $this->assertContains($barangay, $labels, "{$province} | {$city} | {$barangay} ay wala sa jnt_address.txt");
    }

    /** Sagot ng model: buong line ng Holy Spirit, high confidence; pinapalitan kada row. */
    private function answer(array $over = [], array $form = [], array $jnt = []): array
    {
        return array_replace([
            'form' => array_replace([
                'name' => 'Juan Dela Cruz', 'phone' => '09171234567', 'house_number' => '12', 'purok_sitio' => '',
                'address' => 'Sampaguita St', 'brgy' => 'Holy Spirit', 'city' => 'Quezon City', 'province' => 'Metro Manila',
                'landmark' => '', 'price' => '', 'quantity' => '',
            ], $form),
            'jnt'          => array_replace(['province' => 'METRO-MANILA', 'city' => 'QUEZON-CITY', 'barangay' => 'HOLY SPIRIT'], $jnt),
            'intent'       => 'order',
            'issues'       => [],
            'needs_human'  => false,
            'human_reason' => '',
            'confidence'   => 'high',
            'evidence'     => 'sinabi ng customer',
        ], $over);
    }

    /** Iisang peke kada test: ang sagot ay ang kasalukuyang laman ng $this->respond. */
    private function fakeModel(): void
    {
        Http::fake(['api.openai.com/v1/responses' => function (Request $request) {
            $this->sent[] = $request;

            return Http::response(['id' => 'resp_1', 'output_text' => json_encode($this->respond), 'usage' => ['input_tokens' => 1000, 'output_tokens' => 100]]);
        }]);
    }

    private function chat(string $phone, string $addressLine = '12 Sampaguita St, Holy Spirit, Quezon City'): string
    {
        return "Juan Dela Cruz\n{$phone}\n{$addressLine}";
    }

    /** Ang anim na row na hinawakan para sa tao noong gabi (lumang rules), ayon sa pagkakasunod ng id 1 hanggang 6. */
    private function sixHeld(): array
    {
        return [
            // Ang "Holy Spirt" ng customer ay hindi nakita ng lumang text check; halos-tugma ito sa bagong guard.
            'barangay typo'   => [['all_user_input' => $this->chat('09171234501', '12 Sampaguita St, Holy Spirt, Quezon City')], $this->answer(['confidence' => 'medium'], ['phone' => '09171234501'])],
            'mappable form'   => [['all_user_input' => $this->chat('09171234502')], $this->answer(['confidence' => 'medium'], ['phone' => '09171234502'], self::EMPTY_LINE)],
            'unmappable form' => [['all_user_input' => $this->chat('09171234503', '12 Sampaguita St, Holy Spi, Quezon Ci')], $this->answer(['confidence' => 'medium'], ['phone' => '09171234503', 'brgy' => 'Holy Spi', 'city' => 'Quezon Ci', 'province' => 'Metro Man'], self::EMPTY_LINE)],
            'cancel'          => [['all_user_input' => $this->chat('09171234504')], $this->answer(['intent' => 'cancel'], ['phone' => '09171234504'])],
            'duplicate phone' => [['all_user_input' => $this->chat('09171234505')], $this->answer([], ['phone' => '09171234505'])],
            'COD blank'       => [['all_user_input' => $this->chat('09171234506'), 'COD' => ''], $this->answer([], ['phone' => '09171234506'])],
        ];
    }

    /** Apat pang hawak na row, id 7 hanggang 10: isa kada natitirang dahilan. */
    private function fourMoreHeld(): array
    {
        return [
            'the model asks for a person' => [['all_user_input' => $this->chat('09171234507')], $this->answer(['needs_human' => true, 'human_reason' => 'dalawang address ang ibinigay ng customer'], ['phone' => '09171234507'])],
            'unclear intent'              => [['all_user_input' => $this->chat('09171234508')], $this->answer(['intent' => 'unclear'], ['phone' => '09171234508'])],
            'barangay not in the text'    => [['all_user_input' => $this->chat('09171234509', '12 Sampaguita St, Quezon City')], $this->answer(['confidence' => 'medium'], ['phone' => '09171234509'])],
            'no name'                     => [['all_user_input' => "09171234510\n12 Sampaguita St, Holy Spirit, Quezon City"], $this->answer([], ['phone' => '09171234510', 'name' => ''])],
        ];
    }

    /**
     * Isang gabi sa pamamagitan ng night job: [pangalan => [mga field ng order, sagot ng model]].
     * Ibinabalik ang step, ang mga order ayon sa pangalan at ang mga spec (para sa kopya ng order).
     */
    private function seedNight(array $specs, array $stepExtra = []): array
    {
        $this->assertInList('METRO-MANILA', 'QUEZON-CITY', 'HOLY SPIRIT');
        if ($this->sent === [] && $this->respond === []) $this->fakeModel();
        $orders = [];
        foreach ($specs as $name => [$extra]) $orders[$name] = $this->order($extra);
        // Ang kapareho ng phone ng ika-5 row: order ng parehong petsa na PROCEED na at hindi kasama sa gabi.
        if (isset($specs['duplicate phone'])) $this->order(['FULL NAME' => 'Maria Santos', 'PHONE NUMBER' => '9171234505', 'STATUS' => 'PROCEED']);
        $step = $this->runningStep(array_map(fn (MacroOutput $o) => $o->id, array_values($orders)), $stepExtra);
        foreach ($specs as $name => [, $answer]) {
            $this->respond = $answer;
            $row = $this->work($orders[$name]->id);
            $this->assertSame('done', $row->state, $name);
        }

        return ['step' => $step, 'orders' => $orders, 'specs' => $specs];
    }

    /** [exit code, output] */
    private function replay(array $options): array
    {
        $code = Artisan::call(self::COMMAND, $options);

        // Ang console ay nagsusulat ng line ending ng makina (CRLF sa Windows): iisang anyo para sa mga paghahambing.
        return [$code, str_replace("
", "
", Artisan::output())];
    }

    private function replayNight(string $night = self::NIGHT): string
    {
        [$code, $out] = $this->replay(['--night' => $night]);
        $this->assertSame(0, $code, $out);

        return $out;
    }

    /** Ang natitira sa linyang nagsisimula sa $prefix (iisa lang dapat). */
    private function after(string $out, string $prefix): string
    {
        $hits = array_values(array_filter(explode("\n", $out), fn (string $line) => str_starts_with($line, $prefix)));
        $this->assertCount(1, $hits, $prefix);

        return substr($hits[0], strlen($prefix));
    }

    /** "4 (ids: 1, 2, 4, 5)" → [1, 2, 4, 5]; "0" → [] */
    private function ids(string $rest): array
    {
        $this->assertMatchesRegularExpression('/\A\d+( \(ids: \d+(, \d+)*\))?\z/', $rest);
        $ids = preg_match('/\(ids: ([\d, ]+)\)/', $rest, $m) === 1 ? array_map('intval', explode(', ', $m[1])) : [];
        $this->assertSame((int) $rest, count($ids));

        return $ids;
    }

    private function snapshot(): array
    {
        $all = [];
        foreach (self::TABLES as $table) $all[$table] = json_encode(DB::table($table)->get()->all());

        return $all;
    }

    private function listFingerprint(): string
    {
        return substr(hash('sha256', (string) file_get_contents(resource_path('views/macro_output/jnt_address.txt'))), 0, 12);
    }

    private function detailOf(int $orderId): array
    {
        return json_decode((string) DB::table('ai_checker_logs')->where('id', $this->rowFor($orderId)->log_id)->value('detail'), true);
    }

    /** Baguhin ang nakaimbak na log ng row (para gayahin ang log na isinulat bago ang `replay` block, o ibang list). */
    private function rewriteLog(int $orderId, callable $change): void
    {
        $detail = $change($this->detailOf($orderId));
        DB::table('ai_checker_logs')->where('id', $this->rowFor($orderId)->log_id)->update(['detail' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    private function asOlderLog(int $orderId): void
    {
        $this->rewriteLog($orderId, fn (array $d) => array_diff_key($d, ['replay' => true]));
    }

    // ═════════════════════════════════════════════════════════════════════
    //  S-31 — ang command
    // ═════════════════════════════════════════════════════════════════════

    public function test_S_31_1_the_night_option_and_the_step_option_print_the_same_report(): void
    {
        $night = $this->seedNight($this->sixHeld());

        [$byNightCode, $byNight] = $this->replay(['--night' => self::NIGHT]);
        [$byStepCode, $byStep]   = $this->replay(['--step' => (string) $night['step']->id]);

        $this->assertSame([0, 0], [$byNightCode, $byStepCode]);
        $this->assertSame($byNight, $byStep);
        $this->assertSame('6', $this->after($byNight, self::HELD));
    }

    public function test_S_31_2_wrong_options_give_one_fixed_line_and_exit_code_1(): void
    {
        $night = $this->seedNight(array_slice($this->sixHeld(), 0, 1, true));
        $other = NightRunStep::create(['night_date' => '2026-10-04', 'kind' => 'import', 'state' => 'done', 'trigger' => 'schedule', 'started_at' => now()]);
        $cases = [
            'no option'              => [],
            'both options'           => ['--night' => self::NIGHT, '--step' => (string) $night['step']->id],
            'a date in words'        => ['--night' => 'kagabi'],
            'a date that is not real' => ['--night' => '2026-02-30'],
            'a date with more text'  => ['--night' => self::NIGHT . ' 00:00'],
            'an empty date'          => ['--night' => ''],
            'a night without a run'  => ['--night' => '2026-10-01'],
            'an unknown step'        => ['--step' => '987654'],
            'a step in words'        => ['--step' => 'una'],
            'a step with sql text'   => ['--step' => '1 OR 1=1'],
            'a step of ten digits'   => ['--step' => '1000000000'],
            'a step of another kind' => ['--step' => (string) $other->id],
            'the night of another kind' => ['--night' => '2026-10-04'],
        ];

        foreach ($cases as $name => $options) {
            [$code, $out] = $this->replay($options);

            $this->assertSame([1, self::BAD_OPTIONS], [$code, $out], $name);
        }
    }

    public function test_S_31_3_the_command_only_runs_select_statements_and_changes_no_table_and_no_file(): void
    {
        $this->seedNight($this->sixHeld());
        $before    = $this->snapshot();
        $logFiles  = fn (): array => array_map(fn (string $f) => [$f, filesize($f), filemtime($f)], glob(storage_path('logs/*')) ?: []);
        $filesBefore = $logFiles();
        $logLines  = count($this->logLines);
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        $out = $this->replayNight();
        $ran = $statements;

        $this->assertSame('6', $this->after($out, self::HELD));
        $this->assertGreaterThan(5, count($ran));
        foreach ($ran as $sql) {
            $this->assertMatchesRegularExpression('/\A\s*select\b/i', $sql);
        }
        $this->assertSame($before, $this->snapshot());
        clearstatcache();
        $this->assertSame($filesBefore, $logFiles());
        $this->assertCount($logLines, $this->logLines);
    }

    public function test_S_31_4_the_command_works_without_an_api_key_and_sends_nothing(): void
    {
        $this->seedNight($this->sixHeld());
        $withKey = $this->replayNight();
        $this->sent = [];

        $this->withoutApiKey(function () use ($withKey) {
            [$code, $out] = $this->replay(['--night' => self::NIGHT]);

            $this->assertSame([0, $withKey], [$code, $out]);
        });

        $this->assertSame([], $this->sent);
        $this->assertSame('4 (ids: 1, 2, 4, 5)', $this->after($withKey, self::EVERYTHING));
    }

    public function test_S_31_5_no_text_of_a_customer_or_the_model_reaches_the_output_or_a_log_line(): void
    {
        $m = self::MARKER;
        DB::table('pancake_conversations')->insert(['pancake_page_id' => 'page-1', 'full_name' => $m . ' Profile', 'customers_chat' => 'Brgy Holy Spirit ' . $m]);
        $this->seedNight([
            'marked' => [
                ['all_user_input' => $this->chat('09171234501', '12 ' . $m . ' St, Holy Spirit, Quezon City'), 'fb_name' => $m . ' Profile',
                    'FULL NAME' => $m . ' Luma', 'ADDRESS' => $m . ' lumang address', 'CXD' => "---\nBrgy: Holy Spirit {$m}\n---"],
                $this->answer(
                    ['confidence' => 'medium', 'needs_human' => true, 'human_reason' => $m . ' dahilan', 'evidence' => $m . ' evidence', 'issues' => [$m . ' issue']],
                    ['name' => 'Juan ' . $m, 'address' => $m . ' St', 'landmark' => $m, 'phone' => '09171234501'],
                    self::EMPTY_LINE
                ),
            ],
        ]);
        $this->assertStringContainsString($m, (string) DB::table('ai_checker_logs')->value('detail'));
        $logLines = count($this->logLines);

        $out = $this->replayNight();

        $this->assertStringNotContainsString($m, $out);
        $this->assertCount($logLines, $this->logLines);
        $this->assertSame('1', $this->after($out, self::HELD));
        $this->assertSame($this->listFingerprint(), $this->after($out, 'List fingerprint: '));
        // Mga nakapirming salita, bilang at id lang: walang linyang may quote, at-sign o text na hindi ASCII.
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9 ,.:;()\'<>=\-\n]+\z/', $out);
    }

    public function test_S_31_6_an_empty_night_and_rows_without_a_log_an_order_or_the_replay_block_are_counted_not_errors(): void
    {
        $mappable = fn (int $n) => [['all_user_input' => $this->chat('091712345' . sprintf('%02d', $n))], $this->answer(['confidence' => 'medium'], ['phone' => '091712345' . sprintf('%02d', $n)], self::EMPTY_LINE)];
        $night = $this->seedNight(['whole' => $mappable(1), 'log gone' => $mappable(2), 'order gone' => $mappable(3), 'older log' => $mappable(4), 'broken log' => $mappable(5)]);
        DB::table('ai_checker_logs')->where('id', $this->rowFor(2)->log_id)->delete();
        DB::table('macro_output')->where('id', 3)->delete();
        $this->asOlderLog(4);
        DB::table('ai_checker_logs')->where('id', $this->rowFor(5)->log_id)->update(['detail' => '{"passes": [']);
        $this->runningStep([], ['night_date' => '2026-10-06']);

        $out   = $this->replayNight();
        $empty = $this->replayNight('2026-10-06');

        $this->assertSame('1 (ids: 2)', $this->after($out, 'Finished rows without a log: '));
        $this->assertSame('1 (ids: 3)', $this->after($out, 'Finished rows without an order: '));
        $this->assertSame('1 (ids: 5)', $this->after($out, 'Finished rows whose log could not be read: '));
        $this->assertSame('1 (ids: 4)', $this->after($out, 'Finished rows with an older log (the model\'s own request for a person is not recorded there, it is inferred): '));
        $this->assertSame('5', $this->after($out, self::HELD));
        $this->assertStringContainsString("Held for a person that night: 5\n  - could not be replayed (no log, no order or unreadable log): 3 (ids: 2, 3, 5)\n", $out);
        // Ang dalawang row na nabasa (buo, at ang mas lumang log) ay parehong papasa.
        $this->assertSame('2 (ids: 1, 4)', $this->after($out, self::EVERYTHING));
        $this->assertSame('0', $this->after($empty, 'Rows that night: '));
        $this->assertSame('0', $this->after($empty, self::HELD));
    }

    public function test_S_31_7_only_the_rows_of_that_nights_step_are_counted(): void
    {
        $done = ['state' => 'done', 'finished_at' => now()];
        $mine = $this->order();
        $this->runningStep([$mine->id], [], $done);
        $this->runningStep([$this->order()->id, $this->order()->id], ['night_date' => '2026-10-06'], $done);

        $out   = $this->replayNight();
        $other = $this->replayNight('2026-10-06');

        $this->assertSame(['2026-10-05', '1', '1 (ids: 1)'], [$this->after($out, 'Night: '), $this->after($out, 'Rows that night: '), $this->after($out, 'Finished rows without a log: ')]);
        $this->assertSame(['2026-10-06', '2', '2 (ids: 2, 3)'], [$this->after($other, 'Night: '), $this->after($other, 'Rows that night: '), $this->after($other, 'Finished rows without a log: ')]);
    }

    public function test_S_31_8_a_night_of_1500_rows_is_read_in_chunks(): void
    {
        $night = $this->seedNight(array_slice($this->sixHeld(), 1, 1, true));
        $logId = $this->rowFor(1)->log_id;
        $chat  = $this->chat('09171234502');
        $now   = now();
        foreach (array_chunk(range(2, 1500), 100) as $ids) {
            DB::table('macro_output')->insert(array_map(fn (int $id) => [
                'id' => $id, 'TIMESTAMP' => '21:14 04-10-2026', 'ts_date' => self::ORDERS, 'PAGE' => 'Likha Shop', 'ITEM_NAME' => 'Slimming Tea', 'COD' => '599', 'all_user_input' => $chat,
            ], $ids));
            DB::table('night_astra_rows')->insert(array_map(fn (int $id) => [
                'step_id' => $night['step']->id, 'macro_output_id' => $id, 'state' => 'done', 'proceed' => 0, 'log_id' => $logId, 'created_at' => $now, 'updated_at' => $now,
            ], $ids));
        }
        $rowReads = 0; $logReads = 0;
        DB::listen(function ($query) use (&$rowReads, &$logReads) {
            if (str_contains($query->sql, 'from "night_astra_rows"')) $rowReads++;
            if (str_contains($query->sql, 'from "ai_checker_logs"')) $logReads++;
        });

        $out = $this->replayNight();

        $this->assertSame(['1500', '1500'], [$this->after($out, 'Rows that night: '), $this->after($out, self::HELD)]);
        $this->assertSame(1500, (int) $this->after($out, self::EVERYTHING));
        // 1,500 row sa tig-200: walong basa ng mga row, at isang basa ng log kada chunk (hindi kada row).
        $this->assertSame([8, 8], [$rowReads, $logReads]);
    }

    public function test_S_31_9_the_log_the_night_row_points_to_is_used_and_the_switch_does_not_change_the_report(): void
    {
        $this->seedNight(array_slice($this->sixHeld(), 1, 1, true));
        // Pangalawang log ng parehong order (retry), mas bago: walang line na mamamapa kung ito ang babasahin.
        $second = (array) DB::table('ai_checker_logs')->first();
        $detail = json_decode((string) $second['detail'], true);
        $detail['form'] = array_replace($detail['form'], ['brgy' => 'Holy Spi', 'city' => 'Quezon Ci']);
        DB::table('ai_checker_logs')->insert(array_replace(array_diff_key($second, ['id' => true]), ['detail' => json_encode($detail)]));
        $this->assertSame(2, DB::table('ai_checker_logs')->where('macro_output_id', 1)->count());

        AstraEncoder::storeAddressRules(true);
        $on = $this->replayNight();
        AstraEncoder::storeAddressRules(false);
        $off = $this->replayNight();

        $this->assertSame('1 (ids: 1)', $this->after($on, self::EVERYTHING));
        $this->assertSame($on, $off);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  S-32 — ang mga bilang
    // ═════════════════════════════════════════════════════════════════════

    public function test_S_32_1_six_held_rows_give_the_expected_report(): void
    {
        $this->seedNight($this->sixHeld());
        $print = $this->listFingerprint();

        $out = $this->replayNight();

        // Hinango sa rules, row kada row: 1 typo → halos-tugma, pasado; 2 namapa ng program, pasado; 3 walang line;
        // 4 cancel na may line, pasado; 5 kaparehong phone (hindi na tinatanong), pasado; 6 walang COD → hawak ng huling check.
        $expected = <<<TXT
Astra address rules replay (read only, nothing is changed)
Night: 2026-10-05
Step id: 1
List fingerprint: {$print}
Rows that night: 6
  - finished: 6
  - failed: 0
  - skipped: 0
  - not run: 0
  - still waiting or running: 0
  - other: 0
Finished rows without a log: 0
Finished rows without an order: 0
Finished rows whose log could not be read: 0
Finished rows with an older log (the model's own request for a person is not recorded there, it is inferred): 0
Astra proceeded that night: 0
  - could not be replayed (no log, no order or unreadable log): 0
  - the new rules would not proceed: 0
Held for a person that night: 6
  - could not be replayed (no log, no order or unreadable log): 0
Would pass the address rules under the new rules: 5 (ids: 1, 2, 4, 5, 6)
  - of those, the barangay was accepted on high confidence, not from the customer's text: 0
Would pass everything under the new rules: 4 (ids: 1, 2, 4, 5)
First thing that would still hold each held row (the parts add up to the held rows):
  - the model itself asked for a person: 0
  - the customer's intent is unclear: 0
  - no line from the list: 1 (ids: 3)
  - the barangay is not in the customer's text: 0
  - a required field is blank (name, phone or address): 0
  - the final check (item, COD, shop details, blacklists): 1 (ids: 6)
  - nothing, the row would proceed: 4 (ids: 1, 2, 4, 5)
  - not replayed: 0
Where the line of the held rows came from:
  - the model: 4
  - the program, from Astra's form: 1
  - no line: 1
Older logs where the hold was taken as the program's own, not the model's: 0
  If the model itself asked for a person on one of these, that row would stay held: the counts above are an upper bound.
Rows where the model asked for a person and the log does not say why: 0
  - strict (the request always holds), would pass everything: 0
  - lenient (the request does not hold when the program found the line and the customer's text confirms it), would pass everything: 0
Held rows that staff have since set to PROCEED: 0
  - the new rules would also proceed: 0
  - with the same province, city and barangay as staff: 0
  - with a different province, city or barangay: 0
Held rows that staff have since set to CANNOT PROCEED: 0
  - would have passed the address rules: 0
  - would have passed everything: 0
Rows that could not be rebuilt exactly as they were that night: 0
  - the chat has a different length now: 0
  - the earlier conversation has a different length now: 0
  - the customer details have a different length now: 0
  - the customer details cannot be checked for older logs: 0
  - the list has changed, or the stored line is no longer in it: 0
Of the rows that would pass everything, the same phone is on another order of the same date today: 1 (ids: 5)
What a replay cannot know:
  - the earlier conversation as it was that night; it is read as it is today.
  - edits made to the chat or the customer details since that night.
  - earlier conversation the model fetched with its own tool.
  - the version of the list that night, for older logs.
  - item, COD, shop details and blacklists are read as they are today.
  - only nights whose logs still exist can be replayed; logs older than 90 days are deleted.

TXT;
        $this->assertSame(str_replace("\r\n", "\n", $expected), $out);
    }

    public function test_S_32_2_each_held_row_has_two_results_and_everything_is_never_more_than_the_address_rules(): void
    {
        // Ang ika-7 row: line ng model na wala sa text ng customer pero high confidence → pasado sa address rules sa exemption.
        $this->seedNight($this->sixHeld() + ['exempted' => [
            ['all_user_input' => $this->chat('09171234507', '12 Sampaguita St, Quezon City'), 'COD' => ''],
            $this->answer([], ['phone' => '09171234507']),
        ]]);

        $out = $this->replayNight();

        $address    = $this->ids($this->after($out, self::ADDRESS));
        $everything = $this->ids($this->after($out, self::EVERYTHING));
        $this->assertSame([[1, 2, 4, 5, 6, 7], [1, 2, 4, 5]], [$address, $everything]);
        $this->assertSame([], array_diff($everything, $address));
        $this->assertSame('1 (ids: 7)', $this->after($out, '  - of those, the barangay was accepted on high confidence, not from the customer\'s text: '));
    }

    public function test_S_32_3_rows_staff_set_to_proceed_are_compared_with_the_line_of_the_new_rules(): void
    {
        $this->seedNight($this->sixHeld());
        // 1: parehong line, ibang sulat (laki ng letra, tuldik, gitling); 2: ibang barangay; 3: PROCEED ng staff, walang line sa bagong rules.
        DB::table('macro_output')->where('id', 1)->update(['STATUS' => 'PROCEED', 'PROVINCE' => 'Métro Manila', 'CITY' => 'quezon city', 'BARANGAY' => 'Holy-Spirit']);
        DB::table('macro_output')->where('id', 2)->update(['STATUS' => 'PROCEED', 'PROVINCE' => 'METRO-MANILA', 'CITY' => 'QUEZON-CITY', 'BARANGAY' => 'COMMONWEALTH']);
        DB::table('macro_output')->where('id', 3)->update(['STATUS' => 'PROCEED', 'PROVINCE' => 'METRO-MANILA', 'CITY' => 'QUEZON-CITY', 'BARANGAY' => 'HOLY SPIRIT']);

        $out = $this->replayNight();

        $this->assertSame('3 (ids: 1, 2, 3)', $this->after($out, 'Held rows that staff have since set to PROCEED: '));
        $this->assertSame('2 (ids: 1, 2)', $this->after($out, '  - the new rules would also proceed: '));
        $this->assertSame('1 (ids: 1)', $this->after($out, '  - with the same province, city and barangay as staff: '));
        $this->assertSame('1 (ids: 2)', $this->after($out, '  - with a different province, city or barangay: '));
    }

    public function test_S_32_4_rows_staff_set_to_cannot_proceed_are_counted_apart(): void
    {
        $this->seedNight($this->sixHeld());
        DB::table('macro_output')->whereIn('id', [3, 4, 6])->update(['STATUS' => 'CANNOT PROCEED']);

        $out = $this->replayNight();

        $this->assertSame('3 (ids: 3, 4, 6)', $this->after($out, 'Held rows that staff have since set to CANNOT PROCEED: '));
        $this->assertSame('2 (ids: 4, 6)', $this->after($out, '  - would have passed the address rules: '));
        $this->assertSame('1 (ids: 4)', $this->after($out, '  - would have passed everything: '));
        $this->assertSame('0', $this->after($out, 'Held rows that staff have since set to PROCEED: '));
    }

    public function test_S_32_5_rows_astra_proceeded_that_night_still_proceed_and_one_that_would_not_is_listed(): void
    {
        $this->seedNight([
            'first'  => [['all_user_input' => $this->chat('09171234501')], $this->answer([], ['phone' => '09171234501'])],
            'second' => [['all_user_input' => $this->chat('09171234502')], $this->answer([], ['phone' => '09171234502'])],
        ]);
        $this->assertSame(['PROCEED', 'PROCEED'], DB::table('macro_output')->orderBy('id')->pluck('STATUS')->all());

        $out = $this->replayNight();
        // Ang COD ng ika-2 order ay nabura pagkatapos ng gabi: ang huling check ngayon ang hahawak dito.
        DB::table('macro_output')->where('id', 2)->update(['COD' => '']);
        $later = $this->replayNight();

        $this->assertSame(['2', '0', '0'], [$this->after($out, 'Astra proceeded that night: '), $this->after($out, '  - the new rules would not proceed: '), $this->after($out, self::HELD)]);
        $this->assertSame('1 (ids: 2)', $this->after($later, '  - the new rules would not proceed: '));
    }

    public function test_S_32_6_rows_whose_texts_or_list_changed_are_counted_as_not_rebuilt_exactly(): void
    {
        DB::table('pancake_conversations')->insert(['pancake_page_id' => 'page-1', 'full_name' => 'Juan Profile', 'customers_chat' => 'Brgy Holy Spirit po kami']);
        $row = fn (int $n, array $extra = []) => [
            ['all_user_input' => $this->chat('0917123450' . $n), 'CXD' => "---\nBrgy: Holy Spirit\n---"] + $extra,
            $this->answer(['confidence' => 'medium'], ['phone' => '0917123450' . $n], self::EMPTY_LINE),
        ];
        $this->seedNight(['history' => $row(1, ['fb_name' => 'Juan Profile']), 'details' => $row(2), 'list' => $row(3), 'chat' => $row(4), 'unchanged' => $row(5), 'older' => $row(6)]);
        $clean = $this->replayNight();

        DB::table('pancake_conversations')->update(['customers_chat' => 'Brgy Holy Spirit po kami, salamat']);
        DB::table('macro_output')->where('id', 2)->update(['CXD' => DB::raw("'---\nBrgy: Holy Spirit, Quezon City\n---\n' || \"CXD\"")]);
        $this->rewriteLog(3, function (array $d) {
            $d['replay']['list_crc'] = $d['replay']['list_crc'] + 1;

            return $d;
        });
        DB::table('macro_output')->where('id', 4)->update(['all_user_input' => $this->chat('09171234504') . ' po']);
        $this->asOlderLog(6);
        $out = $this->replayNight();

        $this->assertSame('0', $this->after($clean, 'Rows that could not be rebuilt exactly as they were that night: '));
        $this->assertSame('4 (ids: 1, 2, 3, 4)', $this->after($out, 'Rows that could not be rebuilt exactly as they were that night: '));
        $this->assertSame('1 (ids: 4)', $this->after($out, '  - the chat has a different length now: '));
        $this->assertSame('1 (ids: 1)', $this->after($out, '  - the earlier conversation has a different length now: '));
        $this->assertSame('1 (ids: 2)', $this->after($out, '  - the customer details have a different length now: '));
        $this->assertSame('1', $this->after($out, '  - the customer details cannot be checked for older logs: '));
        $this->assertSame('1 (ids: 3)', $this->after($out, '  - the list has changed, or the stored line is no longer in it: '));
        foreach ([
            '  - the earlier conversation as it was that night; it is read as it is today.',
            '  - edits made to the chat or the customer details since that night.',
            '  - earlier conversation the model fetched with its own tool.',
            '  - the version of the list that night, for older logs.',
        ] as $sentence) {
            $this->assertStringContainsString("\n" . $sentence . "\n", $out);
        }
    }

    public function test_S_32_7_the_first_blocking_reason_partitions_the_held_rows(): void
    {
        $this->seedNight($this->sixHeld() + $this->fourMoreHeld());

        $out = $this->replayNight();

        $parts = [
            'the model itself asked for a person'                   => [7],
            'the customer\'s intent is unclear'                     => [8],
            'no line from the list'                                 => [3],
            'the barangay is not in the customer\'s text'           => [9],
            'a required field is blank (name, phone or address)'    => [10],
            'the final check (item, COD, shop details, blacklists)' => [6],
            'nothing, the row would proceed'                        => [1, 2, 4, 5],
        ];
        $sum = 0;
        foreach ($parts as $label => $expected) {
            $ids = $this->ids($this->after($out, '  - ' . $label . ': '));
            $this->assertSame($expected, $ids, $label);
            $sum += count($ids);
        }
        $this->assertSame(['10', 10], [$this->after($out, self::HELD), $sum + (int) $this->after($out, '  - not replayed: ')]);
        $this->assertSame('1 (ids: 5)', $this->after($out, 'Of the rows that would pass everything, the same phone is on another order of the same date today: '));
    }

    public function test_S_32_8_an_older_log_with_the_models_own_flag_is_reported_strict_and_lenient(): void
    {
        $listOnly = ['confidence' => 'medium', 'needs_human' => true, 'human_reason' => 'walang makita sa list'];
        $this->seedNight([
            'flag, the program finds the line' => [['all_user_input' => $this->chat('09171234501')], $this->answer($listOnly, ['phone' => '09171234501'], self::EMPTY_LINE)],
            'flag, the model has the line'     => [['all_user_input' => $this->chat('09171234502')], $this->answer($listOnly, ['phone' => '09171234502'])],
            'no flag, the program held it'     => [['all_user_input' => $this->chat('09171234503')], $this->answer(['confidence' => 'medium'], ['phone' => '09171234503'], self::EMPTY_LINE)],
        ]);
        foreach ([1, 2, 3] as $id) {
            $this->asOlderLog($id);
            $this->assertTrue($this->detailOf($id)['passes'][0]['resolve'][0]['answer']['needs_human']);
        }

        $out = $this->replayNight();

        $this->assertSame('2 (ids: 1, 2)', $this->after($out, 'Rows where the model asked for a person and the log does not say why: '));
        $this->assertSame('0', $this->after($out, '  - strict (the request always holds), would pass everything: '));
        $this->assertSame('1 (ids: 1)', $this->after($out, '  - lenient (the request does not hold when the program found the line and the customer\'s text confirms it), would pass everything: '));
        $this->assertSame('1 (ids: 3)', $this->after($out, 'Older logs where the hold was taken as the program\'s own, not the model\'s: '));
        $this->assertStringContainsString('the counts above are an upper bound.', $out);
        // Ang bilang ng report ay ang mahigpit: ang row na kinuhang flag ng program lang ang papasa.
        $this->assertSame('1 (ids: 3)', $this->after($out, self::EVERYTHING));
        $this->assertSame('2 (ids: 1, 2)', $this->after($out, '  - the model itself asked for a person: '));
    }

    public function test_S_32_9_the_replay_decides_each_stored_answer_as_the_checker_does_with_the_switch_on(): void
    {
        $night = $this->seedNight($this->sixHeld() + $this->fourMoreHeld());
        // Ang checker, naka-on ang switch, sa kopya ng bawat order (gaya ng bago ang gabi) at sa parehong sagot.
        AstraEncoder::storeAddressRules(true);
        $maps = MacroChecker::loadAddressMaps();
        $proceeds = []; $hasLine = [];
        foreach ($night['specs'] as $name => [$extra, $answer]) {
            $copy = $this->order($extra);
            $this->respond = $answer;
            $result = (new AstraEncoder())->processRow($copy->id, $maps, 'https://likhaaitech.com');
            $seen   = (array) DB::table('macro_output')->where('id', $copy->id)->first();
            $id     = $night['orders'][$name]->id;
            if (str_starts_with((string) $result['log']['passes'][0]['resolve'][0]['map']['note'], 'J&T: ')) $hasLine[] = $id;
            if ($seen['STATUS'] === 'PROCEED') {
                $proceeds[] = $id;
                // Ang line ng checker ay inilalagay bilang line ng staff sa orihinal: dapat "pareho" ang sabihin ng replay.
                DB::table('macro_output')->where('id', $id)->update(['STATUS' => 'PROCEED', 'PROVINCE' => $seen['PROVINCE'], 'CITY' => $seen['CITY'], 'BARANGAY' => $seen['BARANGAY']]);
            }
        }
        $this->assertNotEmpty($proceeds);
        $this->assertNotSame($proceeds, $hasLine);

        $out = $this->replayNight();

        $this->assertSame($proceeds, $this->ids($this->after($out, self::EVERYTHING)));
        $this->assertSame($hasLine, $this->ids($this->after($out, self::ADDRESS)));
        $this->assertSame($proceeds, $this->ids($this->after($out, '  - with the same province, city and barangay as staff: ')));
        $this->assertSame('0', $this->after($out, '  - with a different province, city or barangay: '));
    }

    // ═════════════════════════════════════════════════════════════════════
    //  S-33 — hindi pinagkakatiwalaang text
    // ═════════════════════════════════════════════════════════════════════

    public function test_S_33_1_replay_sql_text_in_every_untrusted_place_is_only_ever_bound(): void
    {
        $sql = self::SQL_TEXT;
        DB::table('pancake_conversations')->insert(['pancake_page_id' => 'page-1', 'full_name' => $sql, 'customers_chat' => 'Brgy ' . $sql]);
        $this->seedNight(['sql' => [
            ['all_user_input' => $this->chat('09171234501', '12 Sampaguita St, ' . $sql), 'fb_name' => $sql, 'FULL NAME' => $sql, 'CXD' => "---\nBrgy: {$sql}\n---"],
            $this->answer(
                ['confidence' => 'medium', 'needs_human' => true, 'human_reason' => $sql, 'evidence' => $sql, 'issues' => [$sql]],
                ['name' => 'Juan Dela Cruz', 'phone' => '09171234501', 'brgy' => $sql, 'city' => $sql, 'province' => $sql, 'address' => $sql],
                ['province' => $sql, 'city' => $sql, 'barangay' => $sql]
            ),
        ]]);
        $before = $this->snapshot();
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        [$code, $out] = $this->replay(['--night' => self::NIGHT]);
        $ran = $statements;

        $this->assertSame(0, $code, $out);
        $this->assertNotEmpty($ran);
        foreach ($ran as $statement) {
            $this->assertStringNotContainsString('DROP TABLE', $statement);
            $this->assertStringNotContainsString('; --', $statement);
        }
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('1 (ids: 1)', $this->after($out, '  - the model itself asked for a person: '));
        $this->assertStringNotContainsString('DROP', $out);
    }
}
