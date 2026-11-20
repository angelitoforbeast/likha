<?php

namespace Tests\Feature\Chat;

use App\Services\Chat\PushSender;
use App\Services\Chat\WebPushSender;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\VAPID;
use Tests\TestCase;

/**
 * The real sender (minishlink/web-push): VAPID signing and payload encryption run for real; only the HTTP goes to a
 * Guzzle mock. Also run with gmp switched off (php -n with only bcmath, openssl, mbstring, curl…) to prove the server's
 * PHP without gmp is enough (see the 308 result).
 */
class PushCryptoTest extends TestCase
{
    private array $history = [];

    private function subscriber(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($key)['ec'];
        $point = "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
        $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return ['p256dh' => $b64($point), 'auth' => $b64(random_bytes(16))];
    }

    private function sender(array $responses): WebPushSender
    {
        $keys = VAPID::createVapidKeys();
        config(['chat.push.public_key' => $keys['publicKey'], 'chat.push.private_key' => $keys['privateKey'], 'chat.push.subject' => 'mailto:likha@example.test']);
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new WebPushSender(['handler' => $stack]);
    }

    private function delivery(string $endpoint, array $sub): array
    {
        return ['user_id' => 1, 'endpoint' => $endpoint, 'p256dh' => $sub['p256dh'], 'auth' => $sub['auth'],
            'payload' => json_encode(['title' => 'Mira', 'body' => 'Did the 3 PM pickup go out?', 'tag' => 'likha-chat-7']), 'topic' => 'c7'];
    }

    public function test_a_push_is_signed_and_encrypted_and_404_410_are_gone(): void
    {
        $sender = $this->sender([new Response(201), new Response(410), new Response(404), new Response(500), new Response(302, ['Location' => 'https://127.0.0.1/'])]);
        $sub = $this->subscriber();
        $endpoints = [
            'https://fcm.googleapis.com/fcm/send/ok',
            'https://updates.push.services.mozilla.com/wpush/v2/gone',
            'https://web.push.apple.com/gone-too',
            'https://wns2-bl2p.notify.windows.com/w/broken',
            'https://fcm.googleapis.com/fcm/send/redirect',
        ];
        $results = $sender->send(array_map(fn ($e) => $this->delivery($e, $sub), $endpoints));

        $this->assertSame([PushSender::OK, PushSender::GONE, PushSender::GONE, PushSender::FAILED, PushSender::FAILED], array_values($results));
        $this->assertCount(5, $this->history, 'one request each, the redirect not followed');

        $req = $this->history[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('aes128gcm', $req->getHeaderLine('Content-Encoding'));
        $this->assertStringStartsWith('vapid t=', $req->getHeaderLine('Authorization'));
        $this->assertStringContainsString(', k=' . config('chat.push.public_key'), $req->getHeaderLine('Authorization'));
        $this->assertSame('43200', $req->getHeaderLine('TTL'));
        $this->assertSame('high', $req->getHeaderLine('Urgency'));
        $this->assertSame('c7', $req->getHeaderLine('Topic'));
        // Encrypted: the text never travels in the clear.
        $body = (string) $req->getBody();
        $this->assertNotSame('', $body);
        $this->assertStringNotContainsString('pickup', $body);
        $this->assertStringNotContainsString('Mira', $body);
        // The private key is never sent.
        $this->assertStringNotContainsString(config('chat.push.private_key'), implode("\n", array_map(fn ($v) => implode(',', $v), $req->getHeaders())) . $body);
    }

    public function test_an_endpoint_off_the_allowlist_is_never_requested(): void
    {
        $sender = $this->sender([new Response(201)]);
        $results = $sender->send([$this->delivery('https://127.0.0.1/steal', $this->subscriber())]);

        $this->assertSame([PushSender::FAILED], array_values($results));
        $this->assertCount(0, $this->history);
    }

    public function test_the_container_gives_the_real_sender(): void
    {
        $this->assertInstanceOf(WebPushSender::class, app(PushSender::class));
    }
}
