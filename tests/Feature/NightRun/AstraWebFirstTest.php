<?php

namespace Tests\Feature\NightRun;

use App\Models\MacroOutput;
use App\Services\AstraAddressRules;
use App\Services\AstraEncoder;
use App\Services\MacroChecker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Ang ikatlong mode ng switch ng address rules: `2` = bagong rules + web search muna.
 * Ang OpenAI ay laging peke; ang address list ay ang totoong jnt_address.txt ng repo; gawa-gawa ang mga customer.
 * Ang chat ng mga test dito ay may kalye at lungsod lang (walang barangay), kaya ang barangay ng sagot ay wala sa
 * sulat ng customer maliban kung sinadya ng case.
 */
class AstraWebFirstTest extends NightAstraTestCase
{
    private const SWITCH        = 'astra_address_rules';
    private const WEB_PROCEED   = 'astra_web_barangay_proceed';
    private const HOST          = 'https://likhaaitech.com';
    private const SIX           = ['FULL NAME', 'PHONE NUMBER', 'ADDRESS', 'PROVINCE', 'CITY', 'BARANGAY'];
    private const NO_GATE       = ['hard' => [], 'soft' => []];
    private const SETTINGS      = '/encoder/checker_1/settings';
    private const SETTINGS_FORM = ['work' => 300, 'idle' => 1800, 'long' => 7200, 'shift_start' => '09:00', 'shift_end' => '18:00'];
    private const QC_HOLY       = ['METRO-MANILA', 'QUEZON-CITY', 'HOLY SPIRIT'];
    private const NO_BRGY_CHAT  = "Juan Dela Cruz\n09171234567\n12 Sampaguita St malapit sa palengke, Quezon City";
    private const WEB_LINE      = 'WEB: barangay from web search (single), confirm';

    private static ?array $maps = null;

