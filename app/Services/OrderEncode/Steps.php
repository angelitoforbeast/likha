<?php

namespace App\Services\OrderEncode;

/**
 * Ang talaan ng bawat hakbang at bawat tawag sa GPT ng isang order (ang GPT_DEBUG / GPT_VERIFY / GPT_NAMEADDR ng script).
 * Walang key kailanman dito: ang raw ay dumadaan sa redact() bago itala.
 */
final class Steps
{
    public const RAW_MAX = 2000;

    /** @var array<int, array<string, mixed>> */
    public array $log = [];

    public function __construct(private string $secret = '')
    {
    }

    public function redact(string $text): string
    {
        if ($this->secret !== '') $text = str_replace($this->secret, '[key]', $text);

        return preg_replace('/sk-[A-Za-z0-9_\-]{8,}/', '[key]', $text) ?? $text;
    }

    /** @param array<string, mixed> $extra */
    public function add(string $step, string $status, array $extra = []): void
    {
        $raw = isset($extra['raw']) ? mb_substr($this->redact((string) $extra['raw']), 0, self::RAW_MAX) : null;
        $this->log[] = ['step' => $step, 'status' => mb_substr($this->redact($status), 0, 500)]
            + array_diff_key($extra, ['raw' => 1]) + ($raw !== null ? ['raw' => $raw] : []);
    }

    /** Ilang tawag sa OpenAI ang ginawa (bawat HTTP request). */
    public function requests(): int
    {
        return (int) array_sum(array_map(fn ($s) => (int) ($s['requests'] ?? 0), $this->log));
    }
}
