<?php

namespace Tests\Feature\OwnerPrivate;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Claude Action / Claude Reason sa mga page (markup lang): /owner/private, breakdown, /item.
 * CEO lang ang may columns; plain text (x-text/x-model). Edit sa /owner/private at /item lang; breakdown read-only.
 */
class ClaudeActionViewTest extends OwnerPrivateTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Kailangan ng layout (task badge) ng bawat page.
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        // Kailangan ng /item index() (page list). Gaya ng ItemLayoutTest.
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

    /** Ang bahagi ng $html mula $from hanggang $to (para sa assert sa Claude cell markup lang). */
    private function between(string $html, string $from, string $to): string
    {
        $a = strpos($html, $from);
        $b = strpos($html, $to);
        $this->assertNotFalse($a, "missing marker: {$from}");
        $this->assertNotFalse($b, "missing marker: {$to}");

        return substr($html, $a, $b - $a);
    }

    private function assertNoWriteSurface(string $block): void
    {
        foreach (['openActionModal', 'saveActionNote', 'fetch(', 'x-html', '@submit', 'method="post"'] as $s) {
            $this->assertFalse(str_contains($block, $s), "write surface in Claude cells: {$s}");
        }
    }

    /** Ang modal at ang JS block: plain text lang (x-model/x-text), walang HTML sink. */
    private function assertModalEscaped(string $html): void
    {
        $blocks = [
            $this->between($html, '<!-- claude-modal-start -->', '<!-- claude-modal-end -->'),
            $this->between($html, '// claude-modal-js-start', '// claude-modal-js-end'),
        ];
        foreach ($blocks as $block) {
            foreach (['x-html', 'innerHTML', 'insertAdjacentHTML', 'outerHTML'] as $s) {
                $this->assertFalse(str_contains($block, $s), "html sink in claude modal: {$s}");
            }
        }
        foreach (['x-model="claudeModal.action"', 'x-model="claudeModal.reason"'] as $s) {
            $this->assertTrue(str_contains($blocks[0], $s), "missing: {$s}");
        }
        $this->assertSame(0, preg_match('/\sx-html\s*=/', $html));
    }

    public function test_main_table_has_editable_claude_columns_for_ceo(): void
    {
        $html = $this->page('/owner/private', 'CEO');

        foreach ([
            'Claude Action', 'Claude Reason', 'x-text="row.claude_action"', 'x-text="row.claude_reason"',
            route('owner.private.claude-action.save'), 'saveClaudeNote', 'td.claude-col', "'claude-col'",
        ] as $s) {
            $this->assertTrue(str_contains($html, $s), "missing: {$s}");
        }
        $cells = $this->between($html, '<!-- claude-cells-start -->', '<!-- claude-cells-end -->');
        $this->assertStringContainsString("openClaudeModal(row, 'action')", $cells);
        $this->assertStringContainsString("openClaudeModal(row, 'reason')", $cells);
        $this->assertStringNotContainsString('openActionModal', $cells);
        $this->assertStringNotContainsString('saveActionNote', $cells);
        $this->assertModalEscaped($html);
    }

    public function test_breakdown_has_read_only_claude_columns_for_ceo(): void
    {
        $html = $this->page('/owner/private/breakdown?page_key=page+a&start_date=2026-10-01&end_date=2026-10-03', 'CEO');

        foreach (['Claude Action</th>', 'Claude Reason</th>', 'x-text="r.claude_action"', 'x-text="r.claude_reason"'] as $s) {
            $this->assertTrue(str_contains($html, $s), "missing: {$s}");
        }
        $this->assertNoWriteSurface($this->between($html, '<!-- claude-cells-start -->', '<!-- claude-cells-end -->'));
        $this->assertSame(0, preg_match('/\sx-html\s*=/', $html));
        // D3: wala pang edit dito (sa /owner/private lang).
        foreach (['claude-action', 'openClaudeModal'] as $s) {
            $this->assertFalse(str_contains($html, $s), "edit surface in breakdown: {$s}");
        }
    }

    public function test_item_page_new_layout_has_editable_claude_page_fields_for_ceo(): void
    {
        $html = $this->page('/item', 'CEO');

        foreach ([
            "case 'claude_action':", "case 'claude_reason':", "id:'claude_action'", "id:'claude_reason'",
            route('owner.private.claude-action.save'), 'saveClaudeNote',
            '@click="openClaudeModal(row, \'action\')" title="Edit Claude Action"',
            '@click="openClaudeModal(row, \'reason\')" title="Edit Claude Reason"',
        ] as $s) {
            $this->assertTrue(str_contains($html, $s), "missing: {$s}");
        }
        $this->assertNoWriteSurface($this->between($html, "case 'claude_action':", 'default: return miss;'));
        $this->assertModalEscaped($html);
    }

    public static function pages(): array
    {
        return [
            'main table' => ['/owner/private'],
            'breakdown'  => ['/owner/private/breakdown?page_key=page+a&start_date=2026-10-01&end_date=2026-10-03'],
            'item page'  => ['/item'],
        ];
    }

    #[DataProvider('pages')]
    public function test_non_ceo_roles_get_no_claude_columns_in_the_page_source(string $url): void
    {
        foreach (['Marketing', 'Marketing - OIC'] as $role) {
            $html = $this->page($url, $role);

            // Ang column id sa server config (hidden/order JSON) ay okay; ang label at ang cell/field code ay hindi.
            foreach (['Claude Action', 'Claude Reason', 'row.claude_', 'r.claude_', '.claude_source', "case 'claude_", "id:'claude_", 'claude-cells',
                'claude-action', 'openClaudeModal', 'saveClaudeNote', 'claudeModal', 'claude-modal', 'claude-col'] as $s) {
                $this->assertFalse(str_contains($html, $s), "{$role} {$url}: found {$s}");
            }
            auth()->forgetGuards();
        }
    }
}
