<?php

namespace Tests\Feature\OwnerPrivate;

use App\Http\Controllers\OwnerColumnSettingsController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
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

    // ── Part A: mga column na ito ay gumagana gaya ng kahit anong column (kapareho ng 'action') ──

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Kailangan ng layout at ng /owner/private + breakdown pages (gaya ng ClaudeActionViewTest).
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('ads_manager_reports', function (Blueprint $t) {
            $t->id();
            $t->string('page_name')->nullable();
        });
        Schema::create('fee_settings', function (Blueprint $t) {
            $t->id();
            $t->string('setting_key');
            $t->decimal('setting_value', 14, 6)->nullable();
            $t->date('effective_date')->nullable();
            $t->string('description')->nullable();
            $t->string('host_scope')->nullable();
            $t->timestamps();
        });
        $this->actingAs($this->user('CEO'));
    }

    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), [
            'database/migrations/2026_04_24_000009_create_daily_page_primary_item_table.php',
        ]);
    }

    /** @return array<string, array{0: string, 1: string}> table, saved settings key */
    public static function sections(): array
    {
        return [
            'owner_private' => ['owner_private', 'owner_private_cols'],
            'breakdown'     => ['breakdown', 'breakdown_cols'],
        ];
    }

    private function catalogOrder(string $table): array
    {
        return array_column(OwnerColumnSettingsController::CATALOG[$table], 'id');
    }

    private function postSave(string $table, array $fields)
    {
        return $this->postJson(route('owner.column-settings.save'), ['table' => $table] + $fields)->assertOk();
    }

    private function settingsUrl(string $table): string
    {
        return '/owner/column-settings/' . str_replace('_', '-', $table);
    }

    /** Ang hidden list na ipinadala ng totoong page + ang HTML (breakdown: para sa mga <th>). */
    private function pageState(string $table): array
    {
        if ($table === 'owner_private') {
            $html = $this->get('/owner/private')->assertOk()->getContent();
            $this->assertSame(1, preg_match('/window\.__OWNER_PRIVATE_COLS__ = (\{.*\});/', $html, $m));
            $cfg = json_decode($m[1], true);
            $this->assertIsArray($cfg);

            return [$cfg['hidden'], $html];
        }
        $html = $this->get('/owner/private/breakdown?page_key=page+a&start_date=2026-10-01&end_date=2026-10-03')->assertOk()->getContent();
        $this->assertSame(1, preg_match('/\bhidden:\s+(\[[^\]]*\]),/', $html, $m));

        return [json_decode($m[1], true), $html];
    }

    #[DataProvider('sections')]
    public function test_ceo_hide_and_show_of_the_claude_columns_works_like_action(string $table): void
    {
        $ctl = new OwnerColumnSettingsController();
        $three = array_merge(['action'], self::CLAUDE);

        $this->postSave($table, ['order' => $this->catalogOrder($table), 'hidden' => $three]);
        [$pageHidden, $html] = $this->pageState($table);
        foreach ($three as $id) {
            $this->assertContains($id, $ctl->loadConfig($table, 'CEO')['hidden'], $id);
            $this->assertContains($id, $pageHidden, $id);
        }
        if ($table === 'breakdown') {
            $this->assertStringNotContainsString('Claude Action</th>', $html);
            $this->assertStringNotContainsString('Claude Reason</th>', $html);
        }

        $this->postSave($table, ['order' => $this->catalogOrder($table), 'hidden' => []]);
        [$pageHidden, $html] = $this->pageState($table);
        foreach ($three as $id) {
            $this->assertNotContains($id, $ctl->loadConfig($table, 'CEO')['hidden'], $id);
            $this->assertNotContains($id, $pageHidden, $id);
        }
        if ($table === 'breakdown') {
            $this->assertStringContainsString('Claude Action</th>', $html);
            $this->assertStringContainsString('Claude Reason</th>', $html);
        }
    }

    /** Ang dalawang id ay kasunod agad ng 'action' sa order. */
    private function assertClaudeAfterAction(array $order, string $msg): void
    {
        $i = array_search('action', $order, true);
        $this->assertNotFalse($i, $msg);
        $this->assertSame(self::CLAUDE, array_slice($order, $i + 1, 2), $msg);
    }

    private function assertCeoSeesClaudeAfterAction(string $table): void
    {
        $ctl = new OwnerColumnSettingsController();
        $config = $ctl->loadConfig($table, 'CEO');
        $this->assertClaudeAfterAction($config['order'], 'loadConfig');
        $this->assertClaudeAfterAction($ctl->loadConfigMatrix($table)['order'], 'matrix');
        foreach (self::CLAUDE as $id) {
            $this->assertNotContains($id, $config['hidden'], $id);
        }
    }

    private function resetFrom(string $table)
    {
        return $this->from($this->settingsUrl($table))
            ->post(route('owner.column-settings.reset-to-default', $table))
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    private function raw(string $key): ?string
    {
        return DB::table('app_settings')->where('key', $key)->value('value');
    }

    #[DataProvider('sections')]
    public function test_save_as_default_then_reset_restores_the_snapshot_with_the_claude_columns(string $table, string $key): void
    {
        $this->postSave($table, ['order' => $this->catalogOrder($table), 'hidden' => ['hold']]);
        $this->from($this->settingsUrl($table))
            ->post(route('owner.column-settings.save-as-default', $table))
            ->assertRedirect()->assertSessionHasNoErrors();
        $snapshot = $this->raw($key);

        $this->postSave($table, ['hidden' => array_merge(['hold'], self::CLAUDE)]);
        $this->assertNotSame($snapshot, $this->raw($key));

        $this->resetFrom($table);
        $this->assertSame($snapshot, $this->raw($key));
        $this->assertCeoSeesClaudeAfterAction($table);
    }

    #[DataProvider('sections')]
    public function test_reset_to_an_older_snapshot_without_the_claude_ids_still_places_them_after_action(string $table, string $key): void
    {
        $this->save($key . '_default', [
            'order'           => ['hold', 'action', 'adspent', 'orders'],
            'hidden'          => ['hold'],
            'visible_by_role' => ['Marketing' => ['hold', 'action']],
        ]);

        $this->resetFrom($table);

        $this->assertCeoSeesClaudeAfterAction($table);
    }

    #[DataProvider('sections')]
    public function test_reset_without_a_snapshot_uses_the_code_default_with_the_claude_columns(string $table, string $key): void
    {
        $this->postSave($table, ['hidden' => self::CLAUDE]);
        $this->assertNull($this->raw($key . '_default'));

        $this->resetFrom($table);

        $this->assertCeoSeesClaudeAfterAction($table);
    }

    #[DataProvider('sections')]
    public function test_settings_page_sends_a_row_and_label_for_each_claude_column(string $table): void
    {
        $html = $this->get($this->settingsUrl($table))->assertOk()->getContent();

        // @js = JSON.parse('<js-escaped json>'): i-decode ang string literal, saka ang JSON.
        $this->assertSame(1, preg_match("/sectionState\\('{$table}', JSON\\.parse\\('(.*?)'\\), /", $html, $m));
        $matrix = json_decode((string) json_decode('"' . $m[1] . '"'), true);
        $this->assertIsArray($matrix);
        $this->assertClaudeAfterAction($matrix['order'], 'matrix sent to the page');

        $this->assertSame(1, preg_match('/const COL_CATALOG\s*=\s*(\{.*\});/', $html, $c));
        $labels = array_column(json_decode($c[1], true)[$table], 'label', 'id');
        $this->assertSame('Claude Action (CEO)', $labels['claude_action']);
        $this->assertSame('Claude Reason (CEO)', $labels['claude_reason']);
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
