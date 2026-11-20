<?php

namespace Tests\Feature\Nav;

use App\Models\NavLink;
use App\Support\AccessRule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * The Chat link (/chat, spec 296) after 295's follow-up: it covers /chat and /chat/* only, never /chatblast or
 * /chatbot, in the sidebar and in AccessRule (spec 294); and /chat is one of the layout's full-width pages.
 */
class ChatLinkTest extends NavTestCase
{
    private const MIGRATION = 'database/migrations/2026_11_28_100000_narrow_chat_nav_link_pattern.php';

    protected function setUp(): void
    {
        parent::setUp();

        // Probes on the real paths (same uri as the real routes, so they replace them for this test).
        $probe = fn () => Blade::render('<x-layout><x-slot name="heading">Probe</x-slot><p>probe body</p></x-layout>');
        Route::middleware(['web', 'auth'])->get('/chat', $probe);
        Route::middleware(['web', 'auth'])->get('/chat/{conversation}', $probe);
        Route::middleware(['web', 'auth'])->get('/chatblast', $probe);
        Route::middleware(['web', 'auth'])->get('/chatbot', $probe);
    }

    private function currentLinks(string $html): array
    {
        preg_match_all('/data-nav-link="([^"]+)"[^>]*aria-current="page"/', $html, $m);

        return $m[1];
    }

    public function test_the_chat_link_has_no_pattern_after_the_migrations(): void
    {
        $this->assertSame(1, DB::table('nav_links')->where('route_url', '/chat')->count());
        $this->assertNull(DB::table('nav_links')->where('key', 'chat')->value('active_pattern'));
    }

    public function test_the_migration_clears_a_broad_chat_pattern_and_touches_no_other_link(): void
    {
        DB::table('nav_links')->where('key', 'chat')->update(['active_pattern' => 'chat*']);
        $others = DB::table('nav_links')->where('key', '!=', 'chat')->orderBy('id')->pluck('active_pattern', 'id')->all();

        (require base_path(self::MIGRATION))->up();

        $this->assertNull(DB::table('nav_links')->where('key', 'chat')->value('active_pattern'));
        $this->assertSame($others, DB::table('nav_links')->where('key', '!=', 'chat')->orderBy('id')->pluck('active_pattern', 'id')->all());

        (require base_path(self::MIGRATION))->down();
        $this->assertSame('chat', DB::table('nav_links')->where('key', 'chat')->value('active_pattern'));
    }

    public function test_chatblast_and_chatbot_do_not_light_chat(): void
    {
        $ceo = $this->user('CEO');

        $html = $this->actingAs($ceo)->get('/chatblast')->assertOk()->getContent();
        $this->assertSame(['/chatblast'], $this->currentLinks($html));

        $html = $this->actingAs($ceo)->get('/chatbot')->assertOk()->getContent();
        $this->assertSame(['/chatbot'], $this->currentLinks($html));

        // Marketing sees Chat but not Chatblast or Chatbot: no link of theirs is the current page there.
        $marketing = $this->user('Marketing');
        $this->assertContains('/chat', $this->sidebarLinks($this->actingAs($marketing)->get('/nav-probe')->getContent()));
        foreach (['/chatblast', '/chatbot'] as $path) {
            $this->assertSame([], $this->currentLinks($this->actingAs($marketing)->get($path)->assertOk()->getContent()), $path);
        }
    }

    public function test_chat_and_a_conversation_light_chat(): void
    {
        $user = $this->user('Data Encoder');

        $this->assertSame(['/chat'], $this->currentLinks($this->actingAs($user)->get('/chat')->assertOk()->getContent()));
        $this->assertSame(['/chat'], $this->currentLinks($this->actingAs($user)->get('/chat/5')->assertOk()->getContent()));
    }

    public function test_access_rule_maps_chat_paths_to_the_chat_link_only(): void
    {
        $rule = app(AccessRule::class);

        $this->assertSame(AccessRule::ALLOW, $rule->decide('Marketing', '/chat'));
        $this->assertSame(AccessRule::ALLOW, $rule->decide('Marketing', '/chat/5'));
        // A path that only starts with "chat" is not the Chat link's (`chat*` used to cover it).
        $this->assertSame(AccessRule::UNMAPPED, $rule->decide('Marketing', '/chatroom'));
        // Chatblast stays CEO only.
        $this->assertSame(AccessRule::WOULD_BLOCK, $rule->decide('Marketing', '/chatblast'));
    }

    public function test_reset_keeps_the_chat_link_without_a_pattern(): void
    {
        $chat = collect(NavLink::defaultData())->firstWhere('key', 'chat');
        $this->assertNull($chat['active_pattern']);
        $this->assertSame('Home', $chat['section']); // spec 309: first in Home, where the 299 migration put it

        $this->actingAs($this->user('CEO'))->post('/owner/nav-settings/reset')->assertRedirect('/owner/nav-settings');
        $this->assertSame(1, DB::table('nav_links')->where('route_url', '/chat')->count());
        $this->assertNull(DB::table('nav_links')->where('key', 'chat')->value('active_pattern'));
    }

    public function test_chat_is_a_full_width_page(): void
    {
        $user = $this->user('Marketing');

        foreach (['/chat', '/chat/5'] as $path) {
            $html = $this->actingAs($user)->get($path)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<div class="w-full px-0">\s*<p>probe body<\/p>/', $html, $path);
            $this->assertStringNotContainsString('mx-auto max-w-7xl px-4 py-6', $html, $path);
        }
        // A page off the list keeps the padded container.
        $html = $this->actingAs($user)->get('/nav-probe')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">\s*<p>probe body<\/p>/', $html);
    }
}
