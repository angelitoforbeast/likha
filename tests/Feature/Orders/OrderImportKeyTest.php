<?php

namespace Tests\Feature\Orders;

use App\Jobs\ImportLikhaFromGoogleSheet;
use App\Models\MacroOutput;
use App\Services\OrderIdentity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Ang key ng order sa Likha at Macro import kapag walang Facebook name (blangkong C). */
class OrderImportKeyTest extends TestCase
{
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
    private function likha(string $name, string $phone, string $input = 'hello', string $ts = '10:15 20-10-2026'): string
    {
        $job = new ImportLikhaFromGoogleSheet(1);
        $m = new \ReflectionMethod($job, 'importRow');

        return $m->invoke($job, [$ts, 'Page Uno', $name, $phone, $input, '', '']);
    }

    public function test_S_121_1_1_named_orders_keep_the_old_key(): void
    {
        $this->assertSame('inserted', $this->likha('Ana Cruz', '09123456789'));
        $this->assertSame('updated', $this->likha('Ana Cruz', '09999999999'));
        $this->assertSame('inserted', $this->likha('Ben Reyes', '09123456789'));
        $this->assertSame(2, MacroOutput::count());
    }

    public function test_S_121_1_2_nameless_orders_with_different_phones_are_two_rows(): void
    {
        $this->assertSame('inserted', $this->likha('', '09123456789'));
        $this->assertSame('inserted', $this->likha('', '09987654321'));
        $this->assertSame(2, MacroOutput::count());
    }

    public function test_S_121_1_3_nameless_orders_with_the_same_phone_are_one_row(): void
    {
        $this->assertSame('inserted', $this->likha('', '0912 345 6789'));
        $this->assertSame('updated', $this->likha('', '09123456789'));
        $this->assertSame(1, MacroOutput::count());
    }

    public function test_S_121_1_4_the_phone_is_taken_from_the_customer_text_when_d_is_blank(): void
    {
        $this->likha('', '', "phone ko +63 912 345 6789");
        $this->likha('', '', 'phone ko 09123456789');

        $this->assertSame(1, MacroOutput::count());
        $this->assertSame('09123456789', MacroOutput::first()->{'PHONE NUMBER'});
    }

    public function test_S_121_1_5_no_name_and_no_phone_always_inserts_and_is_flagged(): void
    {
        $this->assertSame('inserted', $this->likha('', '', '₱999 po'));
        $this->assertSame('inserted', $this->likha('', '', '₱999 po'));

        $this->assertSame(2, MacroOutput::count());
        $this->assertSame(['No name / no phone', 'No name / no phone'], MacroOutput::pluck('AI ANALYZE')->all());
    }

    public function test_S_121_2_1_macro_nameless_matches_by_phone(): void
    {
        $this->likha('', '09123456789', 'a');
        $this->likha('', '09987654321', 'b');

        $hit = OrderIdentity::find('10:15 20-10-2026', 'Page Uno', '', '09987654321', "FB NAME: \nibang text");

        $this->assertSame('09987654321', $hit->{'PHONE NUMBER'});
    }

    public function test_S_121_2_2_macro_without_phone_matches_only_identical_input(): void
    {
        $this->likha('', '', '₱999 po');
        $this->likha('', '', '₱499 po');
        $stored = fn (string $t) => "FB NAME: \n" . $t;

        $same = OrderIdentity::find('10:15 20-10-2026', 'Page Uno', '', '', $stored('₱499 po'));
        $other = OrderIdentity::find('10:15 20-10-2026', 'Page Uno', '', '', $stored('iba pa'));

        $this->assertSame($stored('₱499 po'), $same->all_user_input);
        $this->assertNull($other, 'walang existing na ginagalaw');
    }

    public function test_S_121_2_3_macro_named_order_keeps_the_old_key_and_a_blank_name_is_not_the_next_line(): void
    {
        $this->likha('Ana Cruz', '09123456789');

        $this->assertNotNull(OrderIdentity::find('10:15 20-10-2026', 'Page Uno', 'Ana Cruz', '', null));
        $this->assertNull(OrderIdentity::find('10:15 20-10-2026', 'Page Uno', 'Ben Reyes', '09123456789', null));
        $this->assertSame('Ana Cruz', OrderIdentity::fbNameFromInput("FB NAME: Ana Cruz\nPAGE: x"));
        $this->assertSame('', OrderIdentity::fbNameFromInput("FB NAME: \nPAGE: x"));
    }
}
