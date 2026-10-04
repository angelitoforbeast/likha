<?php

namespace Tests\Feature\OwnerPrivate;

use App\Http\Controllers\OwnerColumnSettingsController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

class ClaudeActionColumnSettingsTest extends OwnerPrivateTestCase
{
    private const CLAUDE = ['claude_action', 'claude_reason'];

    private function save(string $key, array $config): void
    {
        DB::table('app_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($config), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> table, role, saved settings key */
    public static function tablesAndRoles(): array
    {
        return [
            'owner_private / Marketing'      => ['owner_private', 'Marketing', 'owner_private_cols'],
            'breakdown / Marketing - OIC'    => ['breakdown', 'Marketing - OIC', 'breakdown_cols'],
        ];
    }

    #[DataProvider('tablesAndRoles')]
    public function test_non_ceo_always_hides_the_claude_columns(string $table, string $role, string $key): void
    {
        $ctl = new OwnerColumnSettingsController();

        // walang saved config (breakdown = all visible by default)
        $this->assertEqualsCanonicalizing(self::CLAUDE, array_intersect(self::CLAUDE, $ctl->loadConfig($table, $role)['hidden']));

        // kahit ibigay ng saved visible_by_role
        $this->save($key, ['visible_by_role' => [$role => ['action', 'claude_action', 'claude_reason']]]);
        $hidden = $ctl->loadConfig($table, $role)['hidden'];
        $this->assertContains('claude_action', $hidden);
        $this->assertContains('claude_reason', $hidden);
        $this->assertNotContains('action', $hidden);
    }

    #[DataProvider('tablesAndRoles')]
    public function test_ceo_sees_the_claude_columns_by_default_right_after_action(string $table): void
    {
        $config = (new OwnerColumnSettingsController())->loadConfig($table, 'CEO');

        $this->assertNotContains('claude_action', $config['hidden']);
        $this->assertNotContains('claude_reason', $config['hidden']);
        $i = array_search('action', $config['order'], true);
        $this->assertSame(['claude_action', 'claude_reason'], array_slice($config['order'], $i + 1, 2));
    }

    #[DataProvider('tablesAndRoles')]
    public function test_an_older_saved_order_gets_the_claude_columns_right_after_action(string $table, string $role, string $key): void
    {
        $this->save($key, ['order' => ['hold', 'action', 'adspent', 'orders']]);
        $ctl = new OwnerColumnSettingsController();

        foreach ([$ctl->loadConfig($table, 'CEO')['order'], $ctl->loadConfigMatrix($table)['order']] as $order) {
            $this->assertSame(['hold', 'action', 'claude_action', 'claude_reason', 'adspent', 'orders'], array_slice($order, 0, 6));
        }
    }

    public function test_no_write_route_mentions_claude(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
                $this->assertStringNotContainsStringIgnoringCase('claude', (string) $route->getName(), $route->uri());
                $this->assertStringNotContainsStringIgnoringCase('claude', $route->uri());
            }
        }
    }
}
