<?php

namespace Tests\Feature\Chat;

use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;

/** WP-1, no SSRF: only https endpoints on the known push services are saved; everything else is 422. */
class PushEndpointTest extends PushTestCase
{
    public static function refused(): array
    {
        return [
            'http' => ['http://fcm.googleapis.com/fcm/send/abc'],
            'unknown host' => ['https://evil.example.com/fcm/send/abc'],
            'look-alike host' => ['https://fcm.googleapis.com.evil.example/fcm/send/abc'],
            'suffix without a dot' => ['https://evilpush.services.mozilla.com.example/x'],
            'ipv4' => ['https://127.0.0.1/fcm/send/abc'],
            'private ipv4' => ['https://10.0.0.5/x'],
            'ipv6' => ['https://[::1]/x'],
            'localhost' => ['https://localhost/x'],
            'other port' => ['https://fcm.googleapis.com:8443/fcm/send/abc'],
            'user info' => ['https://user:pass@fcm.googleapis.com/fcm/send/abc'],
            'no scheme' => ['fcm.googleapis.com/fcm/send/abc'],
            'file' => ['file:///etc/passwd'],
            'javascript' => ['javascript:alert(1)'],
            'too long' => ['https://fcm.googleapis.com/fcm/send/' . str_repeat('a', 2100)],
            'not a string' => [['https://fcm.googleapis.com/x']],
        ];
    }

    #[DataProvider('refused')]
    public function test_an_endpoint_off_the_allowlist_is_422(mixed $endpoint): void
    {
        $ana = $this->person();
        $device = $this->device();
        $device['endpoint'] = $endpoint;
        $this->actingAs($ana)->postJson('/chat/push', $device)->assertStatus(422)->assertJsonValidationErrors('endpoint');
        $this->assertNull(User::find($ana->id)->chat_push);
    }

    public static function accepted(): array
    {
        return [
            'chrome, android, edge on fcm' => ['fcm.googleapis.com', 'Google'],
            'firefox' => ['updates.push.services.mozilla.com', 'Mozilla'],
            'safari and iphone' => ['web.push.apple.com', 'Apple'],
            'windows (wns)' => ['wns2-bl2p.notify.windows.com', 'Microsoft'],
        ];
    }

    #[DataProvider('accepted')]
    public function test_each_known_push_service_is_accepted(string $host, string $service): void
    {
        $ana = $this->person();
        $res = $this->actingAs($ana)->postJson('/chat/push', $this->device('x', $host, '/abc/'))->assertCreated();
        $this->assertSame($service, $res->json('device.service'));
    }

    public function test_bad_keys_are_422(): void
    {
        $ana = $this->person();
        foreach ([
            ['p256dh' => 'short', 'auth' => $this->device()['keys']['auth']],
            ['p256dh' => $this->device()['keys']['p256dh'], 'auth' => 'not+base64url/'],
            ['p256dh' => $this->device()['keys']['p256dh'], 'auth' => str_repeat('A', 200)],
            ['p256dh' => $this->device()['keys']['p256dh']],
            [],
        ] as $keys) {
            $device = $this->device();
            $device['keys'] = $keys;
            $this->actingAs($ana)->postJson('/chat/push', $device)->assertStatus(422);
        }
        $this->assertNull(User::find($ana->id)->chat_push);
    }
}
