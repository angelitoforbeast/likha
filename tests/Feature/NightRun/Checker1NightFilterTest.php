<?php

namespace Tests\Feature\NightRun;

use App\Models\MacroOutput;
use App\Models\NightAstraRow;
use App\Models\NightRunStep;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

/**
 * Ang Checker 1 page (`/encoder/checker_1`) na may `night_step`: ang mga row ng gabi na iniwan ni Astra para sa tao.
 * Lahat ay sa totoong route; ang mga id na nakalista ay binabasa mula sa `<tr data-id>` ng sagot.
 *
 * Fixture (mga order ng self::ORDERS): A at B = done, hindi PROCEED (ang "mga row ng gabi"); C = done at PROCEED;
 * D = failed; E = skipped; F = wala sa kahit anong night row.
 */
class Checker1NightFilterTest extends NightAstraTestCase
{
    private const URL = '/encoder/checker_1';

    /** @var array<string, int> id ng bawat order ng fixture, ayon sa letra */
    private array $o = [];

    private NightRunStep $astra;

    protected function setUp(): void
    {
        parent::setUp();

        // Bawat view ay dumadaan sa composer ng AppServiceProvider na bumibilang ng tasks ng user.
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });

        $complete = [
            'FULL NAME' => 'Juan Dela Cruz', 'PHONE NUMBER' => '09171234567', 'ADDRESS' => '12 Sampaguita St',
            'PROVINCE' => 'METRO-MANILA', 'CITY' => 'QUEZON-CITY', 'BARANGAY' => 'HOLY SPIRIT',
        ];
        $orders = [
            'A' => ['PAGE' => 'Alpha Shop'],
            'B' => ['PAGE' => 'Beta Shop'] + $complete,
            'C' => ['PAGE' => 'Gamma Shop', 'STATUS' => 'PROCEED'] + $complete,
            'D' => ['PAGE' => 'Delta Shop', 'STATUS' => 'ODZ'],
            'E' => ['PAGE' => 'Alpha Shop'],
            'F' => ['PAGE' => 'Plain Shop', 'STATUS' => 'CANNOT PROCEED'],
        ];
        foreach ($orders as $letter => $extra) {
            $this->o[$letter] = $this->order($extra)->id;
        }

        $this->astra = $this->runningStep([], ['state' => 'finished', 'finished_at' => now(), 'rows_found' => 5]);
        $this->nightRow('A', 'done', false, 'TO FIX');
        $this->nightRow('B', 'done', false, 'ODZ');
        $this->nightRow('C', 'done', true, 'PROCEED');
        $this->nightRow('D', 'failed', false, null);
        $this->nightRow('E', 'skipped', false, null);
    }

    private function nightRow(string $letter, string $state, bool $proceed, ?string $code, array $extra = []): NightAstraRow
    {
        return NightAstraRow::create(array_merge([
            'step_id' => $this->astra->id, 'macro_output_id' => $this->o[$letter], 'state' => $state, 'proceed' => $proceed,
            'code' => $code, 'attempts' => 1, 'dispatched_at' => now(), 'started_at' => now(), 'finished_at' => now(),
        ], $extra));
    }

    /** Ang page, bilang isang user ng role na iyon. Ang `$query` ay string (hilaw na query string) o array. */
    private function page(array|string $query = [], string $role = 'CEO'): TestResponse
    {
        $email = strtolower(str_replace(' ', '', $role)) . '@example.test';
        $user  = User::where('email', $email)->first() ?? $this->user($role, $email);
        $qs    = is_array($query) ? http_build_query($query) : $query;

        return $this->actingAs($user)->get(self::URL . ($qs === '' ? '' : '?' . $qs));
    }

    /** Ang link ng gabi, na may dagdag na parameters. */
    private function link(array $extra = []): array
    {
        return array_merge(['date' => self::ORDERS, 'night_step' => $this->astra->id], $extra);
    }

    /** Mga id ng order na nakalista sa table, ayon sa pagkakasunod sa page. */
    private function ids(TestResponse|string $response): array
    {
        $html = is_string($response) ? $response : $response->getContent();
        preg_match_all('/<tr class="[^"]*" data-id="(\d+)">/', $html, $m);

        return array_map('intval', $m[1]);
    }

    /** Ang mga letra ng fixture bilang mga id, pinakabago muna (gaya ng page: id pababa). */
    private function letters(string $letters): array
    {
        $ids = array_map(fn ($letter) => $this->o[$letter], str_split($letters));
        rsort($ids);

        return $ids;
    }

    /** Ang anim na bilang sa mga chip: ['TOTAL' => n, ...]. */
    private function chips(TestResponse $response): array
    {
        preg_match_all('/<strong>([A-Z ]+):<\/strong>\s*(\d+)/', $response->getContent(), $m);

        return array_map('intval', array_combine($m[1], $m[2]));
    }

    /** Mga page sa Page dropdown (walang "All"). */
    private function pageList(TestResponse $response): array
    {
        preg_match_all('/class="page-dd-item[^"]*" data-value="([^"]+)"/', $response->getContent(), $m);

        return $m[1];
    }

    /** Ang buong `<tr>` ng isang order. */
    private function rowMarkup(TestResponse $response, int $id): string
    {
        $this->assertSame(1, preg_match('/<tr class="[^"]*" data-id="' . $id . '">.*?<\/tr>/s', $response->getContent(), $m), "row {$id}");

        return $m[0];
    }

    /** Bawat `<script>` block ng page, na pinapantay ang CSRF token (iba-iba ito kada session). */
    private function scripts(TestResponse $response): array
    {
        preg_match_all('/<script\b[^>]*>.*?<\/script>/s', $response->getContent(), $m);

        return array_map(fn ($block) => str_replace(csrf_token(), 'TOKEN', $block), $m[0]);
    }

    /** Marami pang order sa petsa ng fixture, isang insert. Ibinabalik ang mga id. */
    private function manyOrders(int $count, string $page): array
    {
        $first = (int) MacroOutput::max('id') + 1;
        $rows  = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['TIMESTAMP' => '21:14 04-10-2026', 'ts_date' => self::ORDERS, 'PAGE' => $page];
        }
        MacroOutput::insert($rows);

        return range($first, $first + $count - 1);
    }

    // ───────────── Hindi nagbabago kapag walang night_step ─────────────

    public function test_S_10_4_empty_or_absent_is_the_page_as_today(): void
    {
        $all = $this->letters('ABCDEF');

        foreach (['date=' . self::ORDERS, 'date=' . self::ORDERS . '&night_step=', 'date=' . self::ORDERS . '&night_step=%20%20'] as $query) {
            $response = $this->page($query)->assertOk();

            $this->assertSame($all, $this->ids($response), $query);
            $this->assertStringNotContainsString('Night run filter', $response->getContent(), $query);
        }
    }

    public function test_S_11_2_a_guest_is_sent_to_sign_in(): void
    {
        $response = $this->get(self::URL . '?' . http_build_query($this->link()));

        $response->assertRedirect(route('login'));
        $this->assertSame([], $this->ids($response));
        $this->assertStringNotContainsString('Alpha Shop', $response->getContent());
    }

    public function test_S_12_1_without_the_parameter_rows_chips_pages_and_pagination_are_as_today(): void
    {
        $response = $this->page(['date' => self::ORDERS])->assertOk();

        $this->assertSame($this->letters('ABCDEF'), $this->ids($response));
        $this->assertSame(
            ['TOTAL' => 6, 'PROCEED' => 1, 'CANNOT PROCEED' => 1, 'ODZ' => 1, 'BLANK' => 3, 'INCOMPLETE' => 2],
            $this->chips($response)
        );
        $this->assertSame(['Alpha Shop', 'Beta Shop', 'Delta Shop', 'Gamma Shop', 'Plain Shop'], $this->pageList($response));
        $this->assertStringNotContainsString('page=2', $response->getContent());

        // Higit sa 100 row at walang piniling Page: 100 kada page, at may link sa page 2.
        $this->manyOrders(100, 'Bulk Shop');
        $first  = $this->page(['date' => self::ORDERS]);
        $second = $this->page(['date' => self::ORDERS, 'page' => 2]);

        $this->assertCount(100, $this->ids($first));
        $this->assertStringContainsString('page=2', $first->getContent());
        $this->assertSame($this->letters('ABCDEF'), $this->ids($second));
    }

    public function test_S_12_2_without_the_parameter_no_night_table_is_read(): void
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        $response = $this->page(['date' => self::ORDERS])->assertOk();

        $this->assertNotEmpty($statements);
        foreach ($statements as $sql) {
            $this->assertStringNotContainsString('night_run_steps', $sql);
            $this->assertStringNotContainsString('night_astra_rows', $sql);
        }
        $this->assertStringNotContainsString('Night run filter', $response->getContent());
    }

    public function test_S_12_3_a_chosen_page_is_still_not_paginated(): void
    {
        $bulk = $this->manyOrders(101, 'Bulk Shop');

        $response = $this->page(['date' => self::ORDERS, 'PAGE' => 'Bulk Shop'])->assertOk();

        $this->assertCount(101, $this->ids($response));
        $this->assertSame(array_reverse($bulk), $this->ids($response));
        $this->assertStringNotContainsString('page=2', $response->getContent());
    }

    public function test_S_12_4_updating_a_field_of_a_listed_row_works_as_today(): void
    {
        $id = $this->o['A'];

        // Ang row sa page na may filter ay kapareho ng row sa page na wala: iisang markup ang pinanggagalingan ng request.
        $this->assertSame(
            $this->rowMarkup($this->page(['date' => self::ORDERS]), $id),
            $this->rowMarkup($this->page($this->link()), $id)
        );

        $this->postJson(route('macro_output.update_field'), ['id' => $id, 'field' => 'ADDRESS', 'value' => '45 Rizal Ave'])
            ->assertOk()->assertExactJson(['status' => 'success', 'changed' => true]);

        $row = DB::table('macro_output')->where('id', $id)->first();
        $this->assertSame('45 Rizal Ave', $row->ADDRESS);
        $this->assertSame('1', (string) $row->edited_address);
        $this->assertStringEndsWith('|ADDRESS||45 Rizal Ave', $row->{'HISTORICAL LOGS'});
        $this->assertSame(6, DB::table('macro_output')->count());
    }

    public function test_S_07_8_the_button_scripts_are_unchanged_and_carry_no_night_step(): void
    {
        // Ang bawat request ng toolbar ay binubuo ng isang script na may ganitong address.
        $builders = [
            'Validate 1'           => route('macro_output.validate1'),
            'Download'             => route('macro_output.download'),
            'VALIDATED badges'     => route('macro_output.validated_summary'),
            'AI Checker count'     => route('macro_checker.count'),
            'AI Checker start'     => route('macro_checker.start'),
            'Validate'             => route('macro_output.validate'),
            'ITEM CHECKER'         => '/macro_output/validate-items',
        ];

        foreach ([[], ['PAGE' => 'Alpha Shop']] as $extra) {
            $plain    = $this->scripts($this->page(['date' => self::ORDERS] + $extra));
            $filtered = $this->scripts($this->page($this->link($extra)));

            $this->assertSame($plain, $filtered);
            foreach ($builders as $name => $address) {
                $this->assertNotEmpty(array_filter($filtered, fn ($block) => str_contains($block, $address)), "{$name} script is on the page");
            }
            foreach ($filtered as $block) {
                $this->assertStringNotContainsString('night_step', $block);
            }
        }
    }
}
