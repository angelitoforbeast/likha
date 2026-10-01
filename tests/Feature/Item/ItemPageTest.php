<?php

namespace Tests\Feature\Item;

/**
 * /item page markup — ang sourcing chips + worklist table ay nasa CEO view LANG.
 * Direktang nire-render ang view (ang index() ay nangangailangan ng maraming legacy table).
 */
class ItemPageTest extends ItemTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Kailangan ng layout (task badge) — gaya ng BoardroomTestCase.
        \Illuminate\Support\Facades\Schema::create('tasks', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        $this->actingAs($this->user());
    }

    private function render(bool $effectiveIsCEO): string
    {
        return view('item.index', [
            'pages' => [], 'isCEO' => true, 'isMarketingOIC' => false,
            'viewAs' => $effectiveIsCEO ? 'ceo' : 'marketing', 'effectiveIsCEO' => $effectiveIsCEO,
            'ownerPrivateColsConfig' => null, 'campaignsColsConfig' => null, 'breakevenTargetPct' => 5,
            'colFormatRules' => [], 'campaignsColFormatRules' => [],
            'feeShipping' => null, 'feeCodRate' => null, 'feeVatRate' => null,
        ])->render();
    }

    public function test_sourcing_chips_and_worklist_show_in_the_ceo_view_only(): void
    {
        $ceo = $this->render(true);
        $this->assertStringContainsString('setWorklist(c.key)', $ceo);
        $this->assertStringContainsString("x-show=\"worklist.list !== 'lahat'\"", $ceo);
        $this->assertStringContainsString(route('item.worklist'), $ceo);

        $mkt = $this->render(false);
        $this->assertStringNotContainsString('setWorklist(c.key)', $mkt);
        $this->assertStringNotContainsString("x-show=\"worklist.list !== 'lahat'\"", $mkt);
    }
}
