<?php

namespace Tests\Feature\MetaAds;

use App\Services\MetaAds\Compare;
use App\Services\MetaAds\Connections;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/** Ang pangalan ng ad account: ang galing sa Meta, maliban kung wala o ID lang; saka ang active na ad_accounts row; saka ang ID. */
class MetaAdsAccountNamesTest extends MetaAdsTestCase
{
    private const DAY = '2026-10-20';

    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), [
            'database/migrations/2025_10_19_154416_create_ad_accounts_table.php',
            'database/migrations/2026_10_31_100000_add_history_to_ad_accounts_table.php',
        ]);
    }

    private function adAccount(string $id, string $name, bool $active = true): void
    {
        DB::table('ad_accounts')->insert(['ad_account_id' => $id, 'name' => $name, 'active' => $active, 'action' => 'added', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Isang connection na ang ad account ay may ganitong pangalan mula sa Meta. */
    private function connectNamed(string $id, string $metaName): void
    {
        $user = $this->user('Marketing', 'ana@example.test');
        app(Connections::class)->save($user->id, self::TOKEN_ANA, [
            'fb_user_id' => 'fb' . $user->id, 'fb_user_name' => 'Ana FB', 'expires_at' => now()->addDays(50)->timestamp,
            'ad_accounts' => [['id' => $id, 'name' => $metaName, 'status' => 1]],
        ]);
    }

    public static function names(): array
    {
        return [
            'S-176.1 Meta name is the ID'          => ['1978377456240271', '1978377456240271', [['1978377456240271', 'Vane Mendoza', true]], 'Vane Mendoza'],
            'S-176.2 a real Meta name is kept'     => ['1380335190216061', 'Vanie Lopez', [['1380335190216061', 'Iba', true]], 'Vanie Lopez'],
            'S-176.3 no name anywhere: the ID'     => ['784395860945426', '', [], '784395860945426'],
            'S-176.4 an inactive row is not used'  => ['784395860945426', '', [['784395860945426', 'Luma', false]], '784395860945426'],
            'S-176.5 Meta name act_ID'             => ['1624473525582198', 'act_1624473525582198', [['1624473525582198', 'John Santos', true]], 'John Santos'],
            'S-176.5 ad_accounts ID with act_'     => ['1332339022040935', '', [['act_1332339022040935', 'Xander David', true]], 'Xander David'],
        ];
    }

    #[DataProvider('names')]
    public function test_S_176_the_account_name_falls_back_to_the_ad_accounts_page_then_the_id(string $id, string $metaName, array $rows, string $shown): void
    {
        $this->connectNamed($id, $metaName);
        foreach ($rows as [$rowId, $name, $active]) $this->adAccount($rowId, $name, $active);

        $this->assertSame($shown, app(Connections::class)->accounts()[$id]['name']);
    }

    public function test_S_176_1_the_list_the_accounts_command_and_compare_show_the_ad_accounts_name(): void
    {
        $this->connectNamed('1978377456240271', '1978377456240271');
        $this->adAccount('1978377456240271', 'Vane Mendoza');
        $this->fakeGraph();

        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $html = $this->get('/ads_manager/report')->assertOk()->getContent();
        $this->assertStringContainsString('Vane Mendoza', substr($html, strpos($html, 'value="1978377456240271"'), 600));
        $this->assertStringContainsString('Vane Mendoza <span class="font-mono text-gray-500">1978377456240271', $html);

        $this->artisan('meta-ads:accounts', ['--list' => true])->expectsOutputToContain('1978377456240271  Vane Mendoza')->assertSuccessful();
        $this->assertSame('Vane Mendoza', app(Compare::class)->run(self::DAY, self::DAY)['accounts'][0]['name']);
        // Walang isinusulat sa ad_accounts.
        $this->assertSame([['1978377456240271', 'Vane Mendoza']], DB::table('ad_accounts')->get()->map(fn ($r) => [$r->ad_account_id, $r->name])->all());
    }
}
