<?php

namespace Tests\Feature\NightRun;

use App\Models\NightRunStep;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang `--labels` ng dry run: ang line ng J&T list na pinili ng bagong proseso, katabi ng line ng row ngayon.
 * Ang gabi ay binubuo ng totoong night job (naka-off ang switch, hawak ang bawat row), tapos "nag-edit ang staff",
 * tapos tumatakbo ang command kinaumagahan. Peke ang model; gawa-gawa ang lahat ng customer.
 */
class AstraDryRunLabelsTest extends NightAstraTestCase
{
    private const COMMAND = 'astra:dry-run';
    private const TABLES  = ['macro_output', 'ai_checker_logs', 'night_astra_rows', 'night_run_steps', 'app_settings', 'pancake_conversations', 'jobs'];
    private const MARKER  = 'ZZMARKER dalawang address ang ibinigay';
    private const HOLY    = ['province' => 'METRO-MANILA', 'city' => 'QUEZON-CITY', 'barangay' => 'HOLY SPIRIT'];
    /** Ang mga page na "ibinalik" ng web search: may path at query na may laman ng customer, at higit sa lima ang host. */
    private const SOURCES = [
        'HTTPS://PSA.gov.ph/classification/psgc/12-Sampaguita-St?q=Juan+Dela+Cruz+09171234501',
        'https://en.wikipedia.org/wiki/Holy_Spirit,_Quezon_City#History',
        'https://en.wikipedia.org/wiki/Quezon_City',
        'hindi ito url',
        'https://a.test/x', 'https://b.test/x', 'https://c.test/x', 'https://d.test/x',
    ];

