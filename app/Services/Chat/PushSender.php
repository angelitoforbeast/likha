<?php

namespace App\Services\Chat;

/**
 * Likha Chat (spec 308): nagpapadala ng web push sa mga push service. Pinapalitan ng peke sa tests (sa container).
 *
 * Bawat delivery: ['endpoint', 'p256dh', 'auth', 'payload' (JSON string), 'topic' (o null)].
 * Ibinabalik ang sagot bawat delivery (parehong key): OK, GONE (404 / 410: wala na ang subscription) o FAILED.
 */
interface PushSender
{
    public const OK = 'ok';
    public const GONE = 'gone';
    public const FAILED = 'failed';

    /** @return array<int|string, string> */
    public function send(array $deliveries): array;
}
