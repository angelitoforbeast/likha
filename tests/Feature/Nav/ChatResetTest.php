<?php

namespace Tests\Feature\Nav;

use App\Models\NavLink;
use Illuminate\Support\Facades\DB;

/**
 * PM-3 (spec 309): the factory order matches what the 299 migration did. After Reset in /owner/nav-settings, Chat is
 * the first link of Home (above Checklist) for every role that sees it, and every other link has the section, order
 * and visibility Reset gave it before this spec. `fixtures/reset-before-309.json` is what Reset gave on the 308 tip
 * (b4c9d8d5) in this test database: each role's sidebar, the order of the links, and each link's fields and roles.
 */
class ChatResetTest extends NavTestCase
{
    private const BEFORE = __DIR__ . '/fixtures/reset-before-309.json';

    /** What Reset leaves: each role's sidebar (section => urls), the links in sidebar order, and each link's fields. */
    protected function resetState(): array
    {
        $this->actingAs($this->user('CEO'))->post('/owner/nav-settings/reset')->assertRedirect('/owner/nav-settings');

        $sidebar = [];
        foreach (self::ROLES as $role) {
            $html = $this->actingAs($this->user($role))->get('/nav-probe')->assertOk()->getContent();
            foreach ($this->sidebarSections($html) as $section) {
                $sidebar[$role][$section] = $this->sidebarLinks($html, $section);
            }
        }
        $links = [];
        foreach (DB::table('nav_links')->orderBy('sort_order')->orderBy('id')->get() as $row) {
            $links[$row->key] = [
                'label' => $row->label, 'route_url' => $row->route_url, 'icon' => $row->icon,
                'active_pattern' => $row->active_pattern, 'section' => $row->section, 'is_active' => (bool) $row->is_active,
                'visible' => DB::table('nav_link_role_visibility')->where('nav_link_id', $row->id)->where('is_visible', true)
                    ->orderBy('role')->pluck('role')->all(),
            ];
        }

        // The order that shows: the links of each section in sidebar order (sort_order, then id), whoever sees them.
        $order = [];
        foreach ($links as $key => $link) $order[$link['section']][] = $key;
        ksort($order);
        ksort($links);

        return ['sidebar' => $sidebar, 'order' => $order, 'links' => $links];
    }

    /** The state without the Chat link. */
    private function withoutChat(array $state): array
    {
        foreach ($state['sidebar'] as $role => $sections) {
            foreach ($sections as $section => $urls) {
                $urls = array_values(array_diff($urls, ['/chat']));
                if ($urls) $state['sidebar'][$role][$section] = $urls; else unset($state['sidebar'][$role][$section]);
            }
        }
        foreach ($state['order'] as $section => $keys) {
            $keys = array_values(array_diff($keys, ['chat']));
            if ($keys) $state['order'][$section] = $keys; else unset($state['order'][$section]);
        }
        unset($state['links']['chat']);

        return $state;
    }

    public function test_PM3_after_reset_chat_is_first_in_home_for_every_role_that_sees_it(): void
    {
        $state = $this->resetState();

        foreach (self::ROLES as $role) {
            $this->assertSame('/chat', array_merge(...array_values($state['sidebar'][$role]))[0] ?? null, "$role: Chat first");
            $this->assertSame(['/chat', '/checklist'], array_slice($state['sidebar'][$role]['Home'] ?? [], 0, 2), "$role: Home is Chat, then Checklist");
            $this->assertArrayNotHasKey('Chat', array_filter($state['sidebar'][$role], fn ($urls) => in_array('/chat', $urls, true)));
        }
        $this->assertSame('Home', $state['links']['chat']['section']);
        $this->assertSame('Home', collect(NavLink::defaultData())->firstWhere('key', 'chat')['section']);
    }

    public function test_PM3_reset_leaves_every_other_link_as_reset_did_before(): void
    {
        $before = json_decode(file_get_contents(self::BEFORE), true);
        unset($before['defaults']);
        $after = $this->resetState();

        // The base put Chat in the Chat section; everything else must match, link by link and role by role.
        $this->assertSame('Chat', $before['links']['chat']['section']);
        $this->assertSame($this->withoutChat($before), $this->withoutChat($after));
        // Chat itself: only its section and place changed.
        $chatBefore = $before['links']['chat'];
        $chatAfter = $after['links']['chat'];
        unset($chatBefore['section'], $chatAfter['section']);
        $this->assertSame($chatBefore, $chatAfter);
    }

    public function test_PM3_default_data_moves_only_the_chat_row(): void
    {
        $before = json_decode(file_get_contents(self::BEFORE), true)['defaults'];
        $now = NavLink::defaultData();

        $this->assertSame('chat', $now[0]['key'], 'Chat is the first row of the catalogue');
        $strip = fn (array $rows) => array_values(array_filter($rows, fn ($d) => $d['key'] !== 'chat'));
        $this->assertSame($strip($before), $strip($now));
        $chatBefore = collect($before)->firstWhere('key', 'chat');
        $chatNow = $now[0];
        $this->assertSame(['Chat', 'Home'], [$chatBefore['section'], $chatNow['section']]);
        unset($chatBefore['section'], $chatNow['section']);
        $this->assertSame($chatBefore, $chatNow);
    }
}
