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

    /**
     * Isa pang gabi (2026-10-03, mga order ng 2026-10-02 — hindi "kahapon") na may isang row para sa tao.
     * @return array{0: int, 1: int} [id ng Astra step, id ng order]
     */
    private function secondNight(?int $stepId = null): array
    {
        $older  = ['ts_date' => '2026-10-02', 'TIMESTAMP' => '21:14 02-10-2026', 'PAGE' => 'Older Shop'];
        $order  = $this->order($older);
        $this->order($older); // parehong petsa at Page pero wala sa gabi: hindi dapat lumabas
        $stepId = DB::table('night_run_steps')->insertGetId(array_filter([
            'id' => $stepId, 'night_date' => '2026-10-03', 'kind' => 'astra', 'state' => 'finished', 'trigger' => 'schedule', 'rows_found' => 1,
        ]));
        NightAstraRow::create(['step_id' => $stepId, 'macro_output_id' => $order->id, 'state' => 'done', 'proceed' => false, 'code' => 'TO FIX', 'attempts' => 1]);

        return [$stepId, $order->id];
    }

    /** "Not valid": status 200, walang kahit isang row, at ang notice na puro nakapirming salita. */
    private function assertNotValid(TestResponse $response, string $label): void
    {
        $this->assertSame(200, $response->status(), $label);
        $this->assertSame([], $this->ids($response), $label);
        $this->assertSame('Night run filter not valid. No rows shown. · Show all rows', $this->line($response), $label);
        $this->assertDoesNotMatchRegularExpression('/\d+ of \d+ shown/', $response->getContent(), $label);
        $this->assertStringContainsString('<input type="hidden" name="night_step" id="nightStepHidden" value="0">', $this->form($response), $label);
    }

    private const WHOLE_DATE = 'Validate 1, Download and the AI buttons still use the whole date';

    /** Ang linya ng night filter bilang text (walang tags, isang space lang sa pagitan); null kapag wala sa page. */
    private function line(TestResponse $response): ?string
    {
        $count = preg_match_all('/<div id="nightFilterLine"[^>]*>(.*?)<\/div>/s', $response->getContent(), $m);
        $this->assertLessThanOrEqual(1, $count, 'one line at most');

        return $count === 0 ? null : trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1][0]), ENT_QUOTES)));
    }

    /** Ang address ng "Show all rows". */
    private function clearLink(TestResponse $response): string
    {
        $this->assertSame(1, preg_match('/<a href="([^"]*)"[^>]*>Show all rows<\/a>/', $response->getContent(), $m), 'Show all rows link');

        return html_entity_decode($m[1], ENT_QUOTES);
    }

    /** Ang GET form ng mga filter (date, Page, Filter). */
    private function form(TestResponse $response): string
    {
        $this->assertSame(1, preg_match('/<form id="filtersForm".*?<\/form>/s', $response->getContent(), $m));

        return $m[0];
    }

    /** `$count` na dagdag na row ng gabi (done, hindi PROCEED) sa Page na "Bulk Shop". Ibinabalik ang mga id ng order. */
    private function bulkNight(int $count): array
    {
        $bulk = $this->manyOrders($count, 'Bulk Shop');
        DB::table('night_astra_rows')->insert(array_map(fn ($id) => [
            'step_id' => $this->astra->id, 'macro_output_id' => $id, 'state' => 'done', 'proceed' => false, 'attempts' => 1,
        ], $bulk));

        return $bulk;
    }

    /** Bawat SQL statement ng mga request sa loob ng `$run`. */
    private function statements(callable $run): array
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });
        $run();

        return $statements;
    }

    // ───────────── Ang mga row ng gabi ─────────────

    public function test_S_06_1_the_link_lists_exactly_the_nights_rows(): void
    {
        $response = $this->page($this->link())->assertOk();

        $this->assertSame($this->letters('AB'), $this->ids($response));
    }

    public function test_S_06_2_a_row_edited_since_is_still_listed_with_its_current_values(): void
    {
        MacroOutput::where('id', $this->o['A'])->update(['STATUS' => 'PROCEED', 'ADDRESS' => '99 Bagong Kalye']);

        $response = $this->page($this->link())->assertOk();

        $this->assertSame($this->letters('AB'), $this->ids($response));
        $row = $this->rowMarkup($response, $this->o['A']);
        $this->assertStringContainsString('<option value="PROCEED" selected>', $row);
        $this->assertStringContainsString('>99 Bagong Kalye</textarea>', $row);
    }

    public function test_S_06_4_the_rows_found_equal_the_steps_for_person(): void
    {
        $forPerson = \App\Services\NightRunSummary::build(false)['nights'][0]['astra']['for_person'];

        $this->assertSame(2, $forPerson);
        $this->assertCount(2, $this->ids($this->page($this->link())));
    }

    public function test_S_06_5_a_row_with_an_empty_or_null_code_is_listed(): void
    {
        $this->o['G'] = $this->order()->id;
        $this->o['H'] = $this->order()->id;
        $this->nightRow('G', 'done', false, null);
        $this->nightRow('H', 'done', false, '');

        $this->assertSame($this->letters('ABGH'), $this->ids($this->page($this->link())));
    }

    public function test_S_06_6_a_deleted_order_is_absent_and_the_line_shows_the_gap(): void
    {
        MacroOutput::where('id', $this->o['B'])->delete();

        $response = $this->page($this->link())->assertOk();

        $this->assertSame($this->letters('A'), $this->ids($response));
        $this->assertStringContainsString('· 1 of 2 shown ·', (string) $this->line($response));
    }

    public function test_S_06_7_a_row_moved_to_another_date_is_not_listed(): void
    {
        MacroOutput::where('id', $this->o['A'])->update(['ts_date' => '2026-10-02']);

        $response = $this->page($this->link())->assertOk();

        $this->assertSame($this->letters('B'), $this->ids($response));
        $this->assertStringContainsString('· 1 of 2 shown ·', (string) $this->line($response));
    }

    public function test_S_06_8_more_than_100_rows_are_paged_and_keep_the_filter(): void
    {
        $bulk = $this->bulkNight(120);
        $this->manyOrders(30, 'Plain Shop'); // mga order na wala sa gabi: hindi dapat lumabas

        $first  = $this->page($this->link());
        $second = $this->page($this->link(['page' => 2]));

        $this->assertCount(100, $this->ids($first));
        $this->assertCount(22, $this->ids($second));
        $this->assertSame(1, preg_match('/href="([^"]*[?&;]page=2[^"]*)"/', $first->getContent(), $m), 'link to page 2');
        $this->assertStringContainsString('night_step=' . $this->astra->id, $m[1]);
        $expected = array_merge($bulk, [$this->o['A'], $this->o['B']]);
        rsort($expected);
        $this->assertSame($expected, array_merge($this->ids($first), $this->ids($second)));
    }

    // ───────────── Sa ilalim ng ibang filter ─────────────

    public function test_S_07_1_chips_and_page_list_count_the_filtered_set(): void
    {
        $response = $this->page($this->link())->assertOk();

        $this->assertSame(
            ['TOTAL' => 2, 'PROCEED' => 0, 'CANNOT PROCEED' => 0, 'ODZ' => 0, 'BLANK' => 2, 'INCOMPLETE' => 1],
            $this->chips($response)
        );
        $this->assertSame(['Alpha Shop', 'Beta Shop'], $this->pageList($response));
    }

    public function test_S_07_4_a_status_chip_on_top_is_the_intersection(): void
    {
        // Mag-isa: BLANK = A, B, E; INCOMPLETE = A, E. Ang gabi = A, B.
        $this->assertSame($this->letters('AB'), $this->ids($this->page($this->link(['status_filter' => 'BLANK']))));
        $this->assertSame($this->letters('A'), $this->ids($this->page($this->link(['status_filter' => 'INCOMPLETE']))));
        $this->assertSame([], $this->ids($this->page($this->link(['status_filter' => 'ODZ']))));
    }

    public function test_S_07_6_without_a_date_the_steps_orders_date_is_used(): void
    {
        [$stepId, $orderId] = $this->secondNight();
        $kept = ['PAGE' => 'Older Shop', 'checker' => '__BLANK__', 'status_filter' => 'BLANK', 'page' => '1'];

        $redirect = $this->page(['night_step' => $stepId] + $kept);

        $redirect->assertRedirect();
        $target = $redirect->headers->get('Location');
        $this->assertSame(self::URL, parse_url($target, PHP_URL_PATH));
        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
        ksort($query);
        $expected = ['night_step' => (string) $stepId, 'date' => '2026-10-02'] + $kept;
        ksort($expected);
        $this->assertSame($expected, $query);

        $response = $this->get($target)->assertOk();
        $this->assertSame([$orderId], $this->ids($response));
        $this->assertStringContainsString('name="date" value="2026-10-02"', $response->getContent());

        // Blangkong `date`, at mga parameter na numero ang pangalan o array ang laman: redirect pa rin, dala pa rin ang mga ito.
        foreach (['date=', 'date=%20', '5[]=a&7=b'] as $other) {
            $redirect = $this->page('night_step=' . $stepId . '&' . $other);

            $redirect->assertRedirect();
            parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);
            $this->assertSame('2026-10-02', $query['date'] ?? null, $other);
            $this->assertSame((string) $stepId, $query['night_step'] ?? null, $other);
            if ($other === '5[]=a&7=b') {
                $this->assertSame(['a'], $query[5] ?? null);
                $this->assertSame('b', $query[7] ?? null);
            }
        }
    }

    public function test_S_07_7_another_date_gives_the_intersection_and_says_so(): void
    {
        // Ang A ay inilipat sa ibang petsa pagkatapos ng gabi; may isa pang order sa petsang iyon na wala sa gabi.
        MacroOutput::where('id', $this->o['A'])->update(['ts_date' => '2026-10-02']);
        $this->order(['ts_date' => '2026-10-02', 'TIMESTAMP' => '21:14 02-10-2026']);

        $response = $this->page($this->link(['date' => '2026-10-02']))->assertOk();

        $this->assertSame($this->letters('A'), $this->ids($response));
        $this->assertSame(
            "Night run filter · night of Mon, Oct 5 (orders of Oct 4) · 1 of 2 shown · this date is not the night's orders date · " . self::WHOLE_DATE . ' · Show all rows',
            $this->line($response)
        );
        $this->assertSame([], $this->ids($this->page($this->link(['date' => '2026-10-01']))->assertOk()));

        // Parehong araw na iba lang ang pagkakasulat: hindi ito "ibang petsa".
        $this->assertStringNotContainsString('this date is not', (string) $this->line($this->page($this->link(['date' => '2026-10-4']))->assertOk()));
    }

    public function test_S_06_3_the_filtered_table_has_the_same_columns_and_buttons(): void
    {
        $plain    = $this->page(['date' => self::ORDERS]);
        $filtered = $this->page($this->link());
        $controls = function (TestResponse $response): array {
            preg_match_all('/<(?:th|button|select)\b[^>]*>/', $response->getContent(), $m);

            // Ang mga control ng bawat row (may data-id) ay ikinukumpara nang hiwalay, kada row, sa ibaba.
            return array_values(array_filter($m[0], fn ($tag) => !str_contains($tag, 'data-id=')));
        };

        $this->assertNotNull($this->line($filtered), 'the filter is on');
        $this->assertCount(12, array_filter($controls($filtered), fn ($tag) => str_starts_with($tag, '<th')), 'twelve columns for the CEO');
        $this->assertSame($controls($plain), $controls($filtered));
        $this->assertStringContainsString('id="downloadBtn"', $filtered->getContent());
        foreach (['A', 'B'] as $letter) {
            $this->assertSame($this->rowMarkup($plain, $this->o[$letter]), $this->rowMarkup($filtered, $this->o[$letter]));
        }
    }

    public function test_S_07_2_choosing_a_page_keeps_the_filter_without_pagination(): void
    {
        $bulk = $this->bulkNight(120);

        // Ang Page dropdown ay nagsu-submit ng form: dala nito ang hidden na night_step.
        $this->assertStringContainsString(
            '<input type="hidden" name="night_step" id="nightStepHidden" value="' . $this->astra->id . '">',
            $this->form($this->page($this->link()))
        );

        $response = $this->page($this->link(['PAGE' => 'Bulk Shop']))->assertOk();

        $this->assertSame(array_reverse($bulk), $this->ids($response));
        $this->assertStringNotContainsString('page=2', $response->getContent());
        $this->assertSame($this->letters('A'), $this->ids($this->page($this->link(['PAGE' => 'Alpha Shop']))));
    }

    public function test_S_07_3_filter_chips_and_pagination_keep_the_filter(): void
    {
        $this->bulkNight(120);
        $response = $this->page($this->link(['checker' => '__BLANK__']))->assertOk();
        $html     = $response->getContent();
        $carried  = 'night_step=' . $this->astra->id;

        // Filter select: nasa loob ng form na may hidden na night_step, kaya dala ito ng submit.
        $form = $this->form($response);
        $this->assertStringContainsString('<select name="checker"', $form);
        $this->assertStringContainsString('name="night_step" id="nightStepHidden" value="' . $this->astra->id . '"', $form);
        $this->assertCount(100, $this->ids($response));

        $this->assertSame(6, preg_match_all('/<a\s+href="([^"]*)"\s+class="inline-block px-3 py-1 rounded/', $html, $chips));
        foreach ($chips[1] as $href) {
            $this->assertStringContainsString($carried, $href);
        }

        $this->assertGreaterThan(0, preg_match_all('/href="([^"]*[?&;]page=\d+[^"]*)"/', $html, $pages));
        foreach ($pages[1] as $href) {
            $this->assertStringContainsString($carried, $href);
        }
    }

    public function test_S_07_5_the_date_picker_drops_the_filter(): void
    {
        [, $older] = $this->secondNight();

        $this->assertSame(1, preg_match('/<input type="date" name="date"[^>]*>/s', $this->page($this->link())->getContent(), $m));
        $remove = strpos($m[0], "document.getElementById('nightStepHidden')?.remove();");
        $submit = strpos($m[0], 'resetCheckerAndSubmit(this.form)');
        $this->assertNotFalse($remove, 'the date picker removes the hidden night_step');
        $this->assertNotFalse($submit);
        $this->assertLessThan($submit, $remove, 'removed before the form is submitted');

        // Ang request ng bagong petsa, wala nang night_step: ang karaniwang page ng petsang iyon.
        $response = $this->page(['date' => '2026-10-02', 'PAGE' => '', 'checker' => ''])->assertOk();
        $this->assertSame([$older + 1, $older], $this->ids($response));
        $this->assertNull($this->line($response));
    }

    // ───────────── Ang linya ─────────────

    public function test_S_08_1_the_line_names_the_night_and_the_count(): void
    {
        $response = $this->page($this->link())->assertOk();

        $this->assertSame(
            'Night run filter · night of Mon, Oct 5 (orders of Oct 4) · 2 of 2 shown · ' . self::WHOLE_DATE . ' · Show all rows',
            $this->line($response)
        );
    }

    public function test_S_08_2_show_all_rows_drops_only_the_night_filter(): void
    {
        $response = $this->page($this->link(['PAGE' => 'Alpha Shop', 'checker' => '__BLANK__', 'page' => 1]))->assertOk();

        $target = $this->clearLink($response);
        $this->assertSame(self::URL, parse_url($target, PHP_URL_PATH));
        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
        ksort($query);
        $this->assertSame(['PAGE' => 'Alpha Shop', 'checker' => '__BLANK__', 'date' => self::ORDERS], $query);

        $all = $this->get($target)->assertOk();
        $this->assertSame($this->letters('AE'), $this->ids($all));
        $this->assertNull($this->line($all));
    }

    public function test_S_08_3_no_line_without_the_parameter(): void
    {
        $html = $this->page(['date' => self::ORDERS])->assertOk()->getContent();

        $this->assertStringNotContainsString('Night run filter', $html);
        $this->assertStringNotContainsString('Show all rows', $html);
        $this->assertStringNotContainsString('nightFilterLine', $html);
        $this->assertStringNotContainsString('name="night_step"', $html);
    }

    public function test_S_08_4_n_ignores_page_filter_and_chip_and_m_is_the_steps_count(): void
    {
        // Tatlong row ng gabi: A (nasa petsa), B (nabura na ang order), G (inilipat sa ibang petsa).
        $this->o['G'] = $this->order()->id;
        $this->nightRow('G', 'done', false, 'TO FIX');
        MacroOutput::where('id', $this->o['G'])->update(['ts_date' => '2026-10-02']);
        MacroOutput::where('id', $this->o['B'])->delete();

        $this->assertStringContainsString('· 1 of 3 shown ·', (string) $this->line($this->page($this->link())));

        $narrowed = $this->page($this->link(['PAGE' => 'Beta Shop', 'checker' => '__CHECK__', 'status_filter' => 'ODZ']))->assertOk();
        $this->assertSame([], $this->ids($narrowed));
        $this->assertStringContainsString('· 1 of 3 shown ·', (string) $this->line($narrowed));
    }

    public function test_S_08_5_a_step_with_no_night_rows_says_0_of_0(): void
    {
        $this->order(['ts_date' => '2026-09-30', 'TIMESTAMP' => '21:14 30-09-2026']);
        $stepId = DB::table('night_run_steps')->insertGetId([
            'night_date' => '2026-10-01', 'kind' => 'astra', 'state' => 'finished', 'trigger' => 'schedule',
        ]);

        $response = $this->page(['date' => '2026-09-30', 'night_step' => $stepId])->assertOk();

        $this->assertSame([], $this->ids($response));
        $this->assertSame(
            'Night run filter · night of Thu, Oct 1 (orders of Sep 30) · 0 of 0 shown · ' . self::WHOLE_DATE . ' · Show all rows',
            $this->line($response)
        );
        $all = $this->get($this->clearLink($response))->assertOk();
        $this->assertCount(1, $this->ids($all));
        $this->assertNull($this->line($all));
    }

    public function test_S_08_7_the_line_holds_nothing_from_a_row(): void
    {
        $hostile = '<script>alert(1)</script>';
        MacroOutput::where('id', $this->o['A'])->update([
            'PAGE' => $hostile, 'FULL NAME' => $hostile, 'ADDRESS' => $hostile, 'ITEM_NAME' => $hostile, 'fb_name' => $hostile, 'all_user_input' => $hostile,
        ]);
        NightAstraRow::where('macro_output_id', $this->o['A'])->update(['code' => $hostile, 'reason' => $hostile]);

        $response = $this->page($this->link())->assertOk();

        $this->assertSame($this->letters('AB'), $this->ids($response));
        $this->assertSame(
            'Night run filter · night of Mon, Oct 5 (orders of Oct 4) · 2 of 2 shown · ' . self::WHOLE_DATE . ' · Show all rows',
            $this->line($response)
        );
        $this->assertSame(1, preg_match('/<div id="nightFilterLine".*?<\/div>/s', $response->getContent(), $m));
        $this->assertStringNotContainsString('script', $m[0]);
        $this->assertStringNotContainsString($hostile, $response->getContent());
    }

    // ───────────── Hindi mapagkakatiwalaang parameter ─────────────

    public function test_S_10_1_a_value_that_is_not_digits_shows_no_rows_and_the_notice(): void
    {
        foreach (['abc', '1abc', '1.5', '-1', '%2B1', '1e3', '0x1'] as $value) {
            $this->assertNotValid($this->page('date=' . self::ORDERS . '&night_step=' . $value), $value);
        }
        // Walang `date`: hindi nire-redirect ang hindi valid; ang notice ay nasa default na petsa (kahapon = petsa ng fixture).
        $this->assertNotValid($this->page('night_step=abc'), 'no date');
    }

    public function test_S_10_2_digits_that_name_no_step(): void
    {
        foreach (['0', '999999'] as $value) {
            $this->assertNotValid($this->page($this->link(['night_step' => $value])), $value);
        }
    }

    public function test_S_10_3_a_step_that_is_not_astra(): void
    {
        $import = NightRunStep::create([
            'night_date' => self::NIGHT, 'kind' => 'macro_import_1', 'state' => 'failed', 'trigger' => 'schedule', 'reason' => 'IMPORT-REASON-MARK',
        ]);
        // Kahit may night row na nakakabit sa import step, hindi ito "gabi ng Astra".
        NightAstraRow::create(['step_id' => $import->id, 'macro_output_id' => $this->o['F'], 'state' => 'done', 'proceed' => false, 'attempts' => 1]);

        $response = $this->page($this->link(['night_step' => $import->id]));

        $this->assertNotValid($response, 'import step');
        $this->assertStringNotContainsString('IMPORT-REASON-MARK', $response->getContent());
        $this->assertStringNotContainsString('macro_import_1', $response->getContent());
    }

    public function test_S_10_5_an_array_is_not_valid(): void
    {
        $this->assertSame(1, $this->astra->id); // ang array na na-cast sa 1 ay magpapakita sana ng gabing ito

        foreach (['night_step[]=1', 'night_step[a]=1', 'night_step[]=1&night_step[]=2'] as $value) {
            $this->assertNotValid($this->page('date=' . self::ORDERS . '&' . $value), $value);
        }
    }

    public function test_S_10_6_very_long_digits_never_reach_the_database(): void
    {
        foreach (['99999999999999999999', str_repeat('1', 10000), '0000000001'] as $value) {
            $statements = $this->statements(function () use ($value) {
                $this->assertNotValid($this->page($this->link(['night_step' => $value])), substr($value, 0, 20));
            });

            foreach ($statements as $sql) {
                $this->assertStringNotContainsString('night_run_steps', $sql);
                $this->assertStringNotContainsString('night_astra_rows', $sql);
            }
        }
    }

    public function test_S_10_7_sql_text_is_not_valid_and_never_in_a_statement(): void
    {
        $before = DB::table('macro_output')->orderBy('id')->get()->toJson();

        foreach (['1 OR 1=1', '1;DROP TABLE macro_output', "1' OR '1'='1"] as $value) {
            $statements = $this->statements(function () use ($value) {
                $this->assertNotValid($this->page($this->link(['night_step' => $value])), $value);
            });

            $this->assertNotEmpty($statements);
            foreach ($statements as $sql) {
                $this->assertStringNotContainsString($value, $sql);
                $this->assertStringNotContainsString('DROP', $sql);
            }
        }

        $this->assertSame($before, DB::table('macro_output')->orderBy('id')->get()->toJson());
    }

    public function test_S_10_8_any_mix_only_narrows(): void
    {
        MacroOutput::where('id', $this->o['A'])->update(['APP SCRIPT CHECKER' => 'TO FIX: city']);
        $night = $this->letters('AB');
        $mixes = [
            ['date' => self::ORDERS],
            ['date' => self::ORDERS, 'PAGE' => 'Alpha Shop'],
            ['date' => self::ORDERS, 'PAGE' => 'Plain Shop'],
            ['date' => self::ORDERS, 'checker' => '__BLANK__'],
            ['date' => self::ORDERS, 'checker' => '__TO_FIX__'],
            ['date' => self::ORDERS, 'status_filter' => 'BLANK'],
            ['date' => self::ORDERS, 'status_filter' => 'PROCEED'],
            ['date' => self::ORDERS, 'PAGE' => 'Alpha Shop', 'status_filter' => 'INCOMPLETE', 'checker' => '__TO_FIX__'],
            ['date' => '2026-10-02'],
        ];
        $shown = 0;

        foreach ($mixes as $mix) {
            $without = $this->ids($this->page($mix));
            foreach ([$this->astra->id, 'abc', '999999'] as $step) {
                $with = $this->ids($this->page($mix + ['night_step' => $step])->assertOk());

                $this->assertSame([], array_diff($with, $night), json_encode($mix) . " step {$step}: not a night row");
                $this->assertSame([], array_diff($with, $without), json_encode($mix) . " step {$step}: wider than without");
                $shown += count($with);
            }
        }

        // Ang mga mix na may totoong step ay may ipinapakita: A,B + A + (wala) + B + A + A,B + (wala) + A + (wala) = 8.
        $this->assertSame(8, $shown);
    }

    public function test_S_10_9_nothing_of_the_night_rows_is_printed(): void
    {
        NightAstraRow::where('step_id', $this->astra->id)->update([
            'code' => 'ZZCODEMARK', 'reason' => 'ZZREASONMARK', 'cost_usd' => 9.8765, 'log_id' => 424242, 'duration_ms' => 373737,
        ]);
        $answers = substr_count($this->page(['date' => self::ORDERS])->getContent(), 'ai-checker/answers');

        foreach ([$this->astra->id, 'abc', '999999'] as $step) {
            $html = $this->page($this->link(['night_step' => $step]))->assertOk()->getContent();

            foreach (['ZZCODEMARK', 'ZZREASONMARK', '9.8765', '424242', '373737'] as $marker) {
                $this->assertStringNotContainsString($marker, $html, "step {$step}");
            }
            $this->assertSame($answers, substr_count($html, 'ai-checker/answers'), "no log link added, step {$step}");
        }
    }

    public function test_S_10_10_leading_zeros_read_as_the_step(): void
    {
        [$stepId, $orderId] = $this->secondNight(7);
        $this->assertSame(7, $stepId);

        $response = $this->page(['date' => '2026-10-02', 'night_step' => '007'])->assertOk();

        $this->assertSame([$orderId], $this->ids($response));
    }

    public function test_S_11_1_a_non_ceo_gets_the_same_rows(): void
    {
        $encoder = $this->page($this->link(), 'Data Encoder')->assertOk();
        $ceo     = $this->page($this->link(), 'CEO')->assertOk();

        $this->assertSame($this->letters('AB'), $this->ids($encoder));
        $this->assertSame($this->ids($ceo), $this->ids($encoder));
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
