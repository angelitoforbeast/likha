<?php

namespace Tests\Feature\OwnerPrivate;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Fit-to-width ng main table sa /owner/private (markup lang; walang JS harness, kaya source-level ang check).
 * Lahat ng role may switch; ang breakdown, /item, Daily Summary at snapshot pages ay wala.
 */
class FitToWidthTest extends OwnerPrivateTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Kailangan ng layout (task badge) ng bawat page. Gaya ng ClaudeActionViewTest.
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        // Kailangan ng /item index() (page list).
        Schema::create('ads_manager_reports', function (Blueprint $t) {
            $t->id();
            $t->string('page_name')->nullable();
        });
        Schema::create('fee_settings', function (Blueprint $t) {
            $t->id();
            $t->string('setting_key');
            $t->decimal('setting_value', 14, 6)->nullable();
            $t->date('effective_date')->nullable();
            $t->string('description')->nullable();
            $t->string('host_scope')->nullable();
            $t->timestamps();
        });
    }

    protected function migrationPaths(): array
    {
        // Para sa breakdownPage (page label lookup).
        return array_merge(parent::migrationPaths(), [
            'database/migrations/2026_04_24_000009_create_daily_page_primary_item_table.php',
        ]);
    }

    private function page(string $url, string $role): string
    {
        $this->actingAs($this->user($role, strtolower(str_replace(' ', '', $role)) . '@example.test'));

        return $this->get($url)->assertOk()->getContent();
    }

    public static function roles(): array
    {
        return ['CEO' => ['CEO'], 'Marketing' => ['Marketing']];
    }

    #[DataProvider('roles')]
    public function test_owner_private_carries_the_fit_helper_and_switch_exactly_once(string $role): void
    {
        $html = $this->page('/owner/private', $role);

        foreach (['id="owFitSwitch"', 'class="card" data-ow-fit>', 'function owFitFactor(', '<!-- fit-to-width-start -->'] as $s) {
            $this->assertSame(1, substr_count($html, $s), "{$role}: expected exactly one: {$s}");
        }
    }

    public function test_pages_out_of_scope_do_not_carry_the_fit_helper(): void
    {
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        foreach (['/owner/private/breakdown?page_key=page+a&start_date=2026-10-01&end_date=2026-10-03', '/item'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            foreach (['owFit', 'data-ow-fit'] as $s) {
                $this->assertFalse(str_contains($html, $s), "{$url}: found {$s}");
            }
        }

        // Source scan: isang view lang ang nag-i-include (sakop nito ang Daily Summary at snapshot pages).
        $found = [];
        $root = resource_path('views');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $n = substr_count(file_get_contents($file->getPathname()), 'owner._fit_to_width');
            if ($n > 0) {
                $found[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = $n;
            }
        }
        $this->assertSame(['owner/private.blade.php' => 1], $found);
    }

    public function test_fit_factor_is_one_pure_function_with_its_guards(): void
    {
        $html = $this->page('/owner/private', 'CEO');

        $a = strpos($html, '// fit-factor-start');
        $b = strpos($html, '// fit-factor-end');
        $this->assertNotFalse($a, 'missing marker: // fit-factor-start');
        $this->assertNotFalse($b, 'missing marker: // fit-factor-end');
        $fn = substr($html, $a, $b - $a);

        // Hindi lalampas ng 1, hindi zero o negative, at 1 kapag walang sukat.
        foreach (['isFinite(c)', 'isFinite(n)', 'c <= 0', 'n <= 0', 'return 1', 'f >= 1', 'f > 0'] as $s) {
            $this->assertTrue(str_contains($fn, $s), "missing guard: {$s}");
        }
        // Pure: walang DOM o storage sa loob.
        foreach (['document', 'window', 'localStorage'] as $s) {
            $this->assertFalse(str_contains($fn, $s), "not pure: {$s}");
        }

        // Verify pass pagkatapos i-apply ang factor: may hangganan (3 beses), iisang factor function pa rin.
        $a = strpos($html, '// fit-verify-start');
        $b = strpos($html, '// fit-verify-end');
        $this->assertNotFalse($a, 'missing marker: // fit-verify-start');
        $this->assertNotFalse($b, 'missing marker: // fit-verify-end');
        $verify = substr($html, $a, $b - $a);
        // Mula sa lapad na talagang in-apply (container / f) ang pagwawasto, hindi sa unang tantiya.
        foreach (['i < 3', 'owFitFactor(', 'break', 'tw <= cw', '(container / f) * tw / cw'] as $s) {
            $this->assertTrue(str_contains($verify, $s), "verify pass missing: {$s}");
        }
        $this->assertFalse(str_contains($verify, 'while'), 'verify pass must be bounded: found while');
    }
}
