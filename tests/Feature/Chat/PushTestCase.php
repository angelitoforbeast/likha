<?php

namespace Tests\Feature\Chat;

use App\Models\User;
use App\Services\Chat\PushSender;

/**
 * Base ng mga web push test (spec 308): naka-on ang push (may keys sa config) at peke ang sender, kaya walang totoong
 * tawag sa push service. Ang $this->sender ang nagtatala ng bawat padala at sumasagot ng ok / gone / failed.
 */
abstract class PushTestCase extends ChatTestCase
{
    protected FakePushSender $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pushOn();
        $this->sender = new FakePushSender;
        $this->app->instance(PushSender::class, $this->sender);
    }

    protected function pushOn(): void
    {
        config([
            'chat.push.public_key' => 'BPublicKeyForTestsOnly_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'chat.push.private_key' => 'private-key-for-tests-only',
            'chat.push.subject' => 'mailto:likha@example.test',
        ]);
    }

    protected function pushOff(): void
    {
        config(['chat.push.public_key' => null, 'chat.push.private_key' => null]);
    }

    /** Isang device (subscription) gaya ng ibinibigay ng browser; ang keys ay tamang haba (65 at 16 bytes). */
    protected function device(string $id = 'one', string $host = 'fcm.googleapis.com', string $path = '/fcm/send/'): array
    {
        $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return [
            'endpoint' => "https://{$host}{$path}{$id}-" . str_repeat('x', 20),
            'keys' => [
                'p256dh' => $b64("\x04" . str_pad($id, 64, 'k')),
                'auth' => $b64(str_pad($id, 16, 'a')),
            ],
        ];
    }

    /** I-save ang device para sa tao sa pamamagitan ng route mismo. */
    protected function subscribe(User $user, array $device): void
    {
        $this->actingAs($user)->postJson('/chat/push', $device)->assertSuccessful();
    }
}
