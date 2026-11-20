<?php

namespace Tests\Feature\Chat;

use App\Services\Chat\PushSender;

/** Pekeng push sender para sa tests: itinatala ang bawat padala; ang sagot bawat endpoint ay itinatakda ng test. */
class FakePushSender implements PushSender
{
    /** @var array<int, array{endpoint: string, p256dh: string, auth: string, payload: array, topic: ?string}> */
    public array $sent = [];

    /** @var array<string, string> endpoint → 'gone' | 'failed' (ang wala rito ay 'ok') */
    public array $results = [];

    public function send(array $deliveries): array
    {
        $out = [];
        foreach ($deliveries as $i => $d) {
            $this->sent[] = $d + ['payload_array' => json_decode($d['payload'], true)];
            $out[$i] = $this->results[$d['endpoint']] ?? PushSender::OK;
        }

        return $out;
    }

    /** Ang mga payload na napunta sa isang endpoint. */
    public function to(string $endpoint): array
    {
        return array_values(array_map(fn ($d) => $d['payload_array'], array_filter($this->sent, fn ($d) => $d['endpoint'] === $endpoint)));
    }

    public function endpoints(): array
    {
        return array_values(array_unique(array_map(fn ($d) => $d['endpoint'], $this->sent)));
    }
}
