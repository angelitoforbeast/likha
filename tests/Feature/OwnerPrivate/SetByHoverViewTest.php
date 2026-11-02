<?php

namespace Tests\Feature\OwnerPrivate;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec 216, S-216.1: ang hover ng Item Value at RTS cell sa /owner/private (markup lang; walang JS harness, kaya
 * source-level ang check, gaya ng FitToWidthTest). Iisang owTip ang gumagawa ng linya sa compact at normal na mode.
 */
class SetByHoverViewTest extends OwnerPrivateTestCase
{
    private const CEO_LINE = "'🔒 CEO value set by '";

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Kailangan ng layout (task badge) at ng index() — gaya ng FitToWidthTest.
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
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

    private function html(string $role, string $url = '/owner/private'): string
    {
        $this->actingAs($this->user($role, strtolower(str_replace([' ', '-'], '', $role)) . '@example.test'));

        return $this->get($url)->assertOk()->getContent();
    }

    public static function views(): array
    {
        return [
            'CEO'               => ['CEO', '/owner/private'],
            'CEO as Marketing'  => ['CEO', '/owner/private?view_as=marketing'],
            'Marketing'         => ['Marketing', '/owner/private'],
        ];
    }

    #[DataProvider('views')]
    public function test_S_216_1_4_who_and_when_show_on_hover_in_compact_and_normal_mode(string $role, string $url): void
    {
        $html = $this->html($role, $url);

        // Compact: ang hover ng cell (data-ow-tip) ay galing sa owTip.
        $this->assertStringContainsString(':data-ow-tip="owCompact ? owTip(col.id, row) : null"', $html);
        // Normal: hover sa laman ng RTS at Item Value cell, parehong owTip.
        foreach (['rts_set', 'item_val'] as $col) {
            $this->assertSame(1, substr_count($html, ":title=\"!owCompact ? owTip('{$col}', row) : null\""), "{$role} {$col}");
        }
        // Ang mga linya: sino · kailan, sa RTS at sa Item Value.
        $this->assertStringContainsString("L.push('Set by ' + row.rts_set_by + (row.rts_set_at ? ' · ' + row.rts_set_at : ''));", $html);
        $this->assertStringContainsString("L.push('Set by ' + row.item_value_set_by + (row.item_value_set_at ? ' · ' + row.item_value_set_at : ''));", $html);
    }

    public function test_S_216_1_5_the_ceo_view_adds_the_ceo_value_line(): void
    {
        $this->assertStringContainsString(
            "if (row.item_value_ceo_set_by) L.push(" . self::CEO_LINE . " + row.item_value_ceo_set_by + (row.item_value_ceo_set_at ? ' · ' + row.item_value_ceo_set_at : ''));",
            $this->html('CEO')
        );
    }

    public function test_S_216_1_6_the_page_source_has_no_ceo_value_line_outside_the_ceo_view(): void
    {
        $this->assertStringNotContainsString(self::CEO_LINE, $this->html('Marketing'));
        $this->assertStringNotContainsString(self::CEO_LINE, $this->html('CEO', '/owner/private?view_as=marketing'));
    }
}
