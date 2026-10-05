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

    public function test_ceo_gets_compact_css_and_one_actions_button(): void
    {
        $html = $this->page('/owner/private', 'CEO');

        foreach (['id="owFitSwitch"', 'id="owActionsBtn"'] as $s) {
            $this->assertSame(1, substr_count($html, $s), "expected exactly one: {$s}");
        }
        foreach (['.ow-compact > table', '-webkit-line-clamp:3', '.ow-actions > table', 'owActionsView', 'ow-fit-mode'] as $s) {
            $this->assertTrue(str_contains($html, $s), "missing: {$s}");
        }
    }

    public function test_actions_button_is_ceo_view_only(): void
    {
        $this->assertFalse(str_contains($this->page('/owner/private', 'Marketing'), 'id="owActionsBtn"'));
        $this->assertFalse(str_contains($this->page('/owner/private?view_as=marketing', 'CEO'), 'id="owActionsBtn"'));
    }

    public function test_data_col_is_only_the_alpine_binding_so_old_layout_is_unchanged(): void
    {
        $html = $this->page('/owner/private', 'CEO');

        $this->assertSame(4, substr_count($html, ':data-col="'));
        $this->assertSame(4, substr_count($html, ':data-col="owCompact ? col.id : null"'));
        // Walang static data-col sa markup (ang nasa CSS ay [data-col=...], hindi ' data-col=').
        $this->assertSame(0, preg_match('/<(th|td)\b[^>]*\sdata-col="/', $html));
    }

    public function test_every_new_css_rule_is_scoped_so_old_layout_cannot_match(): void
    {
        $html = $this->page('/owner/private', 'CEO');
        $a = strpos($html, '<!-- fit-to-width-start -->');
        $style = substr($html, strpos($html, '<style>', $a), strpos($html, '</style>', $a) - strpos($html, '<style>', $a));
        $style = preg_replace('#/\*.*?\*/#s', '', $style);

        $count = 0;
        foreach (explode('}', $style) as $rule) {
            $sel = trim(substr($rule, 0, (int) strpos($rule . '{', '{')), " \t\r\n");
            $sel = trim(str_replace('<style>', '', $sel));
            if ($sel === '') {
                continue;
            }
            // Hiwa-hiwalay lang sa kuwit na sinusundan ng (espasyo/newline at) ".": hindi ang nasa loob ng :is() / :not().
            foreach (preg_split('/,\s*(?=\.)/', $sel) as $one) {
                $count++;
                $this->assertMatchesRegularExpression('/^\.(ow-compact|ow-actions|ow-fit-on)\b/', trim($one), "unscoped selector: {$one}");
            }
        }
        $this->assertGreaterThan(10, $count);
    }

    public function test_compact_css_holds_explicit_widths(): void
    {
        $html = $this->page('/owner/private', 'CEO');
        $a = strpos($html, '<!-- fit-to-width-start -->');
        $style = substr($html, strpos($html, '<style>', $a), strpos($html, '</style>', $a) - strpos($html, '<style>', $a));

        foreach ([
            'th[data-col] { width:1px; }',
            'th[data-col="promo"] { width:100px !important;',
            'th:nth-child(1) { width:115px;',
            'th:nth-child(2) { width:115px;',
            'td:nth-child(2) > div:first-child { display:-webkit-box; -webkit-box-orient:vertical; -webkit-line-clamp:2;',
        ] as $s) {
            $this->assertTrue(str_contains($style, $s), "missing css: {$s}");
        }
    }

    public function test_compact_cells_show_notes_on_hover_only(): void
    {
        $html = $this->page('/owner/private', 'CEO');
        $a = strpos($html, '<!-- fit-to-width-start -->');
        $style = substr($html, strpos($html, '<style>', $a), strpos($html, '</style>', $a) - strpos($html, '<style>', $a));

        foreach ([
            'td[data-col="rts_set"] > span > div > template + div > div { display:none !important; }',
            'td[data-col="item_val"] > span > div > template + div > div { display:none !important; }',
            '> div[title] > template + div { display:none !important; }',
            '.page-cell-body div { white-space:normal; overflow-wrap:anywhere; }',
            '.ow-compact > table > tbody:not(.page-section-expanded) > tr > td:is(',
            '.ow-compact > table > tbody.page-section-expanded > tr > td:is(',
        ] as $s) {
            $this->assertTrue(str_contains($style, $s), "missing css: {$s}");
        }
        foreach (['max-width:64px', 'display:inline !important', '.page-cell-body div { white-space:nowrap'] as $s) {
            $this->assertFalse(str_contains($style, $s), "css must be gone: {$s}");
        }

        foreach ([
            'owTip(colId, row){',
            "'Set by ' + row.rts_set_by",
        ] as $s) {
            $this->assertTrue(str_contains($html, $s), "missing: {$s}");
        }
        $this->assertSame(1, substr_count($html, ':data-ow-tip="owCompact ? owTip(col.id, row) : null"'));
        // Natira lang ang item name, secondary item at ang owTip na title.
        $this->assertSame(3, substr_count($html, ':title="owCompact ? '));
        $this->assertSame(0, substr_count($html, ':title="(owCompact ? '));

        $a = strpos($html, '// ow-tip-start');
        $b = strpos($html, '// ow-tip-end');
        $this->assertNotFalse($a, 'missing marker: // ow-tip-start');
        $this->assertNotFalse($b, 'missing marker: // ow-tip-end');
        $tip = substr($html, $a, $b - $a);
        foreach (['tip.textContent = text', 'document.body.appendChild(tip)', 'e.clientX'] as $s) {
            $this->assertTrue(str_contains($tip, $s), "tip script missing: {$s}");
        }
        foreach (['getBoundingClientRect', 'preventDefault', 'stopPropagation'] as $s) {
            $this->assertFalse(str_contains($tip, $s), "tip script must not use: {$s}");
        }
    }

    public function test_actions_whitelist_ids_are_real_columns(): void
    {
        $html = $this->page('/owner/private', 'CEO');
        $a = strpos($html, '.ow-actions > table');
        preg_match_all('/:not\(\[data-col="([a-z0-9_]+)"\]\)/', substr($html, $a, strpos($html, '{', $a) - $a), $m);
        $expected = ['cpp', 'proj_pct', 'proj_pct_1d', 'proj_pct_3d', 'proj_pct_7d', 'hold', 'action', 'claude_action', 'claude_reason', 'ceo_action', 'ceo_reason'];
        $this->assertSame($expected, array_slice($m[1], 0, 11));

        foreach ($expected as $id) {
            $this->assertTrue(str_contains($html, "{ id:'{$id}',"), "no column with id {$id}");
        }
    }

    public function test_column_drag_is_refused_while_actions_view_is_on(): void
    {
        $html = $this->page('/owner/private', 'CEO');

        $this->assertTrue(str_contains($html, "if (document.querySelector('[data-ow-fit].ow-actions')) { e.preventDefault(); return; }"));
    }

    public function test_fit_script_never_writes_html(): void
    {
        $html = $this->page('/owner/private', 'CEO');
        $a = strpos($html, '<!-- fit-to-width-start -->');
        $b = strpos($html, '<!-- fit-to-width-end -->');
        $block = substr($html, $a, $b - $a);

        foreach (['x-html', 'innerHTML', 'insertAdjacentHTML'] as $s) {
            $this->assertFalse(str_contains($block, $s), "found {$s}");
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
