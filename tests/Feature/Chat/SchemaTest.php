<?php

namespace Tests\Feature\Chat;

use App\Models\ChatConversation;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The nav link migration, the direct-pair guard and the indexes behind the two hot queries. */
class SchemaTest extends ChatTestCase
{
    private const NAV = '2026_11_27_296300_add_chat_nav_link.php';

    private function navTables(bool $withSection): void
    {
        Schema::create('nav_links', function (Blueprint $t) use ($withSection) {
            $t->id();
            $t->string('key', 80)->unique();
            $t->string('label', 100);
            $t->string('route_url', 255);
            $t->string('icon', 100)->nullable();
            $t->string('active_pattern', 255)->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            if ($withSection) {
                $t->string('section', 40)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('nav_link_role_visibility', function (Blueprint $t) {
            $t->id();
            $t->foreignId('nav_link_id');
            $t->string('role', 50);
            $t->boolean('is_visible')->default(false);
            $t->timestamps();
            $t->unique(['nav_link_id', 'role']);
        });
        $id = DB::table('nav_links')->insertGetId(['key' => 'finance', 'label' => 'Finance', 'route_url' => '/finance', 'sort_order' => 40]);
        DB::table('nav_link_role_visibility')->insert([
            ['nav_link_id' => $id, 'role' => 'CEO', 'is_visible' => true],
            ['nav_link_id' => $id, 'role' => 'HR - OIC', 'is_visible' => false],
        ]);
    }

    public function test_the_chat_nav_link_is_added_for_every_role_and_is_idempotent(): void
    {
        $this->navTables(false);
        $migration = require database_path('migrations/' . self::NAV);
        $migration->up();
        $migration->up();

        $link = DB::table('nav_links')->where('key', 'chat')->first();
        $this->assertSame('/chat', $link->route_url);
        $this->assertSame('Chat', $link->label);
        $this->assertSame(41, (int) $link->sort_order);
        $roles = DB::table('nav_link_role_visibility')->where('nav_link_id', $link->id)->where('is_visible', true)->pluck('role')->sort()->values()->all();
        $this->assertSame(['CEO', 'Data Encoder', 'Data Encoder - OIC', 'HR - OIC', 'Marketing', 'Marketing - OIC'], $roles);
        $this->assertSame(1, DB::table('nav_links')->where('key', 'chat')->count());

        $migration->down();
        $this->assertFalse(DB::table('nav_links')->where('key', 'chat')->exists());
        $this->assertSame(0, DB::table('nav_link_role_visibility')->where('nav_link_id', $link->id)->count());
    }

    public function test_the_chat_nav_link_goes_in_the_chat_section_when_sections_exist(): void
    {
        $this->navTables(true);
        (require database_path('migrations/' . self::NAV))->up();

        $this->assertSame('Chat', DB::table('nav_links')->where('key', 'chat')->value('section'));
    }

    public function test_a_pair_cannot_have_two_direct_chats_even_in_a_race(): void
    {
        ChatConversation::create(['kind' => 'direct', 'direct_key' => '3:7']);
        $this->expectException(QueryException::class);
        ChatConversation::create(['kind' => 'direct', 'direct_key' => '3:7']);
    }

    /** The plans are printed for the result file (spec 296, done-when 3). */
    public function test_the_two_hot_queries_use_their_indexes(): void
    {
        $list = DB::select('EXPLAIN QUERY PLAN ' . 'SELECT c.* FROM chat_members m JOIN chat_conversations c ON c.id = m.conversation_id'
            . ' WHERE m.user_id = ? AND c.deleted_at IS NULL ORDER BY c.last_message_at DESC LIMIT 500', [1]);
        $after = DB::select('EXPLAIN QUERY PLAN ' . 'SELECT * FROM chat_messages WHERE conversation_id = ? AND id > ? ORDER BY id LIMIT 200', [1, 10]);

        $listPlan = implode("\n", array_map(fn ($r) => $r->detail, $list));
        $afterPlan = implode("\n", array_map(fn ($r) => $r->detail, $after));
        fwrite(STDOUT, "\n[296 list] EXPLAIN QUERY PLAN (sqlite):\n$listPlan\n[296 after] EXPLAIN QUERY PLAN (sqlite):\n$afterPlan\n");

        $this->assertStringContainsString('chat_members_user_id_conversation_id_index', $listPlan);
        $this->assertStringContainsString('chat_messages_conversation_id_id_index', $afterPlan);
        $this->assertStringNotContainsString('SCAN chat_messages', $afterPlan);
    }
}
