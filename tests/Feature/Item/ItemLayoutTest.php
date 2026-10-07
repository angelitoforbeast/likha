<?php

namespace Tests\Feature\Item;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GET /item — ?layout=old ang tanging paraan para sa lumang view: eksaktong 'old' lang.
 */
class ItemLayoutTest extends ItemTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Kailangan ng layout (task badge) at ng index() (page list + fee rates).
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

    public function test_only_the_exact_string_old_selects_the_old_layout(): void
    {
        $this->actingAs($this->user());

        foreach (['/item' => false, '/item?layout=old' => true, '/item?layout=OLD' => false, '/item?layout=x' => false] as $url => $old) {
            $this->assertSame($old, $this->get($url)->assertOk()->viewData('layoutOld'), $url);
        }
    }
}
