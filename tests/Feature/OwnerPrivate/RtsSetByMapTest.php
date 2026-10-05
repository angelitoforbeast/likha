<?php

namespace Tests\Feature\OwnerPrivate;

use App\Http\Controllers\OwnerPrivateController;
use App\Services\ItemAliasResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;

/**
 * rtsSetByMap: sino ang NAGSIMULA ng RTS% na ipinapakita ng row (read-only, display lang).
 */
class RtsSetByMapTest extends OwnerPrivateTestCase
{
    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), [
            'database/migrations/2026_05_04_000001_create_page_item_settings_log_table.php',
        ]);
    }

    private function map(string $date = '2026-10-05'): array
    {
        $m = new ReflectionMethod(OwnerPrivateController::class, 'rtsSetByMap');
        $m->setAccessible(true);

        return $m->invoke(app(OwnerPrivateController::class), $date, new ItemAliasResolver());
    }

    private function log(string $who, string $effective, ?float $old, float $new, array $over = []): void
    {
        DB::table('page_item_settings_log')->insert(array_merge([
            'user_email'     => $who.'@example.test',
            'action'         => 'update',
            'page_name'      => 'Page One',
            'item_name'      => 'Item A',
            'effective_date' => $effective,
            'old_rts_pct'    => $old,
            'new_rts_pct'    => $new,
        ], $over));
    }

    private function named(string $local, string $name): void
    {
        $this->user('CEO', $local.'@example.test')->employeeProfile()->update(['name' => $name]);
    }

    public function test_value_is_credited_to_who_started_its_run(): void
    {
        foreach (['a' => 'Person A', 'b' => 'Person B', 'c' => 'Person C', 'd' => 'Person D'] as $k => $n) {
            $this->named($k, $n);
        }
        $this->log('a', '2026-09-28', null, 70);   // nagsimula ng 70
        $this->log('b', '2026-10-01', null, 70);   // promo-only save: carried 70
        $this->log('c', '2026-10-03', 70, 65);     // totoong pagbabago
        $this->log('d', '2026-09-01', null, 60);   // back-dated, mas mataas ang id

        $map = $this->map();

        $this->assertSame('Person A', $map['page one||itema||2026-09-28||70.00']);
        $this->assertSame('Person A', $map['page one||itema||2026-10-01||70.00']);
        $this->assertSame('Person C', $map['page one||itema||2026-10-03||65.00']);
        $this->assertSame('Person D', $map['page one||itema||2026-09-01||60.00']);
    }

    public function test_page_key_unknown_email_and_date_limit(): void
    {
        $this->log('unknown.person', '2026-09-30', null, 70, ['page_name' => '  PAGE TWO ', 'item_name' => 'Item B']);
        $this->log('late', '2026-10-02', null, 70, ['item_name' => 'Item D']);

        $map = $this->map('2026-10-01');

        // case/space-insensitive; hindi kilalang email = bahagi bago ang @ lang
        $this->assertSame('unknown.person', $map['page two||itemb||2026-09-30||70.00']);
        // dated pagkatapos ng as-of date = wala sa map
        $this->assertArrayNotHasKey('page one||itemd||2026-10-02||70.00', $map);
    }

    public function test_run_started_by_promo_or_cogs_save_has_no_name(): void
    {
        Schema::table('page_item_settings_log', fn (Blueprint $t) => $t->string('scope')->nullable());
        $this->log('p', '2026-09-28', null, 70, ['scope' => 'promo']);
        $this->log('q', '2026-10-01', null, 70, ['scope' => 'promo']);

        $this->assertSame([], $this->map());
    }

    public function test_missing_table_gives_empty_map(): void
    {
        Schema::drop('page_item_settings_log');

        $this->assertSame([], $this->map());
    }
}
