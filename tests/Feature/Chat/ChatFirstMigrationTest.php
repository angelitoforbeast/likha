<?php

namespace Tests\Feature\Chat;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Nav\NavTestCase;

/**
 * MC-1 (spec 299): Chat ang unang link ng sidebar, sa Home, sa itaas ng Checklist, para sa bawat role na nakakakita
 * nito. Ang migration ay ang Chat link lang ang ginagalaw; ang rollback ay ibinabalik ito kung saan ito dati.
 * Ang nav ay ang gaya ng production (NavTestCase: lahat ng nav migration at ang mga row na kamay ang gumawa).
 */
class ChatFirstMigrationTest extends NavTestCase
{
    private const MIGRATION = 'database/migrations/2026_11_29_299100_move_chat_link_to_top.php';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('app_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->timestamps();
        });
    }

    private function snapshot(): array
    {
        return DB::table('nav_links')->orderBy('id')->get(['id', 'key', 'section', 'sort_order', 'is_active', 'active_pattern', 'route_url'])
            ->map(fn ($r) => (array) $r)->keyBy('id')->all();
    }

    private function visibility(): array
    {
        return DB::table('nav_link_role_visibility')->orderBy('id')->get(['nav_link_id', 'role', 'is_visible'])->map(fn ($r) => (array) $r)->all();
    }

    public function test_only_the_chat_link_moves_to_the_top_of_home(): void
    {
        $before = $this->snapshot();
        $visibility = $this->visibility();
        $chatId = (int) DB::table('nav_links')->where('key', 'chat')->value('id');
        $this->assertSame('Chat', $before[$chatId]['section']);

        (require base_path(self::MIGRATION))->up();

        $after = $this->snapshot();
        $this->assertSame('Home', $after[$chatId]['section']);
        $homeOthers = collect($after)->where('section', 'Home')->where('id', '!=', $chatId)->pluck('sort_order');
        $this->assertNotEmpty($homeOthers);
        $this->assertLessThan($homeOthers->min(), $after[$chatId]['sort_order']);
        unset($before[$chatId], $after[$chatId]);
        $this->assertSame($before, $after, 'no other link changes section or order');
        $this->assertSame($visibility, $this->visibility());
    }

    public function test_chat_is_the_first_sidebar_link_for_every_role_that_sees_it(): void
    {
        (require base_path(self::MIGRATION))->up();

        foreach (self::ROLES as $role) {
            $html = $this->actingAs($this->user($role))->get('/nav-probe')->assertOk()->getContent();
            $links = $this->sidebarLinks($html);
            $this->assertSame('/chat', $links[0] ?? null, "$role: Chat first");
            $this->assertSame(['/chat', '/checklist'], array_slice($this->sidebarLinks($html, 'Home'), 0, 2), "$role: Home is Chat, then Checklist");
            $this->assertNotContains('/chat', $this->sidebarLinks($html, 'Chat'));
        }
    }

    public function test_running_it_twice_changes_nothing_more(): void
    {
        (require base_path(self::MIGRATION))->up();
        $once = $this->snapshot();
        (require base_path(self::MIGRATION))->up();

        $this->assertSame($once, $this->snapshot());
    }

    public function test_rollback_puts_it_back_where_it_was(): void
    {
        $before = $this->snapshot();

        (require base_path(self::MIGRATION))->up();
        (require base_path(self::MIGRATION))->down();

        $this->assertSame($before, $this->snapshot());
        $this->assertNull(DB::table('app_settings')->where('key', 'nav.299_chat_before')->value('value'));
    }

    public function test_rollback_puts_back_a_place_the_ceo_had_chosen(): void
    {
        $chatId = (int) DB::table('nav_links')->where('key', 'chat')->value('id');
        DB::table('nav_links')->where('id', $chatId)->update(['section' => 'More', 'sort_order' => 7]);
        $before = $this->snapshot();

        (require base_path(self::MIGRATION))->up();
        $this->assertSame('Home', DB::table('nav_links')->where('id', $chatId)->value('section'));
        (require base_path(self::MIGRATION))->down();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_without_a_chat_link_nothing_changes(): void
    {
        DB::table('nav_link_role_visibility')->where('nav_link_id', DB::table('nav_links')->where('key', 'chat')->value('id'))->delete();
        DB::table('nav_links')->where('key', 'chat')->delete();
        $before = $this->snapshot();

        (require base_path(self::MIGRATION))->up();
        (require base_path(self::MIGRATION))->down();

        $this->assertSame($before, $this->snapshot());
    }
}
