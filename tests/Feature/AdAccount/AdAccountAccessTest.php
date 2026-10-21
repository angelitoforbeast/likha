<?php

namespace Tests\Feature\AdAccount;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\OwnerPrivate\OwnerPrivateTestCase;

/**
 * /ads_manager/ad_account: walang sariling nav link; sumusunod sa access ng Ad Payment link (ads_payment)
 * sa /owner/nav-settings, sa lahat ng apat na route at sa "Ad Account ID" button ng payment upload.
 */
class AdAccountAccessTest extends OwnerPrivateTestCase
{
    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), [
            'database/migrations/2025_10_19_154416_create_ad_accounts_table.php',
            'database/migrations/2026_10_31_100000_add_history_to_ad_accounts_table.php',
            // Ang seed nito: Ad Payment para sa Marketing, Marketing - OIC at CEO.
            'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php',
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
        Schema::create('upload_logs', function (Blueprint $t) { // listahan sa payment upload page
            $t->id();
            $t->string('type')->nullable();
            $t->timestamps();
        });
    }

    /** Bubuksan, magdadagdag, magpapalit ng pangalan at magbubura; ang status ng bawat isa. */
    private function walk(): array
    {
        DB::table('ad_accounts')->insert(['ad_account_id' => '1001', 'name' => 'Likha Main', 'active' => true, 'action' => 'added', 'created_at' => now(), 'updated_at' => now()]);

        return [
            'open'   => $this->get('/ads_manager/ad_account')->status(),
            'edit'   => $this->get('/ads_manager/ad_account/1001')->status(),
            'add'    => $this->post('/ads_manager/ad_account', ['ad_account_id' => '2002', 'name' => 'Likha Dos'])->status(),
            'rename' => $this->post('/ads_manager/ad_account', ['mode' => 'update', 'original_ad_account_id' => '1001', 'ad_account_id' => '1001', 'name' => 'Likha Bago'])->status(),
            'delete' => $this->delete('/ads_manager/ad_account/2002')->status(),
        ];
    }

    private function adPaymentVisible(string $role, bool $visible): void
    {
        $link = DB::table('nav_links')->where('key', 'ads_payment')->value('id');
        DB::table('nav_link_role_visibility')->updateOrInsert(['nav_link_id' => $link, 'role' => $role], ['is_visible' => $visible]);
    }

    public static function allowedRoles(): array
    {
        return ['Marketing' => ['Marketing'], 'Marketing - OIC' => ['Marketing - OIC'], 'CEO' => ['CEO']];
    }

    #[DataProvider('allowedRoles')]
    public function test_S_105_1_roles_that_see_ad_payment_can_open_add_rename_and_delete(string $role): void
    {
        $this->actingAs($this->user($role, 'u@example.test'));

        $this->assertSame(['open' => 200, 'edit' => 200, 'add' => 302, 'rename' => 302, 'delete' => 302], $this->walk());
        $this->assertSame('Likha Bago', DB::table('ad_accounts')->where('active', true)->where('ad_account_id', '1001')->value('name'));
        $this->assertFalse(DB::table('ad_accounts')->where('active', true)->where('ad_account_id', '2002')->exists());
    }

    public function test_S_105_2_a_role_without_ad_payment_gets_404_on_all_four_routes_and_nothing_changes(): void
    {
        $this->actingAs($this->user('Data Encoder', 'de@example.test'));

        $this->assertSame(['open' => 404, 'edit' => 404, 'add' => 404, 'rename' => 404, 'delete' => 404], $this->walk());
        $this->assertSame([['1001', 'Likha Main', 1]], DB::table('ad_accounts')->get()->map(fn ($r) => [$r->ad_account_id, $r->name, (int) $r->active])->all());
    }

    public function test_S_105_3_flipping_ad_payment_visibility_flips_ad_accounts_access(): void
    {
        $this->actingAs($this->user('Data Encoder', 'de@example.test'));
        $this->get('/ads_manager/ad_account')->assertNotFound();

        $this->adPaymentVisible('Data Encoder', true);
        $this->get('/ads_manager/ad_account')->assertOk();

        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->adPaymentVisible('Marketing', false);
        $this->get('/ads_manager/ad_account')->assertNotFound();
    }

    public function test_S_105_4_the_ad_account_id_button_shows_only_to_roles_that_pass(): void
    {
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->get('/ads_manager/payment/upload')->assertOk()->assertSee('Ad Account ID');

        $this->actingAs($this->user('Data Encoder', 'de@example.test'));
        $this->get('/ads_manager/payment/upload')->assertOk()->assertDontSee('Ad Account ID');
    }
}