    /** Mga request na ipinadala sa model mula noong huling takbo ng row. */
    private array $sent = [];
    /** Mga sagot ng pekeng model, sunod-sunod; ang huli ang inuulit. */
    private array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openai.key'                         => 'test-key-not-real',
            'services.openai.astra_encoder_model'         => 'gpt-6-astra',
            'services.openai.astra_encoder_effort'        => 'high',
            'services.openai.astra_encoder_max_web'       => 4,
            'services.openai.astra_encoder_max_web_first' => 5,
        ]);
        Http::fake(['api.openai.com/v1/responses' => function (Request $request) {
            $this->sent[] = $request;
            $reply = count($this->replies) > 1 ? array_shift($this->replies) : $this->replies[0];

            return $reply;
        }]);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Mga helper
    // ═════════════════════════════════════════════════════════════════════

    private function maps(): array
    {
        return self::$maps ??= MacroChecker::loadAddressMaps();
    }

    private function mode(string $value): void
    {
        DB::table('app_settings')->updateOrInsert(['key' => self::SWITCH], ['value' => $value]);
    }

    private function webProceed(string $value): void
    {
        DB::table('app_settings')->updateOrInsert(['key' => self::WEB_PROCEED], ['value' => $value]);
    }

    private function setting(string $key): ?string
    {
        return DB::table('app_settings')->where('key', $key)->value('value');
    }

    /** Sagot ng model: buong line ng Holy Spirit, high confidence, barangay na galing sa web (isang source). */
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
            'evidence'     => 'ayon sa web',
            'brgy_source'  => 'web',
            'web_basis'    => 'single',
        ], $over);
    }

    /** Sagot ng Responses API: $webCalls na web search at ang JSON ng $answer. */
    private function reply(array $answer, int $webCalls = 1)
    {
        $output = array_fill(0, $webCalls, ['type' => 'web_search_call', 'action' => ['query' => 'Sampaguita St Quezon City', 'sources' => [['url' => 'https://example.test/a']]]]);
        $output[] = ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($answer)]]];

        return Http::response(['id' => 'resp_1', 'output' => $output, 'usage' => ['input_tokens' => 1000, 'output_tokens' => 100]]);
    }

    private function orderOn(int $day, array $extra = []): MacroOutput
    {
        return $this->order(array_merge([
            'TIMESTAMP'      => sprintf('21:14 %02d-09-2026', $day),
            'ts_date'        => sprintf('2026-09-%02d', $day),
            'all_user_input' => self::NO_BRGY_CHAT,
        ], $extra));
    }

    /** Isang row sa row method ni Astra: ang nakikita ng tao, ang log at ang mga request. */
    private function ranRow(int $day, array $answer, array $extra = [], int $webCalls = 1): array
    {
        $order = $this->orderOn($day, $extra);
        $this->replies = [$this->reply($answer, $webCalls)];
        $this->sent    = [];
        $result = (new AstraEncoder())->processRow($order->id, $this->maps(), self::HOST);
        $row    = (array) DB::table('macro_output')->where('id', $order->id)->first();

        return [
            'line'     => [$row['PROVINCE'], $row['CITY'], $row['BARANGAY']],
            'STATUS'   => $row['STATUS'],
            'code'     => $row['APP SCRIPT CHECKER'],
            'note'     => (string) $row['AI ANALYZE'],
            'CXD'      => (string) $row['CXD'],
            'evidence' => $result['log']['evidence'],
            'replay'   => $result['log']['replay'],
            'proceed'  => $result['log']['passes'][0]['proceed'],
        ];
    }

    /** Ang pasya nang direkta (pure): blangko ang row, pasado ang huling check. */
    private function decided(array $answer, string $chat = self::NO_BRGY_CHAT, array $in = []): array
    {
        return AstraAddressRules::decide($in + [
            'rules' => 'new', 'web_first' => true, 'web_proceed' => '0',
            'answer' => $answer + ['human_kind' => ''], 'row' => array_fill_keys(self::SIX, ''),
            'chat' => $chat, 'history' => '', 'customer_blocks' => '', 'maps' => $this->maps(), 'list_crc' => 0,
        ], fn () => self::NO_GATE);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  1. Ang switch at ang settings page
    // ═════════════════════════════════════════════════════════════════════

    public function test_only_the_exact_values_1_and_2_are_a_mode_and_the_second_setting_reads_only_its_four_words(): void
    {
        $modes = [];
        foreach (['1', '2', '0', '', ' 2', '2 ', '02', '3', 'two', 'web-first', '[2]'] as $value) {
            $this->mode($value);
            $modes[$value] = [AstraEncoder::addressRulesMode(), AstraEncoder::addressRulesOn()];
        }
        $this->assertSame([
            '1' => ['1', true], '2' => ['2', true], '0' => ['0', false], '' => ['0', false], ' 2' => ['0', false], '2 ' => ['0', false],
            '02' => ['0', false], '3' => ['0', false], 'two' => ['0', false], 'web-first' => ['0', false], '[2]' => ['0', false],
        ], $modes);

        $this->assertSame('0', AstraEncoder::webBarangayProceed());
        $read = [];
        foreach (['0', 'official', 'several', 'single', '', '1', 'Official', 'single ', 'none', 'all'] as $value) {
            $this->webProceed($value);
            $read[$value] = AstraEncoder::webBarangayProceed();
        }
        $this->assertSame([
            '0' => '0', 'official' => 'official', 'several' => 'several', 'single' => 'single', '' => '0', '1' => '0',
            'Official' => '0', 'single ' => '0', 'none' => '0', 'all' => '0',
        ], $read);

        Schema::drop('app_settings');
        $this->assertSame(['0', '0'], [AstraEncoder::addressRulesMode(), AstraEncoder::webBarangayProceed()]);
    }

    public function test_the_ceo_saves_each_mode_and_the_second_setting_and_anything_else_is_refused(): void
    {
        $this->actingAs($this->user());
        $post = fn (array $fields) => $this->post(self::SETTINGS, self::SETTINGS_FORM + ['astra_address_rules_present' => '1'] + $fields)
            ->assertRedirect(self::SETTINGS)->assertSessionHas('settings_saved', true);

        $saved = [];
        foreach (['2', '1', '0'] as $mode) {
            foreach (['official', 'several', 'single', '0'] as $proceed) {
                $post(['astra_address_rules' => $mode, 'astra_web_barangay_proceed' => $proceed]);
                $saved[] = [$this->setting(self::SWITCH), $this->setting(self::WEB_PROCEED)];
            }
        }
        $expected = [];
        foreach (['2', '1', '0'] as $mode) {
            foreach (['official', 'several', 'single', '0'] as $proceed) $expected[] = [$mode, $proceed];
        }
        $this->assertSame($expected, $saved);

        // Ibang value: walang nababago sa naka-save.
        $this->mode('2');
        $this->webProceed('several');
        foreach (['3', 'web-first', 'true', '22', ['2']] as $junk) {
            $post(['astra_address_rules' => $junk, 'astra_web_barangay_proceed' => 'official']);
            $this->assertSame('2', $this->setting(self::SWITCH), json_encode($junk));
        }
        foreach (['1', 'all', 'none', 'OFFICIAL', ['single']] as $junk) {
            $post(['astra_address_rules' => '2', 'astra_web_barangay_proceed' => $junk]);
            $this->assertSame('official', $this->setting(self::WEB_PROCEED), json_encode($junk));
        }
        // Ang page na walang pangalawang setting sa form ay hindi ito ginagalaw.
        $post(['astra_address_rules' => '1']);
        $this->assertSame(['1', 'official'], [$this->setting(self::SWITCH), $this->setting(self::WEB_PROCEED)]);
    }

    public function test_another_role_cannot_save_the_mode_or_the_second_setting_and_sees_neither(): void
    {
        $this->actingAs($this->user('Marketing', 'marketing@example.test'));

        $this->post(self::SETTINGS, self::SETTINGS_FORM + ['astra_address_rules_present' => '1', 'astra_address_rules' => '2', 'astra_web_barangay_proceed' => 'single'])
            ->assertRedirect(self::SETTINGS);

        $this->assertSame([null, null], [$this->setting(self::SWITCH), $this->setting(self::WEB_PROCEED)]);
        $html = $this->settingsPage();
        $this->assertStringNotContainsString('astra_address_rules', $html);
        $this->assertStringNotContainsString('astra_web_barangay_proceed', $html);
    }

    public function test_the_page_offers_the_three_modes_and_the_four_values_with_the_saved_ones_selected(): void
    {
        $this->actingAs($this->user());
        $this->mode('2');
        $this->webProceed('several');

        $html = $this->settingsPage();

        preg_match_all('/<input\b[^>]*\bname="astra_address_rules"[^>]*>/', $html, $m);
        $radios = [];
        foreach ($m[0] as $input) {
            $this->assertStringContainsString('type="radio"', $input);
            preg_match('/\bvalue="([^"]*)"/', $input, $v);
            $radios[$v[1]] = preg_match('/\schecked\b/', $input) === 1;
        }
        $this->assertSame(['1' => false, '2' => true, '0' => false], $radios);
        foreach ([
            'New address rules for Astra (match like the classic checker)',
            'New address rules + web search first',
            'Off: Astra works as before. Turn on after reading the replay numbers.',
        ] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        preg_match('/<select\b[^>]*\bname="astra_web_barangay_proceed"[^>]*>(.*?)<\/select>/s', $html, $select);
        preg_match_all('/<option\s+value="([^"]*)"([^>]*)>/', $select[1], $o);
        $this->assertSame(['0', 'official', 'several', 'single'], $o[1]);
        $this->assertSame([false, false, true, false], array_map(fn ($attrs) => str_contains($attrs, 'selected'), $o[2]));
    }

    // ═════════════════════════════════════════════════════════════════════
    //  2. Ang request sa model at ang mga bagong field ng sagot
    // ═════════════════════════════════════════════════════════════════════

    public function test_in_mode_2_the_first_round_requires_web_search_and_later_rounds_choose_freely(): void
    {
        $this->mode('2');
        $order = $this->orderOn(1);
        $this->replies = [
            Http::response(['id' => 'resp_1', 'output' => [
                ['type' => 'web_search_call', 'action' => ['query' => 'Sampaguita St Quezon City barangay']],
                ['type' => 'function_call', 'name' => 'jnt_address_search', 'call_id' => 'call_1', 'arguments' => json_encode(['query' => 'holy spirit quezon'])],
            ]]),
            $this->reply($this->answer(), 0),
        ];

        $result = (new AstraEncoder())->processRow($order->id, $this->maps(), self::HOST);

        $this->assertCount(2, $this->sent);
        $this->assertSame(['type' => 'web_search'], $this->sent[0]['tool_choice']);
        $this->assertSame('auto', $this->sent[1]['tool_choice']);
        $this->assertSame([5, 5], [$this->sent[0]['max_tool_calls'], $this->sent[1]['max_tool_calls']]);
        $this->assertSame(['web_search', 'function', 'function'], array_column($this->sent[0]['tools'], 'type'));
        $this->assertStringEndsWith(AstraEncoder::NEW_RULES_PROMPT . AstraEncoder::WEB_FIRST_PROMPT, (string) $this->sent[0]['instructions']);
        $this->assertTrue($result['log']['replay']['web_forced']);
        $this->assertNotContains('WEB FIRST: hindi napilit ang web search', $result['log']['evidence']);
    }

    public function test_modes_1_and_off_send_the_request_of_today(): void
    {
        $seen = [];
        foreach (['1', '0'] as $i => $mode) {
            $this->mode($mode);
            $row = $this->ranRow($i + 1, $this->answer());
            $seen[$mode] = [$this->sent[0]['tool_choice'], $this->sent[0]['max_tool_calls'], str_contains((string) $this->sent[0]['instructions'], 'WEB SEARCH FIRST'), array_keys($row['replay'])];
        }

        $keys = ['rules', 'model_needs_human', 'model_human_kind', 'model_intent', 'label_source', 'guard', 'hay_chars', 'dup_phone_checked', 'list_crc'];
        $this->assertSame(['1' => ['auto', 4, false, $keys], '0' => ['auto', 4, false, $keys]], $seen);
    }

    public function test_when_the_api_refuses_the_forced_web_search_the_row_runs_with_the_request_of_mode_1_and_is_counted(): void
    {
        $this->mode('2');
        $order = $this->orderOn(1);
        $this->replies = [
            Http::response(['error' => ['message' => "Invalid value for 'tool_choice'.", 'type' => 'invalid_request_error', 'param' => 'tool_choice']], 400),
            $this->reply($this->answer(), 1),
        ];

        $result = (new AstraEncoder())->processRow($order->id, $this->maps(), self::HOST);

        $this->assertCount(2, $this->sent);
        $this->assertSame([['type' => 'web_search'], 'auto'], [$this->sent[0]['tool_choice'], $this->sent[1]['tool_choice']]);
        $this->assertNotSame('failed', $result['status']);
        $this->assertFalse($result['log']['replay']['web_forced']);
        $this->assertContains('WEB FIRST: hindi napilit ang web search', $result['log']['evidence']);
    }

    public function test_a_first_round_without_a_web_search_is_counted_as_not_forced(): void
    {
        $this->mode('2');

        $row = $this->ranRow(1, $this->answer(), [], 0);

        $this->assertFalse($row['replay']['web_forced']);
        $this->assertContains('WEB FIRST: hindi napilit ang web search', $row['evidence']);
    }

    public function test_the_new_answer_fields_are_read_only_in_mode_2_and_only_as_the_exact_words(): void
    {
        $this->mode('2');
        $read = [];
        $day  = 0;
        foreach ([
            ['customer', 'official'], ['web', 'several'], ['web', 'single'], ['none', 'none'],
            ['Web', 'Official'], [' web', 'single '], ['internet', 'many'], [['web'], ['official']], [true, 1], [null, null],
        ] as [$source, $basis]) {
            $row = $this->ranRow(++$day, $this->answer(['brgy_source' => $source, 'web_basis' => $basis]));
            $read[] = [$row['replay']['brgy_source'], $row['replay']['web_basis']];
        }
        $this->assertSame([
            ['customer', 'official'], ['web', 'several'], ['web', 'single'], ['none', 'none'],
            ['none', 'none'], ['none', 'none'], ['none', 'none'], ['none', 'none'], ['none', 'none'], ['none', 'none'],
        ], $read);

        // Mode 1: ang parehong sagot ay binabasa gaya ng dati — high confidence, kaya PROCEED, at walang bagong key sa log.
        $this->mode('1');
        $row = $this->ranRow(++$day, $this->answer());
        $this->assertSame(['PROCEED', 'exempt_high_confidence'], [$row['STATUS'], $row['replay']['guard']['result']]);
        $this->assertArrayNotHasKey('brgy_source', $row['replay']);
    }

    private function settingsPage(): string
    {
        $this->withoutVite();
        // Bawat view ay dumadaan sa composer ng AppServiceProvider na bumibilang ng tasks ng user.
        if (!Schema::hasTable('tasks')) {
            Schema::create('tasks', function (\Illuminate\Database\Schema\Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('status')->nullable();
                $t->timestamps();
            });
        }

        return $this->get(self::SETTINGS)->assertStatus(200)->getContent();
    }
}
