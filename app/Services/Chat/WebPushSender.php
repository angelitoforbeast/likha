<?php

namespace App\Services\Chat;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Likha Chat (spec 308): ang totoong PushSender, sa minishlink/web-push (VAPID + aes128gcm encryption ng payload).
 * Gumagana nang walang gmp (bcmath o purong PHP ang kalkulasyon). Walang redirect na sinusundan: ang POST ay sa
 * endpoint lang na pumasa sa allowlist (ChatPush::service).
 */
class WebPushSender implements PushSender
{
    /** @param  array  $clientOptions  dagdag na Guzzle options (tests: isang mock handler) */
    public function __construct(private array $clientOptions = [])
    {
    }

    public function send(array $deliveries): array
    {
        $push = new WebPush(
            ['VAPID' => [
                'subject' => (string) config('chat.push.subject'),
                'publicKey' => trim((string) config('chat.push.public_key')),
                'privateKey' => trim((string) config('chat.push.private_key')),
            ]],
            ['TTL' => (int) config('chat.push.ttl', 43200), 'urgency' => 'high'],
            10,
            ['allow_redirects' => false, 'connect_timeout' => 5] + $this->clientOptions,
        );
        $push->setReuseVAPIDHeaders(true);

        $byEndpoint = [];
        foreach ($deliveries as $i => $d) {
            // Pangalawang bantay: hindi kailanman magpapadala sa endpoint na wala sa allowlist.
            if (ChatPush::service($d['endpoint']) === null) {
                $byEndpoint[$d['endpoint']] = $i;

                continue;
            }
            $push->queueNotification(
                Subscription::create(['endpoint' => $d['endpoint'], 'publicKey' => $d['p256dh'], 'authToken' => $d['auth'], 'contentEncoding' => 'aes128gcm']),
                $d['payload'],
                array_filter(['topic' => $d['topic'] ?? null]),
            );
            $byEndpoint[$d['endpoint']] = $i;
        }

        $out = array_fill_keys(array_keys($deliveries), PushSender::FAILED);
        foreach ($push->flush() as $report) {
            $i = $byEndpoint[$report->getEndpoint()] ?? null;
            if ($i === null) {
                continue;
            }
            $status = $report->getResponse()?->getStatusCode();
            // 2xx lang ang ok (ang 3xx na hindi sinundan ay palya, hindi tagumpay).
            $out[$i] = $report->isSubscriptionExpired() ? PushSender::GONE
                : ($report->isSuccess() && $status !== null && $status >= 200 && $status < 300 ? PushSender::OK : PushSender::FAILED);
        }

        return $out;
    }
}
