<?php

namespace Tests\Feature\AdAccount;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\OwnerPrivate\OwnerPrivateTestCase;

/**
 * /ads_manager/ad_account: sino ang nagdagdag, nagpalit at nagbura ng ad account, at ang change log.
 * Ang lumang row ay naiiwan (active = false); ang mga reader ay active rows lang ang nakikita.
 */
class AdAccountHistoryTest extends OwnerPrivateTestCase
{
    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), [
            'database/migrations/2025_10_19_154416_create_ad_accounts_table.php',
            'database/migrations/2025_10_19_131712_create_payment_activity_ads_manager_table.php',
            'database/migrations/2026_01_29_195919_add_remarks_to_payment_activity_ads_manager_table.php',
            'database/migrations/2026_10_31_100000_add_history_to_ad_accounts_table.php',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('tasks', function (Blueprint $t) { // binibilang ng view composer
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Carbon::setTestNow('2026-10-21 09:00:00');
    }

    private function staff(string $name = 'Ana Marketing', string $email = 'ana@example.test'): User
    {
        return User::create(['name' => $name, 'email' => $email, 'password' => 'secret-password'])->fresh();
    }

    private function rows(string $adAccountId)
    {
        return DB::table('ad_accounts')->where('ad_account_id', $adAccountId)->orderBy('id')->get();
    }

    public function test_S_103_1_add_sets_created_by_and_action_added(): void
    {
        $ana = $this->staff();

        $this->actingAs($ana)->post('/ads_manager/ad_account', ['ad_account_id' => '1001', 'name' => 'Likha Main'])
            ->assertRedirect('/ads_manager/ad_account');

        $rows = $this->rows('1001');
        $this->assertCount(1, $rows);
        $this->assertSame($ana->id, (int) $rows[0]->created_by);
        $this->assertSame('added', $rows[0]->action);
        $this->assertTrue((bool) $rows[0]->active);
    }

    /** Rename sa edit form, ID change sa edit form, at ibang pangalan sa parehong ID sa add form. */
    public static function changes(): array
    {
        return [
            'rename'           => [['mode' => 'update', 'original_ad_account_id' => '1001', 'ad_account_id' => '1001', 'name' => 'Likha Bago'], '1001'],
            'id change'        => [['mode' => 'update', 'original_ad_account_id' => '1001', 'ad_account_id' => '2002', 'name' => 'Likha Main'], '2002'],
            'add existing id'  => [['ad_account_id' => '1001', 'name' => 'Likha Bago'], '1001'],
        ];
    }

    #[DataProvider('changes')]
    public function test_S_103_2_change_keeps_the_old_row_inactive_and_writes_a_new_active_row(array $form, string $newId): void
    {
        $ana = $this->staff();
        $ben = $this->staff('Ben Ads', 'ben@example.test');
        $this->actingAs($ana)->post('/ads_manager/ad_account', ['ad_account_id' => '1001', 'name' => 'Likha Main']);
        Carbon::setTestNow('2026-10-21 10:00:00');

        $this->actingAs($ben)->post('/ads_manager/ad_account', $form)->assertRedirect('/ads_manager/ad_account');

        $all = DB::table('ad_accounts')->orderBy('id')->get();
        $this->assertCount(2, $all);
        [$old, $new] = [$all[0], $all[1]];
        $this->assertFalse((bool) $old->active);
        $this->assertSame($ben->id, (int) $old->replaced_by);
        $this->assertSame('2026-10-21 10:00:00', Carbon::parse($old->replaced_at)->format('Y-m-d H:i:s'));
        $this->assertSame('1001', $old->ad_account_id);
        $this->assertSame('Likha Main', $old->name);

        $this->assertTrue((bool) $new->active);
        $this->assertSame($newId, $new->ad_account_id);
        $this->assertSame($form['name'], $new->name);
        $this->assertSame($ben->id, (int) $new->created_by);
        $this->assertSame('changed', $new->action);
    }

    public function test_S_103_3_saving_the_same_values_writes_no_history_row(): void
    {
        $ana = $this->staff();
        $this->actingAs($ana)->post('/ads_manager/ad_account', ['ad_account_id' => '1001', 'name' => 'Likha Main']);

        $this->actingAs($ana)->post('/ads_manager/ad_account', ['mode' => 'update', 'original_ad_account_id' => '1001', 'ad_account_id' => '1001', 'name' => 'Likha Main']);
        $this->actingAs($ana)->post('/ads_manager/ad_account', ['ad_account_id' => '1001', 'name' => 'Likha Main']);

        $this->assertSame(1, DB::table('ad_accounts')->count());
    }

    public function test_S_103_4_id_change_onto_an_active_id_is_refused_and_changes_nothing(): void
    {
        $ana = $this->staff();
        $this->actingAs($ana)->post('/ads_manager/ad_account', ['ad_account_id' => '1001', 'name' => 'Uno']);
        $this->actingAs($ana)->post('/ads_manager/ad_account', ['ad_account_id' => '2002', 'name' => 'Dos']);

        $this->actingAs($ana)->from('/ads_manager/ad_account/1001')
            ->post('/ads_manager/ad_account', ['mode' => 'update', 'original_ad_account_id' => '1001', 'ad_account_id' => '2002', 'name' => 'Uno'])
            ->assertRedirect('/ads_manager/ad_account/1001')
            ->assertSessionHasErrors('ad_account_id');

        $this->assertSame(2, DB::table('ad_accounts')->where('active', true)->count());
        $this->assertSame(2, DB::table('ad_accounts')->count());
    }

    public function test_S_103_5_delete_marks_the_row_inactive_and_keeps_it(): void
    {
        $ana = $this->staff();
        $ben = $this->staff('Ben Ads', 'ben@example.test');
        $this->actingAs($ana)->post('/ads_manager/ad_account', ['ad_account_id' => '1001', 'name' => 'Likha Main']);

        $this->actingAs($ben)->delete('/ads_manager/ad_account/1001')->assertRedirect('/ads_manager/ad_account');

        $rows = $this->rows('1001');
        $this->assertCount(1, $rows);
        $this->assertFalse((bool) $rows[0]->active);
        $this->assertSame('deleted', $rows[0]->action);
        $this->assertSame($ben->id, (int) $rows[0]->replaced_by);
        $this->assertNotNull($rows[0]->replaced_at);
    }

    public function test_S_103_6_the_list_shows_active_rows_only_with_added_by_and_unknown_for_old_rows(): void
    {
        // Lumang row bago ang history: walang created_by.
        DB::table('ad_accounts')->insert(['ad_account_id' => '9009', 'name' => 'Lumang Account', 'created_at' => '2025-10-20 08:00:00', 'updated_at' => '2025-10-21 14:30:00']);
        $ana = $this->staff();
        $this->actingAs($ana)->post('/ads_manager/ad_account', ['ad_account_id' => '1001', 'name' => 'Pangalang Luma']);
        $this->actingAs($ana)->post('/ads_manager/ad_account', ['ad_account_id' => '1001', 'name' => 'Pangalang Bago']);
        $this->actingAs($ana)->post('/ads_manager/ad_account', ['ad_account_id' => '3003', 'name' => 'Binurang Account']);
        $this->actingAs($ana)->delete('/ads_manager/ad_account/3003');

        $res = $this->actingAs($ana)->get('/ads_manager/ad_account')->assertOk();

        $rows = $res->viewData('rows');
        $this->assertEqualsCanonicalizing(['9009', '1001'], $rows->pluck('ad_account_id')->all());
        $res->assertSeeInOrder(['Added by', 'Last change', '9009', 'Lumang Account', 'unknown', 'Oct 21, 2025 2:30 PM', '1001', 'Pangalang Bago', 'Ana Marketing', 'Oct 21, 2026 9:00 AM', 'Change log']);
    }

    public function test_S_103_7_change_log_lists_adds_changes_and_deletes_newest_first(): void
    {
        DB::table('ad_accounts')->insert(['ad_account_id' => '9009', 'name' => 'Lumang Account', 'created_at' => '2025-10-20 08:00:00', 'updated_at' => '2025-10-20 08:00:00']);
        $ana = $this->staff();
        $ben = $this->staff('Ben Ads', 'ben@example.test');

        Carbon::setTestNow('2026-10-21 09:00:00');
        $this->actingAs($ana)->post('/ads_manager/ad_account', ['ad_account_id' => '1001', 'name' => 'Pangalang Luma']);
        Carbon::setTestNow('2026-10-21 10:15:00');
        $this->actingAs($ben)->post('/ads_manager/ad_account', ['mode' => 'update', 'original_ad_account_id' => '1001', 'ad_account_id' => '1002', 'name' => 'Pangalang Bago']);
        Carbon::setTestNow('2026-10-21 11:30:00');
        $this->actingAs($ana)->delete('/ads_manager/ad_account/1002');

        $res = $this->actingAs($ana)->get('/ads_manager/ad_account')->assertOk();

        $log = $res->viewData('log');
        $this->assertSame(
            [
                ['deleted', '1002', 'Ana Marketing'],
                ['changed', '1002', 'Ben Ads'],
                ['added', '1001', 'Ana Marketing'],
                ['added', '9009', 'unknown'],
            ],
            collect($log->items())->map(fn ($e) => [$e->action, $e->ad_account_id, $e->who])->all()
        );
        $changed = $log->items()[1];
        $this->assertSame(['1001', 'Pangalang Luma', 'Pangalang Bago'], [$changed->old_ad_account_id, $changed->old_name, $changed->name]);
        $res->assertSeeInOrder([
            'Change log',
            'Oct 21, 2026 11:30 AM', 'Ana Marketing', 'deleted', '1002', 'Pangalang Bago',
            'Oct 21, 2026 10:15 AM', 'Ben Ads', 'changed', '1001', '1002', 'Pangalang Luma', '→', 'Pangalang Bago',
            'Oct 21, 2026 9:00 AM', 'Ana Marketing', 'added', '1001', 'Pangalang Luma',
            'Oct 20, 2025 8:00 AM', 'unknown', 'added', '9009', 'Lumang Account',
        ]);
    }

    public function test_S_103_7_two_renames_by_one_user_in_the_same_second_keep_their_own_old_names(): void
    {
        $ana = $this->staff();
        $this->actingAs($ana)->post('/ads_manager/ad_account', ['ad_account_id' => '1001', 'name' => 'Uno Luma']);
        $this->actingAs($ana)->post('/ads_manager/ad_account', ['ad_account_id' => '2002', 'name' => 'Dos Luma']);
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->actingAs($ana)->post('/ads_manager/ad_account', ['ad_account_id' => '1001', 'name' => 'Uno Bago']);
        $this->actingAs($ana)->post('/ads_manager/ad_account', ['ad_account_id' => '2002', 'name' => 'Dos Bago']);

        $log = $this->actingAs($ana)->get('/ads_manager/ad_account')->assertOk()->viewData('log');

        $changed = collect($log->items())->where('action', 'changed')
            ->map(fn ($e) => [$e->old_ad_account_id, $e->old_name, $e->ad_account_id, $e->name])->values()->all();
        $this->assertSame([['2002', 'Dos Luma', '2002', 'Dos Bago'], ['1001', 'Uno Luma', '1001', 'Uno Bago']], $changed);
    }

    public function test_S_103_8_change_log_pages_by_50(): void
    {
        $ana = $this->staff();
        for ($i = 1; $i <= 51; $i++) {
            Carbon::setTestNow(Carbon::parse('2026-10-21 09:00:00')->addMinutes($i));
            $this->actingAs($ana)->post('/ads_manager/ad_account', ['ad_account_id' => (string) (5000 + $i), 'name' => "Account {$i}"]);
        }

        $log = $this->actingAs($ana)->get('/ads_manager/ad_account')->assertOk()->viewData('log');
        $this->assertSame(50, count($log->items()));
        $this->assertSame(51, $log->total());
        $this->assertSame('5051', $log->items()[0]->ad_account_id);

        $page2 = $this->actingAs($ana)->get('/ads_manager/ad_account?log_page=2')->assertOk()->viewData('log');
        $this->assertSame(['5001'], collect($page2->items())->pluck('ad_account_id')->all());
    }

    public function test_S_103_9_payment_records_join_only_the_active_ad_account_row(): void
    {
        $at = ['created_at' => now(), 'updated_at' => now()];
        DB::table('ad_accounts')->insert([
            ['ad_account_id' => '1001', 'name' => 'Pangalang Luma', 'active' => false, 'action' => 'added'] + $at,
            ['ad_account_id' => '1001', 'name' => 'Pangalang Bago', 'active' => true, 'action' => 'changed'] + $at,
            ['ad_account_id' => '3003', 'name' => 'Binura', 'active' => false, 'action' => 'deleted'] + $at,
        ]);
        foreach ([['t-1', '1001'], ['t-2', '3003']] as [$txn, $acct]) {
            DB::table('payment_activity_ads_manager')->insert([
                'date' => '2026-10-20', 'transaction_id' => $txn, 'amount' => 500, 'ad_account' => $acct,
                'payment_method' => 'Visa ···· 0000', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $res = $this->actingAs($this->user())->get('/ads_manager/payment/records?start_date=2026-10-01&end_date=2026-10-21')->assertOk();

        $rows = collect($res->viewData('rows')->items());
        $this->assertSame(['1001' => 'Pangalang Bago', '3003' => '3003'], $rows->pluck('ad_account_name', 'ad_account')->sortKeys()->all());
        $this->assertCount(2, $rows);
        $this->assertSame(1000.0, $res->viewData('totalAmount'));
        $this->assertSame(['1001' => 'Pangalang Bago', '3003' => '3003'], $res->viewData('adAccountOptions')->pluck('name', 'id')->sortKeys()->all());
    }
}
