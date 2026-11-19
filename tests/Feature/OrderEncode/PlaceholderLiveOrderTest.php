<?php

namespace Tests\Feature\OrderEncode;

use App\Models\MacroOutput;

/** Spec 307 FU-3: ang Encode order (live) ay hindi kumakabit sa row ng import ng ibang "Facebook User" ayon sa pangalan. */
class PlaceholderLiveOrderTest extends LiveRowTestCase
{
    public function test_FU_3_an_order_with_a_psid_does_not_take_another_facebook_users_imported_row(): void
    {
        $stranger = $this->existing(['botcake_psid' => null, 'fb_name' => 'Facebook User', 'FULL NAME' => 'Mercedita Santos', 'PHONE NUMBER' => '09170000001']);
        $before = $this->columns($stranger);

        $o = $this->liveOrder($this->details(), [2 => 'Facebook User']);

        $this->assertSame(2, MacroOutput::count());
        $this->assertNotSame($stranger->id, $o->macro_output_id);
        $this->assertSame($before, $this->columns($stranger), 'walang nabago sa row ng ibang customer');
        $this->assertSame((string) self::PSID, MacroOutput::find($o->macro_output_id)->botcake_psid);
    }

    public function test_FU_3_an_order_without_psid_and_a_placeholder_name_makes_its_own_row(): void
    {
        $stranger = $this->existing(['botcake_psid' => null, 'fb_name' => 'Facebook User', 'FULL NAME' => 'Mercedita Santos', 'PHONE NUMBER' => '09170000001']);
        $before = $this->columns($stranger);

        $o = $this->liveOrder($this->details(), [2 => 'Facebook User'], 0);

        $this->assertSame(2, MacroOutput::count());
        $this->assertNotSame($stranger->id, $o->macro_output_id);
        $this->assertSame($before, $this->columns($stranger));
    }

    public function test_FU_3_N_a_real_name_without_psid_is_still_linked_by_page_and_fb_name(): void
    {
        $import = $this->existing(['botcake_psid' => null]);

        $o = $this->liveOrder($this->details());

        $this->assertSame(1, MacroOutput::count());
        $this->assertSame($import->id, $o->macro_output_id);
    }

    public function test_FU_3_N_the_same_facebook_user_by_psid_is_still_one_row(): void
    {
        $this->liveOrder($this->details(), [2 => 'Facebook User']);
        $second = $this->liveOrder($this->details(), [2 => 'Facebook User']);

        $this->assertSame(1, MacroOutput::count());
        $this->assertSame('done', $second->status);
    }
}
