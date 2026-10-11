<?php

namespace Tests\Feature\Rts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Ang mga pure function ng public/js/rts-column-filter.js (ang filter kada column ng RTS table), pinapatakbo sa
 * node (tests/js/call.cjs). Ang inaasahang value ng bawat case ay nakasulat dito, hindi kinukuwenta gaya ng script.
 * Kapag walang node sa makina, bawat test ay "skipped: node not found": hindi iyon pasado, hindi lang napatakbo.
 */
class RtsColumnFilterScriptTest extends TestCase
{
    private const SCRIPT = 'public/js/rts-column-filter.js';
    private const RUNNER = 'tests/js/call.cjs';
    private const NAME   = 'RtsColumnFilter';

    // Mga column ng maliit na table ng mga test: 0 = date range, 1 = sender, 2 = RTS%.
    private const DATE = 0;
    private const SENDER = 1;
    private const PCT = 2;

    private static ?bool $node = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$node === null) {
            try {
                $probe = new Process(['node', '--version']);
                $probe->run();
                self::$node = $probe->isSuccessful();
            } catch (\Throwable $e) {
                self::$node = false;
            }
        }
        if (!self::$node) $this->markTestSkipped('skipped: node not found');
    }

    private function call(string $fn, mixed ...$args): mixed
    {
        $root = dirname(__DIR__, 3);
        $process = new Process(['node', $root . '/' . self::RUNNER, $root . '/' . self::SCRIPT, self::NAME]);
        $process->setInput(json_encode([['fn' => $fn, 'args' => $args]], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'node: ' . trim($process->getErrorOutput()));
        $out = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('error', $out[0], $fn . ': ' . ($out[0]['error'] ?? ''));

        return $out[0]['value'];
    }

    /** Isang row: [simula ng date range, sender, RTS% o null kapag N/A]. */
    private function row(string $start, string $sender, ?float $pct): array
    {
        return [
            ['text' => $start . ' to 2026-09-30', 'key' => $start],
            ['text' => $sender, 'key' => null],
            ['text' => $pct === null ? 'N/A' : number_format($pct, 2) . '%', 'key' => $pct],
        ];
    }

    /** Mga imbentong sender: walang totoong pangalan dito. */
    private function rows(): array
    {
        return [
            $this->row('2026-09-10', 'Zeta Shop', 30.0),
            $this->row('2026-09-02', 'alpha Store', 9.5),
            $this->row('2026-09-10', 'Zeta Shop', 100.0),
            $this->row('2026-08-28', 'Beta Mart', null),
            $this->row('2026-09-02', '', 9.5),
        ];
    }

    private function filter(?array $values = null, ?float $min = null, ?float $max = null): array
    {
        return ['values' => $values, 'min' => $min, 'max' => $max];
    }

    /** Ang mga index ng row na pumapasa sa mga filter (bawat key = column). */
    private function passing(array $filters): array
    {
        return $this->call('matching', $this->rows(), (object) $filters);
    }

    public function test_distinct_text_values_are_sorted_a_to_z_with_their_row_counts(): void
    {
        $this->assertSame(
            [['value' => '', 'count' => 1], ['value' => 'alpha Store', 'count' => 1], ['value' => 'Beta Mart', 'count' => 1], ['value' => 'Zeta Shop', 'count' => 2]],
            $this->call('distinctValues', $this->rows(), self::SENDER, 'text')
        );
    }

    public function test_distinct_numbers_are_sorted_by_value_not_as_text_and_n_a_comes_last(): void
    {
        $this->assertSame(
            [['value' => '9.50%', 'count' => 2], ['value' => '30.00%', 'count' => 1], ['value' => '100.00%', 'count' => 1], ['value' => 'N/A', 'count' => 1]],
            $this->call('distinctValues', $this->rows(), self::PCT, 'number')
        );
    }

    public function test_distinct_date_ranges_are_sorted_by_start_date(): void
    {
        $this->assertSame(
            ['2026-08-28 to 2026-09-30', '2026-09-02 to 2026-09-30', '2026-09-10 to 2026-09-30'],
            array_column($this->call('distinctValues', $this->rows(), self::DATE, 'date'), 'value')
        );
    }

    public function test_only_rows_with_a_checked_value_pass(): void
    {
        $this->assertSame([0, 2, 4], $this->passing([self::SENDER => $this->filter(['Zeta Shop', ''])]));
        $this->assertSame([], $this->passing([self::SENDER => $this->filter([])]));
        $this->assertSame([0, 1, 2, 3, 4], $this->passing([]));
    }

    /** @return array<string, array{0: ?float, 1: ?float, 2: int[]}> */
    public static function conditions(): array
    {
        return [
            'at least, the limit itself passes' => [30.0, null, [0, 2]],
            'at most'                           => [null, 30.0, [0, 1, 4]],
            'both'                              => [10.0, 99.99, [0]],
            'at least above at most: no row'    => [50.0, 20.0, []],
        ];
    }

    #[DataProvider('conditions')]
    public function test_the_at_least_and_at_most_condition_compares_the_number_and_n_a_never_passes(?float $min, ?float $max, array $expected): void
    {
        $this->assertSame($expected, $this->passing([self::PCT => $this->filter(null, $min, $max)]));
    }

    public function test_a_row_must_pass_every_filtered_column_and_both_the_checkboxes_and_the_condition(): void
    {
        $this->assertSame([0], $this->passing([
            self::SENDER => $this->filter(['Zeta Shop', 'alpha Store']),
            self::PCT    => $this->filter(['30.00%', '9.50%', '100.00%'], 20.0, 50.0),
        ]));
    }

    public function test_the_panel_gives_a_filter_only_when_it_narrows_something(): void
    {
        $listed = ['9.50%', '30.00%', 'N/A'];

        $this->assertNull($this->call('buildFilter', $listed, $listed, '', '  '));
        $this->assertSame($this->filter(['30.00%']), $this->call('buildFilter', $listed, ['30.00%'], '', ''));
        $this->assertSame($this->filter([]), $this->call('buildFilter', $listed, [], '', ''));
        // Ang kondisyon ay tinatype ng tao: may % o kuwit, o hindi numero (hindi kondisyon iyon).
        $this->assertEquals($this->filter(null, 20, 1500.5), $this->call('buildFilter', $listed, $listed, ' 20% ', '1,500.5'));
        $this->assertNull($this->call('buildFilter', $listed, $listed, 'abc', ''));
    }

    public function test_after_a_reload_chosen_values_that_still_exist_are_kept_and_the_others_dropped(): void
    {
        $kept = $this->call('reconcile', (object) [
            self::SENDER => $this->filter(['Zeta Shop', 'Wala Na Store']),   // isa na lang ang natira
            self::DATE   => $this->filter(['2025-01-01 to 2025-01-31']),      // wala nang natira: tanggal
            self::PCT    => $this->filter(['77.00%'], 20.0, null),            // ang kondisyon ay nananatili
        ], $this->rows());

        $this->assertEquals([
            self::SENDER => $this->filter(['Zeta Shop']),
            self::PCT    => $this->filter(null, 20, null),
        ], $kept);
    }

    /** @return array<string, array{0: mixed}> */
    public static function brokenStates(): array
    {
        return [
            'null'            => [null],
            'text'            => ['{not json'],
            'list'            => [[1, 2]],
            'column not here' => [['99' => ['values' => ['Zeta Shop'], 'min' => null, 'max' => null]]],
            'wrong shapes'    => [['1' => 'x', '2' => ['values' => 'Zeta Shop', 'min' => 'abc', 'max' => []]]],
        ];
    }

    #[DataProvider('brokenStates')]
    public function test_a_broken_saved_state_gives_no_filters_and_no_error(mixed $saved): void
    {
        $this->assertSame([], (array) $this->call('reconcile', $saved, $this->rows()));
    }
}
