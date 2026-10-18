<?php

namespace Tests\Feature\Chatblast;

use App\Models\AppSetting;
use App\Models\BotcakeMessage;
use App\Models\ChatblastCampaign;
use App\Services\Chatblast;
use Illuminate\Support\Facades\DB;

/**
 * Ang dalawang dating uri (in_transit, delivering) ay nagiging dalawang campaign: ang naka-save na text (o ang default),
 * manual, naka-on, at ang mga dating row ay naka-link sa kanila. Ang kind ng row ay hindi nagbabago.
 */
class ChatblastCampaignMigrationTest extends ChatblastTestCase
{
    protected bool $withCampaigns = false;

    public function test_the_schedule_columns_keep_the_live_campaigns_exactly_and_default_to_every_day(): void
    {
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_18_400000_create_chatblast_campaigns_table.php']]);
        $days = fn (string $w, int $n) => array_map(fn ($d) => [$w . ' araw ' . $d . ' A {name}', $w . ' araw ' . $d . ' B {waybill}'], range(1, $n));
        DB::table('chatblast_campaigns')->where('kind', 'in_transit')->update(['days' => json_encode($days('Biyahe', 10)), 'pages' => json_encode(['Page Uno'])]);
        DB::table('chatblast_campaigns')->where('kind', 'delivering')->update(['days' => json_encode($days('Deliver', 5)), 'send_times' => json_encode(['09:15', '15:00'])]);
        $before = DB::table('chatblast_campaigns')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        \Illuminate\Support\Facades\Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_19_100000_add_schedule_to_chatblast_campaigns.php']]);

        $after = DB::table('chatblast_campaigns')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        foreach ($before as $i => $row) {
            $this->assertSame($row, array_intersect_key($after[$i], $row), 'walang nabago sa dating column');
            $this->assertSame([null, null, null], [$after[$i]['weekdays'], $after[$i]['step_times'], $after[$i]['daily_cap']]);
        }
        $it = ChatblastCampaign::where('kind', 'in_transit')->sole();
        $this->assertCount(10, $it->days);
        $this->assertSame('Biyahe araw 10 B {waybill}', $it->days[9][1]);
    }

    public function test_the_two_kinds_become_two_manual_campaigns_with_their_texts_and_their_rows(): void
    {
        $saved = [['Araw isa {name}', 'Araw isa B {name}'], ['Araw dalawa {waybill}']];
        AppSetting::set('chatblast.sequence.in_transit', json_encode($saved));
        AppSetting::set('chatblast.sequence.delivering', json_encode([['  ']])); // mali: ang default ang gagamitin
        $old = fn (string $kind, string $wb) => BotcakeMessage::create(['page_name' => 'Page Uno', 'waybill' => $wb, 'kind' => $kind,
            'day' => 1, 'status' => 'sent', 'text' => 'x', 'dedupe_key' => $wb . '|' . $kind . '|1']);
        $transit = $old('in_transit', 'WB-001');
        $delivering = $old('delivering', 'WB-002');

        $this->migrateCampaigns();

        $it = ChatblastCampaign::where('kind', 'in_transit')->sole();
        $dl = ChatblastCampaign::where('kind', 'delivering')->sole();
        $this->assertSame(['In transit', ['In Transit'], 14, $saved, false, 'manual', true, null],
            [$it->name, $it->statuses, $it->window_days, $it->days, $it->requires_on_delivery, $it->mode, $it->active, $it->pages]);
        $this->assertSame(['Delivering', ['Delivering'], 7, 1, 'manual', true, true],
            [$dl->name, $dl->statuses, $dl->window_days, count($dl->days), $dl->mode, $dl->active, $dl->requires_on_delivery]);
        $this->assertStringStartsWith("Hello Ma'am/Sir {name}, Idedeliver po ngayon", $dl->days[0][0]);
        $this->assertNull(app(Chatblast::class)->invalid($dl->days), 'ang default ng delivering ay tamang text');

        $this->assertSame([$it->id, 'in_transit'], [$transit->fresh()->campaign_id, $transit->fresh()->kind]);
        $this->assertSame([$dl->id, 'delivering'], [$delivering->fresh()->campaign_id, $delivering->fresh()->kind]);
        $this->assertSame($saved, app(Chatblast::class)->sequence('in_transit'), 'ang sequence ay mula na sa campaign');
    }
}
