<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeMessage;
use App\Services\Chatblast;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** "Kunin ang mga bago": isang row kada waybill na Delivering sa huling 7 araw, ready / no_psid / no_config. */
class ChatblastBuildTest extends ChatblastTestCase
{
    public function test_delivering_orders_become_rows_by_status_and_a_second_build_adds_nothing(): void
    {
        $this->page('Page Uno');
        $this->page('Page Dos', '2002', ['field.message', 'flow.shipping']);
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-001', 'psid-a');                 // ready
        $this->shipped(' page uno ', 'Ben Reyes', 'WB-002', null);                  // no_psid (ibang baybay ng page)
        $this->shipped('Page Dos', 'Cora Diaz', 'WB-003', 'psid-c');                // no_config
        $this->shipped('Page Tres', 'Dino Lim', 'WB-004', 'psid-d');                // no_config: walang settings
        $this->shipped('Page Uno', 'Eva Tan', 'WB-005', 'psid-e', 'In Transit');    // hindi Delivering
        $this->shipped('Page Uno', 'Fe Go', 'WB-006', 'psid-f', 'Delivering', 8);   // lampas 7 araw
        $this->shipped('Page Uno', 'Gil Uy', 'WB-007', 'psid-g');
        DB::table('from_jnts')->where('waybill_number', 'WB-007')->update(['rts_reason' => 'Refused']); // may RTS: hindi Delivering

        $first = app(Chatblast::class)->build('delivering', 1);

        $rows = BotcakeMessage::orderBy('waybill')->get()->keyBy('waybill');
        $this->assertSame(['WB-001', 'WB-002', 'WB-003', 'WB-004'], $rows->keys()->all());
        $this->assertSame(['ready', 'no_psid', 'no_config', 'no_config'], $rows->pluck('status')->values()->all());
        $this->assertSame('walang flow.shipping, field.message', $rows['WB-003']->reason);
        $this->assertSame('walang page_id, token, flow.shipping, field.message', $rows['WB-004']->reason);
        $this->assertSame('psid-a', $rows['WB-001']->psid);
        $this->assertSame(['delivering', 'Delivering', false, 1], [$rows['WB-001']->kind, $rows['WB-001']->jnt_status, $rows['WB-001']->is_test, $rows['WB-001']->created_by]);
        $this->assertSame(['added' => 4, 'ready' => 1, 'no_psid' => 1, 'no_config' => 2, 'updated' => 0], $first);

        $this->assertSame(0, app(Chatblast::class)->build('delivering')['added'], 'walang doble sa ikalawang kuha');
        $this->assertSame(4, BotcakeMessage::count());
    }

    public function test_a_second_build_turns_a_row_ready_once_its_psid_or_settings_exist(): void
    {
        $this->page('Page Uno');
        $order = $this->shipped('Page Uno', 'Ben Reyes', 'WB-002', null);
        $this->shipped('Page Dos', 'Cora Diaz', 'WB-003', 'psid-c');
        app(Chatblast::class)->build('delivering');

        $order->update(['botcake_psid' => 'psid-b']);
        $this->page('Page Dos', '2002');
        $res = app(Chatblast::class)->build('delivering');

        $this->assertSame(2, $res['updated']);
        $this->assertSame(['ready', 'ready'], BotcakeMessage::orderBy('waybill')->pluck('status')->all());
        $this->assertSame('psid-b', BotcakeMessage::where('waybill', 'WB-002')->value('psid'));
        $this->assertNull(BotcakeMessage::where('waybill', 'WB-003')->value('reason'));
    }

    public function test_the_text_fills_the_placeholders_and_the_template_can_be_changed(): void
    {
        $this->page('Page Uno');
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-001', 'psid-a', 'Delivering', 0, ['ITEM_NAME' => 'Sabon x2', 'COD' => '650']);
        $this->shipped('Page Uno', '', 'WB-002', 'psid-b', 'Delivering', 0, ['receiver' => 'Ben Receiver']);
        $chatblast = app(Chatblast::class);
        $chatblast->build('delivering');

        $this->assertSame(
            "Hello Ma'am/Sir Ana Cruz, Idedeliver po ngayon ang order niyo. Maraming salamat po sa pag-order, ingat po kayo at salamat ulit sa tiwala!",
            BotcakeMessage::where('waybill', 'WB-001')->value('text')
        );
        $this->assertStringContainsString('Sir Ben Receiver,', BotcakeMessage::where('waybill', 'WB-002')->value('text'), 'walang FB name: ang receiver');

        BotcakeMessage::where('waybill', 'WB-002')->update(['status' => 'sent']);
        $chatblast->setTemplate('delivering', '{name}: {item}, COD {cod}, {waybill}, {page}');

        $this->assertSame('Ana Cruz: Sabon x2, COD 650, WB-001, Page Uno', BotcakeMessage::where('waybill', 'WB-001')->value('text'));
        $this->assertStringStartsWith("Hello Ma'am/Sir Ben Receiver", BotcakeMessage::where('waybill', 'WB-002')->value('text'), 'ang naipadala na ay hindi binabago');
        $this->assertSame('{name}: {item}, COD {cod}, {waybill}, {page}', $chatblast->template('delivering'));
    }

    public function test_the_command_builds_the_list(): void
    {
        $this->page('Page Uno');
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-001', 'psid-a');

        $this->assertSame(0, Artisan::call('chatblast:build'));

        $this->assertSame(1, BotcakeMessage::where('status', 'ready')->count());
        $this->assertStringContainsString('1 bago', Artisan::output());
    }
}
