<?php

namespace Tests\Feature\Chatblast;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laging malinaw kung Chatblast o Chatbot (spec 085): ang switcher sa top bar, walang cross link sa tabs, ang title na
 * nagsisimula sa app, at ang hiwalay na "Chatbot" link sa main nav ng Likha (kapag may access sa /chatblast lang).
 */
class ChatblastChatbotNavTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
        ]]);
    }

    public static function apps(): array
    {
        return [
            'chatblast to send' => ['/chatblast', 'chatblast', 'Chatblast · To send'],
            'chatblast page settings' => ['/chatblast/page-settings', 'chatblast', 'Chatblast · Page settings'],
            'chatbot flow' => ['/chatbot', 'chatbot', 'Chatbot · Flow'],
            'chatbot page settings' => ['/chatbot/pages', 'chatbot', 'Chatbot · Page settings'],
            'chatbot chats (shared view)' => ['/chatbot/chats', 'chatbot', 'Chatbot · Chats'],
        ];
    }

    /** @dataProvider apps */
    public function test_S_085_1_the_switcher_shows_the_current_app_the_title_starts_with_it_and_there_is_no_cross_link(string $url, string $app, string $title): void
    {
        $this->actingAs($this->user());
        $html = $this->get($url)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#data-app-switch[^>]*data-current="' . $app . '"#', $html);
        $this->assertMatchesRegularExpression('#<a[^>]*data-app="' . $app . '"[^>]*aria-current="page"#', $html);
        $other = $app === 'chatbot' ? 'chatblast' : 'chatbot';
        $this->assertMatchesRegularExpression('#<a[^>]*href="[^"]*/' . $other . '"[^>]*data-app="' . $other . '"#', $html, 'the other app is a plain link to its home');
        $this->assertStringContainsString('<title>' . $title . '</title>', $html);
        preg_match_all('/data-cb-nav="([^"]+)"/', $html, $tabs);
        $this->assertNotContains($app === 'chatbot' ? 'Chatblast' : 'Chatbot', $tabs[1], 'no cross link in the tabs');
        // Each app's colour: violet for Chatblast, emerald for Chatbot.
        $this->assertStringContainsString($app === 'chatbot' ? 'from-emerald-500' : 'from-violet-600', $html);
    }

    private function mainNav(string $role): array
    {
        $this->actingAs($this->user($role, uniqid('u') . '@example.test'));
        $html = (string) $this->blade('<x-layout>body</x-layout>');
        preg_match_all('#<a\s[^>]*href="([^"]*(?:chatblast|chatbot|page-settings)[^"]*)"#', $html, $m);

        return array_values(array_unique(array_map(fn ($u) => parse_url($u, PHP_URL_PATH), $m[1])));
    }

    public function test_S_085_2_the_main_nav_has_a_chatbot_link_only_where_chatblast_is_open(): void
    {
        if (!Schema::hasTable('nav_links')) Artisan::call('migrate', ['--path' => 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php', '--force' => true]);
        $chatblast = DB::table('nav_links')->insertGetId(['key' => 'chatblast', 'label' => 'Chatblast', 'route_url' => '/chatblast', 'icon' => 'fa-solid fa-paper-plane',
            'active_pattern' => 'chatblast*', 'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['Marketing - OIC' => true, 'Marketing' => false] as $role => $visible) {
            DB::table('nav_link_role_visibility')->insert(['nav_link_id' => $chatblast, 'role' => $role, 'is_visible' => $visible, 'created_at' => now(), 'updated_at' => now()]);
        }

        Artisan::call('migrate', ['--path' => 'database/migrations/2026_10_27_100000_add_chatbot_nav_link.php', '--force' => true]);
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_10_27_100000_add_chatbot_nav_link.php', '--force' => true]); // idempotent

        $link = DB::table('nav_links')->where('key', 'chatbot')->first();
        $this->assertSame(['/chatbot', 'chatbot*', 'Chatbot'], [$link->route_url, $link->active_pattern, $link->label]);
        $this->assertSame(1, DB::table('nav_links')->where('key', 'chatbot')->count());
        $this->assertSame(['/chatblast', '/chatbot'], $this->mainNav('Marketing - OIC'));
        $this->assertSame([], $this->mainNav('Marketing'));

        // Shown only where /chatblast is open: a role that was given the Chatbot link alone doesn't see it.
        DB::table('nav_link_role_visibility')->insert(['nav_link_id' => $link->id, 'role' => 'Data Encoder', 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([], $this->mainNav('Data Encoder'));
    }
}
