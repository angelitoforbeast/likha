<?php

namespace Tests\Feature\Orders;

use App\Jobs\ImportLikhaFromGoogleSheet;
use App\Models\MacroOutput;
use App\Services\OrderIdentity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Spec 307 FU-3: ang "Facebook User" ay hindi susi ng order sa Likha at Macro import; ang phone ang susi, gaya ng walang pangalan. */
class PlaceholderOrderKeyTest extends TestCase
{
    private const TS = '20:41 18-11-2026';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('macro_output', function (Blueprint $t) {
            $t->id();
            $t->string('TIMESTAMP')->nullable();
            $t->string('PAGE')->nullable();
            $t->string('FULL NAME')->nullable();
            $t->string('fb_name')->nullable();
            $t->string('PHONE NUMBER', 100)->nullable();
            $t->text('all_user_input')->nullable();
            $t->text('shop_details')->nullable();
            $t->text('extracted_details')->nullable();
            $t->string('ITEM_NAME')->nullable();
            $t->string('COD')->nullable();
            $t->text('AI ANALYZE')->nullable();
            $t->timestamps();
        });
    }

    /** Isang row ng TO WEBSITE: A date, B page, C name, D phone, E input, F shop, G extracted. */
    private function likha(string $name, string $phone, string $input = 'hello', string $ts = self::TS): string
    {
        $job = new ImportLikhaFromGoogleSheet(1);
        $m = new \ReflectionMethod($job, 'importRow');

        return $m->invoke($job, [$ts, 'Elyse Abad', $name, $phone, $input, '', '']);
    }

    public function test_FU_3_two_facebook_user_rows_on_the_same_page_and_timestamp_with_different_phones_are_two_orders(): void
    {
        $this->assertSame('inserted', $this->likha('Facebook User', '09853000001', 'a'));
        $this->assertSame('inserted', $this->likha('Facebook User', '09913485181', 'b'));
        $this->assertSame('inserted', $this->likha('facebook  user', '09170000002', 'c'));

        $this->assertSame(3, MacroOutput::count());
        $this->assertSame(['9853000001', '9913485181', '9170000002'], MacroOutput::orderBy('id')->pluck('PHONE NUMBER')->all());
        // Ang pangalan ng row ay ang nasa sheet pa rin; ang susi lang ang nagbago.
        $this->assertSame(['Facebook User', 'Facebook User', 'facebook  user'], MacroOutput::orderBy('id')->pluck('fb_name')->all());
    }

    public function test_FU_3_the_same_facebook_user_with_the_same_phone_is_still_one_order(): void
    {
        $this->assertSame('inserted', $this->likha('Facebook User', '0991 348 5181'));
        $this->assertSame('updated', $this->likha('Facebook User', '639913485181'));

        $this->assertSame(1, MacroOutput::count());
    }

    public function test_FU_3_a_facebook_user_without_a_phone_never_joins_another_facebook_users_order_and_is_flagged(): void
    {
        $this->likha('Facebook User', '09853000001', 'a');
        $this->assertSame('inserted', $this->likha('Facebook User', '', '₱999 po'));
        $this->assertSame('inserted', $this->likha('Facebook User', '', '₱999 po'));

        $this->assertSame(3, MacroOutput::count());
        $this->assertSame([null, 'No name / no phone', 'No name / no phone'], MacroOutput::orderBy('id')->pluck('AI ANALYZE')->all());
    }

    public function test_FU_3_order_identity_find_falls_through_to_the_phone_and_the_identical_input_for_a_placeholder(): void
    {
        $this->likha('Facebook User', '09853000001', 'a');
        $this->likha('Facebook User', '09913485181', 'b');

        $hit = OrderIdentity::find(self::TS, 'Elyse Abad', 'Facebook User', '09913485181', "FB NAME: Facebook User\nb");
        $none = OrderIdentity::find(self::TS, 'Elyse Abad', 'Messenger User', '09170000009', null);
        $sameInput = OrderIdentity::find(self::TS, 'Elyse Abad', 'Facebook User', '', "FB NAME: Facebook User\nb");

        $this->assertSame('9913485181', $hit->{'PHONE NUMBER'});
        $this->assertNull($none, 'walang tugma sa phone: bagong order, hindi ang ibang Facebook User');
        $this->assertSame('9913485181', $sameInput->{'PHONE NUMBER'});
    }

    public function test_FU_3_N_a_real_name_keeps_the_old_key(): void
    {
        $this->assertSame('inserted', $this->likha('Ana Cruz', '09123456789'));
        $this->assertSame('updated', $this->likha('Ana Cruz', '09999999999'));
        $this->assertSame('inserted', $this->likha('Ben Reyes', '09123456789'));

        $this->assertSame(2, MacroOutput::count());
        $this->assertNotNull(OrderIdentity::find(self::TS, 'Elyse Abad', 'Ana Cruz', '', null));
        $this->assertNull(OrderIdentity::find(self::TS, 'Elyse Abad', 'Carla Uy', '09123456789', null));
    }
}
