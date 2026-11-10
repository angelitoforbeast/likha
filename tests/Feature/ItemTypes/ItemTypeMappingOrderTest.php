<?php

namespace Tests\Feature\ItemTypes;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\OwnerPrivate\OwnerPrivateTestCase;

/**
 * Spec 260: ang "Existing Mappings" sa /jnt/item-types ay pinakabago muna (created_at desc, id desc),
 * hindi A to Z, para makita agad ng staff ang kaka-upload lang.
 */
class ItemTypeMappingOrderTest extends OwnerPrivateTestCase
{
    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), [
            'database/migrations/2026_04_13_000001_create_item_type_mappings_table.php',
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
    }

    private function mapping(string $type, string $name, string $createdAt): void
    {
        DB::table('item_type_mappings')->insert([
            'item_type'  => $type,
            'item_name'  => $name,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /** Ang item_name ng mga row sa listahan, sa pagkakasunod na ipinakita. */
    private function listedNames(string $url): array
    {
        $this->actingAs($this->user());

        $response = $this->get($url)->assertOk();

        return collect($response->viewData('mappings')->items())->pluck('item_name')->all();
    }

    public function test_the_newest_mapping_is_listed_first_regardless_of_name(): void
    {
        $this->mapping('aa type', 'aa item', '2026-11-01 08:00:00');
        $this->mapping('mm type', 'mm item', '2026-11-05 08:00:00');
        $this->mapping('zz type', 'zz item', '2026-11-09 08:00:00');

        $this->assertSame(['zz item', 'mm item', 'aa item'], $this->listedNames('/jnt/item-types'));
    }

    public function test_same_created_at_falls_back_to_the_higher_id_first(): void
    {
        $this->mapping('aa type', 'aa first', '2026-11-09 08:00:00');
        $this->mapping('zz type', 'zz second', '2026-11-09 08:00:00');

        $this->assertSame(['zz second', 'aa first'], $this->listedNames('/jnt/item-types'));
    }

    public function test_a_search_keeps_the_newest_first_order(): void
    {
        $this->mapping('flashlight', 'aa torch', '2026-11-01 08:00:00');
        $this->mapping('fan', 'mm fan', '2026-11-03 08:00:00');
        $this->mapping('flashlight', 'zz torch', '2026-11-09 08:00:00');
        $this->mapping('flashlight', 'mm torch', '2026-11-05 08:00:00');

        $this->assertSame(
            ['zz torch', 'mm torch', 'aa torch'],
            $this->listedNames('/jnt/item-types?search=torch')
        );
    }
}
