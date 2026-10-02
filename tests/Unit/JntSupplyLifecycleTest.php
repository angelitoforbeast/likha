<?php

namespace Tests\Unit;

use App\Http\Controllers\JntSupplyController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pinned ang lifecycle classification ng /jnt/supply (kasalukuyang behaviour, hindi bagong rules).
 * Defaults: new <= 30 days, long-running >= 90 days, scale x1.5, decline x0.5.
 */
class JntSupplyLifecycleTest extends TestCase
{
    /** @return array<string, array{float, float, int, string}> recent, prev, daysRunning, expected */
    public static function classifyCases(): array
    {
        return [
            'new at exactly 30 days'            => [5, 5, 30, 'new'],
            'day 31 is no longer new'           => [5, 5, 31, 'active'],
            'new beats scaling'                 => [10, 1, 10, 'new'],
            'new needs recent sales: phasing'   => [0, 5, 10, 'phasing_out'],
            'new needs recent sales: dormant'   => [0, 0, 10, 'dormant'],
            'brand new, no sales at all'        => [0, 0, 0, 'dormant'],
            'recent 0, prev > 0 is phasing out' => [0, 3, 100, 'phasing_out'],
            'recent 0, prev 0 is dormant'       => [0, 0, 100, 'dormant'],
            'prev 0, recent > 0 is scaling'     => [4, 0, 100, 'scaling'],
            'exactly prev x1.5 is scaling'      => [1.5, 1, 50, 'scaling'],
            'just under prev x1.5 is active'    => [1.4, 1, 50, 'active'],
            'exactly prev x0.5 is declining'    => [0.5, 1, 50, 'declining'],
            'just over prev x0.5 is active'     => [0.6, 1, 50, 'active'],
            'declining beats consistent'        => [0.5, 1, 200, 'declining'],
            'exactly 90 days is consistent'     => [1, 1, 90, 'consistent'],
            '89 days is active'                 => [1, 1, 89, 'active'],
            '9999 days (no first date)'         => [1, 1, 9999, 'consistent'],
        ];
    }

    #[DataProvider('classifyCases')]
    public function test_jnt_supply_classifies_lifecycle(float $recent, float $prev, int $days, string $expected): void
    {
        $this->assertSame($expected, $this->controllerClassify($recent, $prev, $days));
    }

    /** @return array<string, array{string, string, string}> key, label, class */
    public static function badgeCases(): array
    {
        return [
            'new'         => ['new',         '🆕 New',        'bg-blue-100 text-blue-800'],
            'scaling'     => ['scaling',     '📈 Scaling',    'bg-green-100 text-green-800'],
            'consistent'  => ['consistent',  '✅ Consistent', 'bg-teal-100 text-teal-800'],
            'active'      => ['active',      '🔄 Active',     'bg-slate-100 text-slate-700'],
            'declining'   => ['declining',   '📉 Declining',  'bg-orange-100 text-orange-800'],
            'phasing_out' => ['phasing_out', '🚫 Phasing Out', 'bg-red-100 text-red-800'],
            'dormant'     => ['dormant',     '💤 Dormant',    'bg-gray-100 text-gray-500'],
            'unknown'     => ['whatever',    '— Unknown',     'bg-gray-100 text-gray-400'],
        ];
    }

    #[DataProvider('badgeCases')]
    public function test_jnt_supply_lifecycle_badge(string $key, string $label, string $classes): void
    {
        $this->assertSame([$label, $classes], $this->controllerBadge($key));
    }

    private function controllerClassify(float $recent, float $prev, int $days): string
    {
        $m = new ReflectionMethod(JntSupplyController::class, 'classifyLifecycle');

        return $m->invoke(new JntSupplyController(), $recent, $prev, $days, true, 30, 90, 1.5, 0.5);
    }

    private function controllerBadge(string $key): array
    {
        $m = new ReflectionMethod(JntSupplyController::class, 'lifecycleBadge');

        return $m->invoke(new JntSupplyController(), $key);
    }
}
