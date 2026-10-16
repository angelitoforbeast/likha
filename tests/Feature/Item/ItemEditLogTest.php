<?php

namespace Tests\Feature\Item;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * item_edit_logs: bawat edit sa /item ay isang row kada field na nagbago (luma → bago),
 * walang row kapag walang nagbago; at ang /item/edit-logs page para sa CEO at Marketing - OIC.
 */
class ItemEditLogTest extends ItemTestCase
{
    private int $acme;

    protected function setUp(): void
    {
        parent::setUp();
        // Kailangan ng layout ng logs page (task badge).
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        $this->acme = DB::table('suppliers')->insertGetId(['name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** [action, item_key, field, old, new] ng mga row mula sa id na $after. */
    private function logs(int $after = 0): array
    {
        return DB::table('item_edit_logs')->where('id', '>', $after)->orderBy('id')->get()
            ->map(fn ($r) => [$r->action, $r->item_key, $r->field, $r->old_value, $r->new_value])->all();
    }

    private function lastId(): int
    {
        return (int) DB::table('item_edit_logs')->max('id');
    }

    public function test_S_37B_1_quote_save_and_delete_log_each_changed_field_and_nothing_when_unchanged(): void
    {
        $oic = $this->user('Marketing - OIC', 'oic@example.test');
        $this->actingAs($oic);
        $save = fn (array $f) => $this->postJson('/item/quotes', array_merge(['item_name' => '1 x HAND GRIP', 'supplier_id' => $this->acme], $f))->assertOk();

        $save(['price' => 100, 'moq' => 10]);
        $this->assertSame([
            ['quote.save', 'hand grip', 'price · Acme', null, '100.00'],
            ['quote.save', 'hand grip', 'moq · Acme', null, '10'],
        ], $this->logs());
        $row = DB::table('item_edit_logs')->first();
        $this->assertSame($oic->id, (int) $row->user_id);
        $this->assertSame('Marketing - OIC', $row->role);
        $this->assertSame('1 x HAND GRIP', $row->item_name);

        $id = $this->lastId();
        $save(['price' => '100.00', 'moq' => 10]);
        $this->assertSame([], $this->logs($id), 'walang nagbago = walang row');

        $save(['price' => 120, 'moq' => 10, 'note' => 'bago']);
        $this->assertSame([
            ['quote.save', 'hand grip', 'price · Acme', '100.00', '120.00'],
            ['quote.save', 'hand grip', 'note · Acme', null, 'bago'],
        ], $this->logs($id));

        $id = $this->lastId();
        $quoteId = (int) DB::table('item_supplier_quotes')->value('id');
        $this->postJson('/item/quotes/delete', ['id' => $quoteId, 'item_name' => '1 x HAND GRIP'])->assertOk();
        $this->assertSame([
            ['quote.delete', 'hand grip', 'price · Acme', '120.00', null],
            ['quote.delete', 'hand grip', 'moq · Acme', '10', null],
            ['quote.delete', 'hand grip', 'note · Acme', 'bago', null],
        ], $this->logs($id));
        // Ang lumang history ng quotes ay tuloy pa rin.
        $this->assertSame(2, DB::table('item_supplier_quote_history')->count());
    }

    public function test_S_37B_2_category_and_supply_settings_log_old_and_new(): void
    {
        $this->actingAs($this->user());
        $this->postJson('/item/category', ['item_name' => '2 x GLOW TAPE', 'new_category' => 'Gym'])->assertOk();
        $this->postJson('/item/category', ['item_name' => '2 x GLOW TAPE', 'new_category' => 'Gym'])->assertOk();
        $gym = (int) DB::table('item_categories')->where('name', 'Gym')->value('id');
        $this->postJson('/item/category', ['item_name' => 'GLOW TAPE'])->assertOk();

        $this->postJson('/item/supply-settings', ['item_name' => 'GLOW TAPE', 'lead_time_days' => 7, 'safety_days' => 3])->assertOk();
        $this->postJson('/item/supply-settings', ['item_name' => 'GLOW TAPE', 'lead_time_days' => 7, 'safety_days' => 3])->assertOk();
        $this->postJson('/item/supply-settings', ['item_name' => '1 x GLOW TAPE', 'lead_time_days' => 9])->assertOk();

        $this->assertSame([
            ['category.set', 'glow tape', 'category', null, 'Gym'],
            ['category.set', 'glow tape', 'category', 'Gym', null],
            ['supply_settings.save', 'glow tape', 'lead_time_days', null, '7'],
            ['supply_settings.save', 'glow tape', 'palugit', null, '3'],
            ['supply_settings.save', 'glow tape', 'lead_time_days', '7', '9'],
            ['supply_settings.save', 'glow tape', 'palugit', '3', null],
        ], $this->logs());
        $this->assertGreaterThan(0, $gym);
    }

    public function test_S_37B_3_item_photo_upload_replace_and_delete_log_file_names_only(): void
    {
        $this->actingAs($this->user());
        $this->post('/item/image', ['item_name' => '1 x LAMP', 'image' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json'])->assertOk();
        $first = basename((string) DB::table('item_images')->value('image_path'));
        $this->post('/item/photo', ['item_name' => '1 x LAMP', 'image' => UploadedFile::fake()->image('b.png')])->assertRedirect();
        $second = basename((string) DB::table('item_images')->value('image_path'));
        $this->postJson('/item/image/delete', ['item_name' => '1 x LAMP'])->assertOk();
        $this->postJson('/item/image/delete', ['item_name' => '1 x LAMP'])->assertOk();   // wala nang photo = walang row

        $this->assertSame([
            ['image.upload', 'lamp', 'photo', null, $first],
            ['photo.save', 'lamp', 'photo', $first, $second],
            ['image.delete', 'lamp', 'photo', $second, null],
        ], $this->logs());
        $this->assertSame('1 x LAMP', DB::table('item_edit_logs')->value('item_name'));
    }

    public function test_S_37B_4_a_failing_log_write_never_breaks_the_edit(): void
    {
        Schema::drop('item_edit_logs');
        $this->actingAs($this->user());
        $this->postJson('/item/quotes', ['item_name' => 'BELT', 'supplier_id' => $this->acme, 'price' => 50])->assertOk();
        $this->assertSame(1, DB::table('item_supplier_quotes')->count());
    }

    public function test_S_37B_5_the_logs_page_is_for_ceo_and_marketing_oic_newest_first_with_filters(): void
    {
        $ceo = $this->user();
        $oic = $this->user('Marketing - OIC', 'oic@example.test');
        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00'));
        $this->actingAs($ceo)->postJson('/item/quotes', ['item_name' => 'BELT', 'supplier_id' => $this->acme, 'price' => 50])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
        $this->actingAs($oic)->postJson('/item/category', ['item_name' => 'LAMP', 'new_category' => 'Ilaw'])->assertOk();

        foreach ([[$ceo, 200], [$oic, 200], [$this->user('Marketing', 'mkt@example.test'), 404], [$this->user('Encoder', 'enc@example.test'), 404]] as [$u, $status]) {
            $this->actingAs($u)->get('/item/edit-logs')->assertStatus($status);
        }

        $this->actingAs($oic);
        $all = $this->get('/item/edit-logs')->assertOk();
        $this->assertSame(['category.set', 'quote.save'], collect($all->viewData('rows')->items())->pluck('action')->all(), 'newest first');
        $this->assertSame(50, $all->viewData('rows')->perPage());
        $all->assertSeeInOrder(['Marketing - OIC User', 'category.set', 'LAMP', 'category', 'Ilaw', 'CEO User', 'quote.save', 'BELT', 'price · Acme', '50.00']);
        $all->assertSee(route('owner.private.edit-logs'), false);

        $cases = [
            '?item=bel'                                    => ['quote.save'],
            '?user=' . $oic->id                            => ['category.set'],
            '?action=quote.save'                           => ['quote.save'],
            '?from_date=2026-10-02'                        => ['category.set'],
            '?to_date=2026-10-01'                          => ['quote.save'],
            '?from_date=2026-10-02&to_date=2026-10-03'     => [],
        ];
        foreach ($cases as $q => $expected) {
            $rows = $this->get('/item/edit-logs' . $q)->assertOk()->viewData('rows');
            $this->assertSame($expected, collect($rows->items())->pluck('action')->all(), $q);
        }
    }

    public function test_S_37B_6_the_item_page_links_to_the_logs_page_for_supplier_work_only(): void
    {
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
        $link = 'href="' . route('item.edit-logs') . '"';
        $this->actingAs($this->user('Marketing - OIC', 'oic@example.test'))->get('/item')->assertOk()->assertSee($link, false);
        $this->actingAs($this->user('Marketing', 'mkt@example.test'))->get('/item')->assertOk()->assertDontSee($link, false);
    }
}
