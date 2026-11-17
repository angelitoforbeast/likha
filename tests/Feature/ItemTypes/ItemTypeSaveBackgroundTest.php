<?php

namespace Tests\Feature\ItemTypes;

use App\Jobs\RecomputePrimaryItemsForAliases;
use App\Services\DailyPrimaryItemService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\OwnerPrivate\MacroOutputTestCase;

/**
 * Spec 289: ang save sa /jnt/item-types ay sumasagot agad; ang 90-day recompute ng main item at ang
 * cache bump ay nasa queue, isang pending job lang kahit sunod-sunod ang save.
 */
class ItemTypeSaveBackgroundTest extends MacroOutputTestCase
{
    private const ALPHA = '1 x Alpha Lamp';
    private const BETA  = '1 x Beta Fan';
    private const GAMMA = '1 x Gamma Fan';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-10 12:00:00');
        $this->orders(self::DAY, self::ALPHA, 2);
        $this->orders(self::DAY, self::BETA, 2);
        $this->orders(self::DAY, self::GAMMA, 3);
        app(DailyPrimaryItemService::class)->recomputeDates([self::DAY]);
        $this->actingAs($this->user());
    }

    private function orders(string $date, string $item, int $n): void
    {
        DB::table('macro_output')->insert(array_map(fn ($i) => [
            'PAGE' => 'Pahina Alpha', 'ITEM_NAME' => $item, 'STATUS' => 'Pending',
            'COD' => '599', 'ts_date' => $date, 'TIMESTAMP' => '10:00 ' . date('d-m-Y', strtotime($date)),
        ], range(0, $n - 1)));
    }

    /** The stored main-item rows, without ids and timestamps. */
    private function rows(): array
    {
        return DB::table('daily_page_primary_item')->orderBy('ts_date')->orderBy('page_key')->get()
            ->map(fn ($r) => collect((array) $r)->except(['id', 'created_at', 'updated_at', 'computed_at'])->all())
            ->all();
    }

    private function paste(string $rows)
    {
        return $this->post('/jnt/item-types/save', ['bulk_data' => $rows]);
    }

    public function test_save_answers_with_the_counts_and_queues_the_recompute_instead_of_running_it(): void
    {
        Queue::fake();
        $before = $this->rows();
        Cache::forever('owner_private:cache_version', 'old');

        $this->paste(self::BETA . "\t" . self::ALPHA . "\n" . self::GAMMA . "\t" . self::ALPHA)
            ->assertSessionHas('success', 'Saved. 2 inserted. Updating owner reports in the background (a few minutes).');

        $this->assertSame(2, DB::table('item_type_mappings')->count());
        Queue::assertPushed(RecomputePrimaryItemsForAliases::class, function ($job) {
            return $job->itemNames === [self::BETA, self::GAMMA]
                && $job->queue === 'default' && $job->tries === 1 && $job->timeout >= 600;
        });
        // Nothing ran inside the request: same rows, cache version untouched.
        $this->assertSame($before, $this->rows());
        $this->assertSame('old', Cache::get('owner_private:cache_version'));
    }

    public function test_save_one_and_delete_also_queue_instead_of_running(): void
    {
        Queue::fake();
        $this->postJson('/jnt/item-types/save-one', ['item_name' => self::BETA, 'item_type' => self::ALPHA])
            ->assertOk()->assertJson(['ok' => true]);
        Queue::assertPushed(RecomputePrimaryItemsForAliases::class, fn ($job) => $job->itemNames === [self::BETA]);

        Cache::forget(RecomputePrimaryItemsForAliases::PENDING_KEY); // as if the first job already ran
        $id = DB::table('item_type_mappings')->value('id');
        $this->post('/jnt/item-types/delete/' . $id)->assertSessionHas('success');
        Queue::assertPushed(RecomputePrimaryItemsForAliases::class, 2);
    }

    public function test_running_the_job_gives_the_same_rows_as_the_service_and_bumps_the_cache(): void
    {
        DB::table('item_type_mappings')->insert([
            ['item_name' => self::BETA, 'item_type' => self::ALPHA, 'created_at' => now(), 'updated_at' => now()],
            ['item_name' => self::GAMMA, 'item_type' => self::ALPHA, 'created_at' => now(), 'updated_at' => now()],
        ]);

        app(DailyPrimaryItemService::class)->recomputeForItemNames([self::BETA, self::GAMMA]);
        $direct = $this->rows();

        DB::table('daily_page_primary_item')->delete();
        Cache::forever('owner_private:cache_version', 'old');
        (new RecomputePrimaryItemsForAliases([self::BETA, self::GAMMA]))->handle(app(DailyPrimaryItemService::class));

        $this->assertNotEmpty($direct);
        $this->assertSame($direct, $this->rows());
        $this->assertSame(7, (int) $this->rows()[0]['primary_orders']); // alpha + beta + gamma as one family
        $this->assertNotSame('old', Cache::get('owner_private:cache_version'));
    }

    public function test_double_saves_leave_one_pending_job_that_recomputes_every_name(): void
    {
        Queue::fake();

        $this->paste(self::BETA . "\t" . self::ALPHA)->assertSessionHas('success');
        $this->paste(self::GAMMA . "\t" . self::ALPHA)->assertSessionHas('success');
        $this->paste(self::GAMMA . "\t" . self::ALPHA)->assertSessionHas('error'); // no change, nothing queued

        Queue::assertPushed(RecomputePrimaryItemsForAliases::class, 1);
        $this->assertSame([self::BETA, self::GAMMA], Cache::get(RecomputePrimaryItemsForAliases::PENDING_KEY)['names']);

        // The one job takes the merged list, so the second save's name is recomputed too.
        $job = Queue::pushed(RecomputePrimaryItemsForAliases::class)->first();
        $this->assertSame([self::BETA], $job->itemNames);
        $job->handle(app(DailyPrimaryItemService::class));

        $this->assertSame(7, (int) $this->rows()[0]['primary_orders']);
        $this->assertNull(Cache::get(RecomputePrimaryItemsForAliases::PENDING_KEY));

        // A save after the job took the list queues the next one.
        $this->paste(self::ALPHA . "\t" . self::ALPHA)->assertSessionHas('success');
        Queue::assertPushed(RecomputePrimaryItemsForAliases::class, 2);
    }

    public function test_a_stale_pending_list_queues_a_new_job(): void
    {
        Queue::fake();
        $this->paste(self::BETA . "\t" . self::ALPHA);
        $this->travel(RecomputePrimaryItemsForAliases::STALE_SECONDS + 1)->seconds();
        $this->paste(self::GAMMA . "\t" . self::ALPHA);

        Queue::assertPushed(RecomputePrimaryItemsForAliases::class, 2);
    }

    public function test_a_failing_recompute_is_logged_by_class_and_keeps_the_mapping(): void
    {
        $this->mock(DailyPrimaryItemService::class, function ($m) {
            $m->shouldReceive('recomputeForItemNames')->andThrow(new \RuntimeException('boom'));
        });
        Log::spy();

        // The sync test queue runs the job inside the request; its failure must not break the save.
        $this->paste(self::BETA . "\t" . self::ALPHA)->assertSessionHas('success');

        $this->assertSame(1, DB::table('item_type_mappings')->count());
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($msg) => str_contains($msg, 'RecomputePrimaryItemsForAliases failed: RuntimeException'))
            ->once();
    }
}