    /** null = gabi (lahat hawak); kung hindi, [phone sa chat => sagot]. */
    private ?array $dryAnswers = null;
    private string $storage = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Ang report file ay napupunta sa sariling folder ng test, hindi sa storage ng project.
        $this->storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'astra-dry-run-labels-test-' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->storage . '/app');
        $this->app->useStoragePath($this->storage);

        Http::fake(['api.openai.com/v1/responses' => function (Request $request) {
            $this->assertSame(1, preg_match('/CHAT \(raw customer conversation\):\n<<<\n.*?(0917123\d{4})/s', (string) $request['input'], $m));
            $answer = $this->dryAnswers === null
                ? $this->answer(['needs_human' => true, 'human_reason' => 'hawak noong gabi'], $m[1])
                : $this->dryAnswers[$m[1]];
            $sources = array_map(fn (string $url) => ['url' => $url], self::SOURCES);

            return Http::response(['id' => 'resp_1', 'usage' => ['input_tokens' => 1000, 'output_tokens' => 100], 'output' => [
                ['type' => 'web_search_call', 'action' => ['query' => 'Sampaguita St Quezon City barangay', 'sources' => $sources]],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($answer)]]],
            ]]);
        }]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    /** Sagot ng model: buong line ng Holy Spirit, high confidence, barangay na galing sa web. */
    private function answer(array $over, string $phone, array $form = []): array
    {
        return array_replace([
            'form' => array_replace([
                'name' => 'Juan Dela Cruz', 'phone' => $phone, 'house_number' => '12', 'purok_sitio' => '',
                'address' => 'Sampaguita St', 'brgy' => 'Holy Spirit', 'city' => 'Quezon City', 'province' => 'Metro Manila',
                'landmark' => '', 'price' => '', 'quantity' => '',
            ], $form),
            'jnt'          => self::HOLY,
            'intent'       => 'order',
            'issues'       => [],
            'needs_human'  => false,
            'human_reason' => '',
            'confidence'   => 'high',
            'evidence'     => 'ayon sa web',
            'brgy_source'  => 'web',
            'web_basis'    => 'official',
        ], $over);
    }

    /** [exit code, output] */
    private function dryRun(array $options): array
    {
        $code = Artisan::call(self::COMMAND, $options);

        return [$code, str_replace("\r\n", "\n", Artisan::output())];
    }

    private function reportFile(): string
    {
        $files = File::glob($this->storage . '/app/astra-dry-run/*.json');
        $this->assertCount(1, $files);

        return (string) file_get_contents($files[0]);
    }

    /**
     * Ang maliit na gabi (phone …01 hanggang …03). Pagkatapos ng gabi ang line ng bawat row ay ang Holy Spirit ni Astra.
     *   1 = wala sa chat ang barangay, galing sa web (official, high); staff: PROCEED, ibang barangay
     *   2 = nasa chat ang barangay → PROCEED agad; staff: PROCEED, parehong line
     *   3 = humingi ng tao ang model at walang line (blangko ang jnt at ang address ng form)
     */
    private function fixtureNight(): NightRunStep
    {
        $orders = [
            1 => $this->order(['all_user_input' => "Juan Dela Cruz\n09171234501\n12 Sampaguita St malapit sa palengke, Quezon City"]),
            2 => $this->order(['all_user_input' => "Juan Dela Cruz\n09171234502\n12 Sampaguita St, Holy Spirit, Quezon City"]),
            3 => $this->order(['all_user_input' => "Juan Dela Cruz\n09171234503\n12 Sampaguita St malapit sa palengke"]),
        ];
        $step = $this->runningStep(array_map(fn ($o) => $o->id, array_values($orders)));
        foreach ($orders as $order) {
            $this->assertSame('done', $this->work($order->id)->state);
            $this->assertSame('HOLY SPIRIT', $order->fresh()->BARANGAY);
        }
        DB::table('night_run_steps')->where('id', $step->id)->update(['state' => 'finished', 'finished_at' => now()]);
        $this->at('10:00:00');

        DB::table('macro_output')->where('id', $orders[1]->id)->update(['STATUS' => 'PROCEED', 'BARANGAY' => 'BAGONG PAG-ASA']);
        DB::table('macro_output')->where('id', $orders[2]->id)->update(['STATUS' => 'PROCEED']);
        $this->dryAnswers = [
            '09171234501' => $this->answer([], '09171234501'),
            '09171234502' => $this->answer(['brgy_source' => 'customer', 'web_basis' => 'none'], '09171234502'),
            '09171234503' => $this->answer(
                ['jnt' => ['province' => '', 'city' => '', 'barangay' => ''], 'needs_human' => true, 'human_kind' => 'other', 'human_reason' => self::MARKER,
                    'confidence' => 'low', 'brgy_source' => 'none', 'web_basis' => 'none'],
                '09171234503',
                ['brgy' => '', 'city' => '', 'province' => '']
            ),
        ];

        return $step;
    }

    public function test_labels_puts_the_proposed_line_the_line_of_the_row_now_and_the_source_hosts_in_the_file_and_writes_nothing(): void
    {
        $step   = $this->fixtureNight();
        $before = [];
        foreach (self::TABLES as $table) $before[$table] = json_encode(DB::table($table)->orderBy('id')->get()->all());
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        [$code, $out] = $this->dryRun(['--step' => (string) $step->id, '--mode' => 'web-first', '--labels' => true]);

        $this->assertSame(0, $code, $out);
        $rows = json_decode($this->reportFile(), true)['rows'];
        $none = ['province' => null, 'city' => null, 'barangay' => null];
        // 1: barangay mula sa web. Host lang ng bawat page, walang ulit, hanggang lima; ang hindi URL ay hindi kasama.
        $this->assertSame(['held', 'web'], [$rows[0]['outcome'], $rows[0]['reason']]);
        $this->assertSame(self::HOLY, $rows[0]['proposed']);
        $this->assertSame(['province' => 'METRO-MANILA', 'city' => 'QUEZON-CITY', 'barangay' => 'BAGONG PAG-ASA'], $rows[0]['staff_now']);
        $this->assertSame(['psa.gov.ph', 'en.wikipedia.org', 'a.test', 'b.test', 'c.test'], $rows[0]['web_sources']);
        // 2: magpo-proceed; hindi galing sa web ang barangay, kaya walang listahan ng pinagkunan.
        $this->assertSame('would_proceed', $rows[1]['outcome']);
        $this->assertSame([self::HOLY, self::HOLY], [$rows[1]['proposed'], $rows[1]['staff_now']]);
        $this->assertArrayNotHasKey('web_sources', $rows[1]);
        // 3: hawak at walang line.
        $this->assertSame('held', $rows[2]['outcome']);
        $this->assertSame([$none, self::HOLY], [$rows[2]['proposed'], $rows[2]['staff_now']]);

        $this->assertNotEmpty($statements);
        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/\A\s*(select|pragma)\b/i', $sql, $sql);
        }
        foreach (self::TABLES as $table) $this->assertSame($before[$table], json_encode(DB::table($table)->orderBy('id')->get()->all()));
        Queue::assertNothingPushed();
    }

    public function test_labels_prints_one_table_and_without_the_option_neither_the_output_nor_the_file_has_the_labels(): void
    {
        $step = $this->fixtureNight();

        [, $out] = $this->dryRun(['--step' => (string) $step->id, '--mode' => 'web-first', '--labels' => true]);

        $holy = 'METRO-MANILA \/ QUEZON-CITY \/ HOLY SPIRIT';
        foreach ([
            ['id', 'outcome', 'reason group', 'source strength', 'confidence', 'proposed province \/ city \/ barangay', 'staff province \/ city \/ barangay', 'differs in'],
            ['1', 'held', 'barangay from the web, waiting for a person', 'official', 'high', $holy, 'METRO-MANILA \/ QUEZON-CITY \/ BAGONG PAG-ASA', 'barangay'],
            ['2', 'would proceed', '-', 'none', 'high', $holy, $holy, 'none'],
            ['3', 'held', 'the model itself asked for a person', 'none', 'low', '-', $holy, '-'],
        ] as $cells) {
            $this->assertMatchesRegularExpression('/^\|\s*' . implode('\s*\|\s*', $cells) . '\s*\|$/m', $out);
        }

        // Kahit sa takbo ng ilang id lang ay may table.
        File::deleteDirectory($this->storage . '/app/astra-dry-run');
        [, $one] = $this->dryRun(['--step' => (string) $step->id, '--ids' => '2', '--labels' => true]);
        $this->assertMatchesRegularExpression('/^\|\s*2\s*\|\s*would proceed\s*\|\s*-\s*\|\s*-\s*\|\s*-\s*\|\s*' . $holy . '\s*\|\s*' . $holy . '\s*\|\s*none\s*\|$/m', $one);
        $this->assertDoesNotMatchRegularExpression('/^\|\s*1\s*\|/m', $one);

        File::deleteDirectory($this->storage . '/app/astra-dry-run');
        [, $plain] = $this->dryRun(['--step' => (string) $step->id, '--mode' => 'web-first']);
        foreach (['reason group', 'HOLY SPIRIT', 'BAGONG', 'psa.gov.ph'] as $label) {
            $this->assertStringNotContainsString($label, $plain . $this->reportFile());
        }
        $this->assertArrayNotHasKey('proposed', json_decode($this->reportFile(), true)['rows'][0]);
    }

    public function test_with_labels_no_name_phone_street_chat_text_or_page_path_reaches_the_output_or_the_file(): void
    {
        $step = $this->fixtureNight();

        [, $out] = $this->dryRun(['--step' => (string) $step->id, '--mode' => 'web-first', '--labels' => true]);

        $all = $out . "\n" . $this->reportFile() . "\n" . json_encode($this->logLines->getArrayCopy());
        $this->assertStringContainsString('HOLY SPIRIT', $all);
        foreach ([
            'Juan', 'Dela Cruz', '0917123', '917123450', 'Sampaguita', 'palengke', 'Quezon City', 'ZZMARKER', 'dalawang address', 'ayon sa web',
            'psgc', 'wiki/', 'Holy_Spirit', '?q=', 'https', 'hindi ito url', 'Slimming', 'test-key-not-real',
        ] as $private) {
            $this->assertStringNotContainsStringIgnoringCase($private, $all);
        }
    }
}
