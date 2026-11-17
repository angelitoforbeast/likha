<?php

namespace Tests\Feature\OwnerPrivate;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/**
 * The Item column sort on /owner/private: the "N x " count prefix is not part of the name, so the same item
 * stays together (name first, case ignored, then the smaller count). Runs the page's own sortedRows() in node
 * (tests/js/private-sort.cjs) on a real render of the page. Other columns sort as before.
 */
class ItemSortTest extends OwnerPrivateTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // The layout (task badge) needs it, as in FitToWidthTest.
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
    }

    /** Rows in their current (filtered) order, before any sort. */
    private const ROWS = [
        ['item_name' => '2 x HAND GRIP', 'page_name' => 'Shop C', 'orders' => 5],
        ['item_name' => '1 x YOGA MAT',  'page_name' => '2 x Shop', 'orders' => 12],
        ['item_name' => '1 x hand grip', 'page_name' => 'Shop A', 'orders' => 3],
        ['item_name' => '2 x Yoga Mat',  'page_name' => '1 x Shop B', 'orders' => 7],
        ['item_name' => 'AB ROLLER',     'page_name' => 'Shop D', 'orders' => 1],
        ['item_name' => '1 x HAND GRIP', 'page_name' => 'Shop E', 'orders' => 9],
        ['item_name' => '10 x Yoga Mat', 'page_name' => 'Shop F', 'orders' => 2],
        ['item_name' => '3XL Shirt',     'page_name' => 'Shop G', 'orders' => 4],
    ];

    private function html(): string
    {
        $this->actingAs($this->user('CEO'));

        return $this->get('/owner/private')->assertOk()->getContent();
    }

    /** Runs sortedRows() for each {col, dir} on ROWS; returns one order per sort. */
    private function sorted(array $sorts): array
    {
        try {
            $probe = new Process(['node', '--version']);
            $probe->run();
            $found = $probe->isSuccessful();
        } catch (\Throwable $e) {
            $found = false;
        }
        if (!$found) $this->markTestSkipped('skipped: node not found');

        $file = tempnam(sys_get_temp_dir(), 'ops');
        file_put_contents($file, $this->html());
        try {
            $process = new Process(['node', base_path('tests/js/private-sort.cjs'), $file]);
            $process->setInput(json_encode(['rows' => self::ROWS, 'sorts' => $sorts], JSON_THROW_ON_ERROR));
            $process->run();
            $this->assertTrue($process->isSuccessful(), 'node: ' . trim($process->getErrorOutput()));
        } finally {
            @unlink($file);
        }

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_item_sort_groups_one_item_and_puts_the_smaller_count_first(): void
    {
        [$asc, $desc] = $this->sorted([['col' => 'item_name', 'dir' => 'asc'], ['col' => 'item_name', 'dir' => 'desc']]);

        // A name with no count sorts as count 0; same name and count (case ignored) keep their current order.
        // "3XL Shirt" has no space after the x, so it is a name, not a count of 3: it sorts as "3XL Shirt".
        $this->assertSame([
            '3XL Shirt',
            'AB ROLLER',
            '1 x hand grip', '1 x HAND GRIP', '2 x HAND GRIP',
            '1 x YOGA MAT', '2 x Yoga Mat', '10 x Yoga Mat',
        ], $asc);

        // Descending reverses names and counts; equal rows still keep their current order.
        $this->assertSame([
            '10 x Yoga Mat', '2 x Yoga Mat', '1 x YOGA MAT',
            '2 x HAND GRIP', '1 x hand grip', '1 x HAND GRIP',
            'AB ROLLER',
            '3XL Shirt',
        ], $desc);
    }

    public function test_other_columns_sort_as_before(): void
    {
        [$page, $orders] = $this->sorted([['col' => 'page_name', 'dir' => 'asc'], ['col' => 'orders', 'dir' => 'desc']]);

        // Text columns still compare the full text, count prefix included.
        $this->assertSame(['1 x Shop B', '2 x Shop', 'Shop A', 'Shop C', 'Shop D', 'Shop E', 'Shop F', 'Shop G'], array_column($page, 1));
        $this->assertSame([12, 9, 7, 5, 4, 3, 2, 1], array_column($orders, 1));
    }

    public function test_the_item_cell_still_shows_the_full_name(): void
    {
        $this->assertStringContainsString('x-text="sq(row.item_name)"', $this->html());
    }
}
