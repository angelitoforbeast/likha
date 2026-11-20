<?php

namespace Tests\Feature\Nav;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * PM-3 (spec 309): a fresh database (the migrations and the seed, no rows added by hand) has Chat as the first link
 * of Home for every role, as Reset does. The seed adds only a user; the nav comes from the migrations, the 299 one
 * included.
 */
class ChatFreshDatabaseTest extends NavTestCase
{
    private const CHAT_FIRST = 'database/migrations/2026_11_29_299100_move_chat_link_to_top.php';

    /** A fresh database has none of production's hand-made rows. */
    protected function addHandRows(): void
    {
    }

    public function test_PM3_a_fresh_database_has_chat_first_in_home(): void
    {
        Artisan::call('migrate', ['--path' => [self::CHAT_FIRST], '--force' => true]);

        $this->assertSame('Home', DB::table('nav_links')->where('key', 'chat')->value('section'));
        foreach (self::ROLES as $role) {
            $html = $this->actingAs($this->user($role))->get('/nav-probe')->assertOk()->getContent();
            $this->assertSame('/chat', $this->sidebarLinks($html)[0] ?? null, "$role: Chat first");
            $this->assertSame('/chat', $this->sidebarLinks($html, 'Home')[0] ?? null, "$role: Chat first in Home");
        }
    }

    public function test_PM3_reset_on_a_fresh_database_keeps_chat_first_in_home(): void
    {
        Artisan::call('migrate', ['--path' => [self::CHAT_FIRST], '--force' => true]);
        $this->actingAs($this->user('CEO'))->post('/owner/nav-settings/reset')->assertRedirect('/owner/nav-settings');

        $this->assertSame('Home', DB::table('nav_links')->where('key', 'chat')->value('section'));
        foreach (self::ROLES as $role) {
            $html = $this->actingAs($this->user($role))->get('/nav-probe')->assertOk()->getContent();
            $this->assertSame('/chat', $this->sidebarLinks($html)[0] ?? null, "$role: Chat first");
        }
    }
}
