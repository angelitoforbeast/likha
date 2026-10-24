<?php

namespace Tests\Feature\OrderEncode;

use App\Models\MacroOutput;
use Illuminate\Support\Facades\Http;

/** Spec 136 C: bawat encode ay binabasa ang mga mensahe ng order na ito lang; ang sagot ay idinudugtong sa orihinal na order. */
class ConversationTest extends LiveRowTestCase
{
    public function test_S_136_15_19_a_reply_with_only_the_barangay_is_read_with_the_original_order_and_fills_the_row(): void
    {
        $first = $this->heldOrder([4 => 'Order po 2 pcs glow serum']);

        $reply = $this->liveOrder("- Brgy: Bungad", [4 => 'Brgy. Bungad, Quezon City']);

        $this->assertSame($first->macro_output_id, $reply->macro_output_id);
        $this->assertSame("Order po 2 pcs glow serum\nBrgy. Bungad, Quezon City", $reply->input['fields']['input']);
        $m = MacroOutput::sole();
        $this->assertSame(['BUNGAD', 'QUEZON-CITY', 'METRO-MANILA', 'PROCEED'], [$m->BARANGAY, $m->CITY, $m->PROVINCE, $m->STATUS]);
        $this->assertStringContainsString("Order po 2 pcs glow serum\nBrgy. Bungad, Quezon City", $m->all_user_input);
    }

    public function test_S_136_17_18_the_next_order_reads_only_its_own_messages_from_a_cell_that_holds_them_all(): void
    {
        $this->liveOrder($this->details(), [4 => "Hi po\norder ko 1 serum\nTaga Bungad QC po"]);
        MacroOutput::query()->update(['STATUS' => 'CANNOT PROCEED']);
        $this->gpt(); // bagong fake: ang recorded ay ang ikalawang order lang

        $o = $this->liveOrder($this->details(), [4 => "Hi po\norder ko 1 serum\nTaga Bungad QC po\nUlit po ako\n2 pcs naman"]);

        $this->assertSame("Ulit po ako\n2 pcs naman", $o->input['fields']['input']);
        $this->assertStringNotContainsString('order ko 1 serum', MacroOutput::find($o->macro_output_id)->all_user_input);
        $sent = Http::recorded()->map(fn ($r) => json_encode($r[0]->data(), JSON_UNESCAPED_UNICODE))->implode("\n");
        $this->assertStringNotContainsString('order ko 1 serum', $sent);
    }

    /** @return array<string, array{0: string}> */
    public static function oddInputs(): array
    {
        return ['empty' => [''], '20,000 characters' => [str_repeat('abcde fghij ', 1667)]];
    }

    /** @dataProvider oddInputs */
    public function test_S_136_20_an_empty_or_huge_input_ends_cleanly_with_bounded_gpt_calls(string $input): void
    {
        $o = $this->liveOrder($this->details('Nowhere'), [4 => $input]); // wala sa listahan: ang verify ng GPT ay binabasa ang usapan

        $this->assertSame(['done', null], [$o->status, $o->error]);
        $this->assertLessThanOrEqual(8000, mb_strlen($o->input['fields']['input']));
        $longest = Http::recorded()->map(fn ($r) => mb_strlen(json_encode($r[0]->data(), JSON_UNESCAPED_UNICODE)))->max() ?? 0;
        $this->assertLessThan(12000, $longest);
    }
}
