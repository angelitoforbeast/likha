<?php

namespace Tests\Feature\OwnerPrivate;

use App\Http\Controllers\OwnerPrivateController;
use App\Services\ItemAliasResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;

/**
 * rtsSetByMap: sino ang huling nag-log ng RTS% na ipinapakita ng row (read-only, display lang).
 */
class RtsSetByMapTest extends OwnerPrivateTestCase
{
    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), [
            'database/migrations/2026_05_04_000001_create_page_item_settings_log_table.php',
        ]);
    }

    private function map(string $date = '2026-10-01'): array
    {
        $m = new ReflectionMethod(OwnerPrivateController::class, 'rtsSetByMap');
        $m->setAccessible(true);

        return $m->invoke(app(OwnerPrivateController::class), $date, new ItemAliasResolver());
    }

    private function log(array $over): void
    {
        DB::table('page_item_settings_log')->insert(array_merge([
            'user_email'     => 'someone@example.test',
            'action'         => 'update',
            'page_name'      => 'Page One',
            'item_name'      => 'Item A',
            'effective_date' => '2026-09-30',
            'old_rts_pct'    => null,
            'new_rts_pct'    => 70,
        ], $over));
    }

    public function test_latest_log_wins_and_name_is_never_a_full_email(): void
    {
        $this->user('CEO', 'first@example.test')->employeeProfile()->update(['name' => 'First Person']);

        $this->log(['user_email' => 'old@example.test']);
        $this->log(['user_email' => 'first@example.test']);
        $this->assertSame('First Person', $this->map()['page one||itema||2026-09-30||70.00']);

        // page name: case/space-insensitive; hindi kilalang email = bahagi bago ang @
        $this->log(['page_name' => '  PAGE TWO ', 'item_name' => 'Item B', 'user_email' => 'unknown.person@example.test']);
        $this->assertSame('unknown.person', $this->map()['page two||itemb||2026-09-30||70.00']);

        // RTS hindi nagbago (promo/cost-only save) = skip
        $this->log(['item_name' => 'Item C', 'old_rts_pct' => 70, 'new_rts_pct' => 70]);
        $this->assertArrayNotHasKey('page one||itemc||2026-09-30||70.00', $this->map());

        // dated pagkatapos ng as-of date = wala sa map
        $this->log(['item_name' => 'Item D', 'effective_date' => '2026-10-02']);
        $this->assertArrayNotHasKey('page one||itemd||2026-10-02||70.00', $this->map());
    }

    public function test_missing_table_gives_empty_map(): void
    {
        Schema::drop('page_item_settings_log');

        $this->assertSame([], $this->map());
    }
}
