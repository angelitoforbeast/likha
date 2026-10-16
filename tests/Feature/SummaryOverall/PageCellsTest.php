<?php

namespace Tests\Feature\SummaryOverall;

use Symfony\Component\Process\Process;

/**
 * Ang markup at mga formatter ng table ng /summary/overall: isang table lang ang naka-render,
 * at isang formatter kada column (parehong itsura ng dating hanay ng x-if).
 */
class PageCellsTest extends SummaryOverallTestCase
{
    private const RUNNER = 'tests/js/overall.cjs';

    private function page(): string
    {
        $this->actingAs($this->user());

        return $this->get('/summary/overall')->assertOk()->getContent();
    }

    private function js(string $html, array $calls): array
    {
        $probe = new Process(['node', '--version']);
        $probe->run();
        if (!$probe->isSuccessful()) $this->markTestSkipped('skipped: node not found');

        $file = tempnam(sys_get_temp_dir(), 'overall');
        file_put_contents($file, $html);
        $payload = array_map(fn (array $c) => ['fn' => $c[0], 'args' => array_slice($c, 1)], $calls);
        $process = new Process(['node', dirname(__DIR__, 3) . '/' . self::RUNNER, $file]);
        $process->setInput(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $process->run();
        @unlink($file);
        $this->assertTrue($process->isSuccessful(), 'node: ' . trim($process->getErrorOutput()));
        $out = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        foreach ($out as $i => $r) $this->assertArrayNotHasKey('error', $r, $calls[$i][0] . ': ' . ($r['error'] ?? ''));

        return array_column($out, 'value');
    }

    public function test_only_the_visible_table_is_rendered_and_cells_use_one_formatter(): void
    {
        $html = $this->page();

        $this->assertDoesNotMatchRegularExpression('/<table[^>]*x-show/', $html);
        $this->assertStringContainsString('<template x-if="!isCEO || (isCEO && !showAllColumns)">', $html);
        $this->assertStringContainsString('<template x-if="isCEO && showAllColumns">', $html);
        $this->assertSame(4, substr_count($html, 'x-if="col.key'));
    }

    public function test_cell_formatters_give_the_same_text_class_and_style_as_before(): void
    {
        $cases = [
            [['cellText', 'adspent', ['adspent' => 1234.5]], '₱1,234.50'],
            [['cellText', 'net_profit', ['net_profit' => -50]], '₱-50.00'],
            [['cellText', 'orders', ['orders' => 1234]], '1,234'],
            [['cellText', 'hold', ['hold' => null]], '0'],
            [['cellText', 'proceed_cpp', ['proceed_cpp' => null]], '—'],
            [['cellText', 'net_profit_pct', ['net_profit_pct' => 16.456]], '16.46%'],
            [['cellText', 'projected_net_profit_pct', ['projected_net_profit_pct' => 5]], '5.00%'],
            [['cellText', 'page', ['page' => null]], '—'],
            [['cellText', 'avg_delay_days', ['avg_delay_days' => 1.8333]], '1.83'],
            [['cellText', 'date', ['date' => '2026-10-01']], 'Oct 1, 2026'],
            [['cellClass', 'rts_pct', ['rts_pct' => 40]], 'px-2 py-0.5 rounded bg-red-100 text-red-800'],
            [['cellClass', 'tcpr', ['tcpr' => 1]], 'px-2 py-0.5 rounded '],
            [['cellClass', 'net_profit_pct', ['net_profit_pct' => 8]], 'px-2 py-0.5 rounded font-bold bg-yellow-100 text-yellow-800'],
            [['cellClass', 'orders', ['orders' => 1]], ''],
            [['cellStyle', 'net_profit_pct', ['net_profit_pct' => 20]], ['backgroundColor' => '#00ff00', 'color' => '#052e16']],
            [['cellStyle', 'orders', ['orders' => 1]], ''],
            [['cellText', 'settlement', ['realized_net_profit' => 863.4, 'expected_net_profit' => 2431.98, 'pending_net_profit' => 1568.58, 'settled_pct' => 55.556, 'is_final' => false]],
                "₱863.40 → ₱2,431.98\npending ₱1,568.58\n55.56% settled"],
            [['cellText', 'settlement', ['realized_net_profit' => -150, 'expected_net_profit' => -150, 'pending_net_profit' => 0, 'settled_pct' => null, 'is_final' => true]],
                "₱-150.00 → ₱-150.00\npending ₱0.00\n— settled · ✓ final"],
            [['cellClass', 'settlement', ['is_final' => true]], 'whitespace-pre-line leading-tight text-[11px] text-green-700'],
        ];
        $values = $this->js($this->page(), array_column($cases, 0));

        foreach ($cases as $i => [$call, $expected]) {
            $this->assertSame($expected, $values[$i], $call[0] . ' ' . $call[1]);
        }
    }
}
