<?php

namespace Tests\Feature\Chat;

/** WP-1, WP-3, WP-4 in the browser files: the Alerts box, the service worker, the manifest and the app badge. */
class PushPageTest extends PushTestCase
{
    public function test_the_chat_page_offers_turn_on_alerts_with_the_public_key_and_never_asks_on_load(): void
    {
        $html = $this->actingAs($this->person())->get('/chat')->assertOk()->getContent();

        $this->assertStringContainsString('Turn on alerts', $html);
        $this->assertStringContainsString('Alerts on for this device', $html);
        $this->assertStringContainsString('Turn off', $html);
        $this->assertStringContainsString('"push_key":"' . config('chat.push.public_key') . '"', $html);
        // The iPhone line, for Safari outside the installed app.
        $this->assertStringContainsString('To get alerts on iPhone: Share', $html);
        $this->assertStringContainsString('Add to Home Screen, then open Likha from the Home Screen', $html);
        // Permission is asked only inside the click handler, never at the top level of the script.
        $this->assertStringContainsString("register('/sw.js', { scope: '/' })", $html);
        $this->assertDoesNotMatchRegularExpression('/^\s*(await\s+)?Notification\.requestPermission\(/m', $html);
        // The private key is never on the page.
        $this->assertStringNotContainsString(config('chat.push.private_key'), $html);
    }

    public function test_the_service_worker_is_at_the_root_with_no_fetch_handler_and_no_secret(): void
    {
        $sw = file_get_contents(public_path('sw.js'));

        $this->assertStringNotContainsString("'fetch'", $sw);
        $this->assertStringNotContainsString('"fetch"', $sw);
        $this->assertStringNotContainsString('caches.', $sw);
        $this->assertStringNotContainsStringIgnoringCase('private', $sw);
        $this->assertStringNotContainsString('VAPID', $sw);
        $this->assertStringContainsString("addEventListener('push'", $sw);
        $this->assertStringContainsString("addEventListener('notificationclick'", $sw);
        // Nothing while a Likha tab is visible (300's in-page alert does it).
        $this->assertStringContainsString("visibilityState === 'visible'", $sw);
        // One notification per conversation, replaced with the count.
        $this->assertStringContainsString('getNotifications({ tag', $sw);
        $this->assertStringContainsString('new messages', $sw);
        // The app icon badge where supported.
        $this->assertStringContainsString('setAppBadge', $sw);
    }

    public function test_the_manifest_installs_likha_at_chat(): void
    {
        $m = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Likha', $m['name']);
        $this->assertSame('Likha', $m['short_name']);
        $this->assertSame('/chat', $m['start_url']);
        $this->assertSame('/', $m['scope']);
        $this->assertSame('standalone', $m['display']);
        $this->assertSame('#2563eb', $m['theme_color']);
        $sizes = array_column($m['icons'], 'sizes');
        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);
        foreach ($m['icons'] as $icon) {
            $file = public_path(ltrim($icon['src'], '/'));
            $this->assertFileExists($file);
            [$w, $h, $type] = getimagesize($file);
            $this->assertSame($icon['sizes'], "{$w}x{$h}");
            $this->assertSame(IMAGETYPE_PNG, $type);
        }
        $this->assertFileExists(public_path('icons/apple-touch-icon.png'));
        $this->assertSame([180, 180], array_slice(getimagesize(public_path('icons/apple-touch-icon.png')), 0, 2));
    }

    public function test_the_layout_links_the_manifest_and_the_app_icon(): void
    {
        $html = $this->actingAs($this->person())->get('/chat')->getContent();
        $head = substr($html, 0, strpos($html, '</head>'));

        $this->assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest">', $head);
        $this->assertStringContainsString('<meta name="theme-color" content="#2563eb">', $head);
        $this->assertStringContainsString('<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">', $head);
    }

    public function test_the_every_page_script_sets_the_app_badge_where_supported(): void
    {
        $html = $this->actingAs($this->person())->get('/chat')->getContent();
        $this->assertStringContainsString("'setAppBadge' in navigator", $html);
        $this->assertStringContainsString('clearAppBadge', $html);
    }
}
