<?php

namespace App\Boardroom\Support;

use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Encryption\Encrypter;

/**
 * Pag-encrypt, pag-mask, at pag-redact ng provider API keys.
 *
 * - Ang encryption key ay nasa .env (BOARDROOM_ENCRYPTION_KEY, o APP_KEY kung blangko) — hiwalay sa database.
 * - Walang method dito na nagbabalik ng plaintext papunta sa browser; decrypt() ay para LANG sa
 *   server-side na request papunta sa provider.
 */
class Secrets
{
    public static function encrypter(): EncrypterContract
    {
        $key = trim((string) config('boardroom.encryption_key'));
        if ($key === '') {
            return app('encrypter');
        }
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        return new Encrypter($key, 'aes-256-cbc');
    }

    public static function encrypt(string $plain): string
    {
        return static::encrypter()->encryptString($plain);
    }

    public static function decrypt(string $cipher): string
    {
        return static::encrypter()->decryptString($cipher);
    }

    public static function last4(string $plain): string
    {
        $plain = trim($plain);

        return strlen($plain) >= 12 ? substr($plain, -4) : '';
    }

    /**
     * Tanggalin ang anumang mukhang secret sa isang text bago ito i-save, i-log, o ipakita.
     *
     * @param  array<int, string|null>  $known  mga aktwal na secret na ginamit sa request
     */
    public static function redact(?string $text, array $known = []): string
    {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }

        foreach ($known as $secret) {
            $secret = trim((string) $secret);
            if (strlen($secret) >= 6) {
                $text = str_replace($secret, '[REDACTED]', $text);
            }
        }

        $patterns = [
            '/\bsk-[A-Za-z0-9_\-\*\.]{6,}/',                       // OpenAI / Anthropic / DeepSeek style keys
            '/\bBearer\s+[A-Za-z0-9_\-\.\*=+\/]{6,}/i',
            '/\b(x-api-key|api[_-]?key|authorization)\b(["\']?\s*[:=]\s*["\']?)[^\s"\',;]{6,}/i',
        ];
        $text = preg_replace($patterns[0], '[REDACTED]', $text) ?? $text;
        $text = preg_replace($patterns[1], 'Bearer [REDACTED]', $text) ?? $text;
        $text = preg_replace($patterns[2], '$1$2[REDACTED]', $text) ?? $text;

        return $text;
    }

    /** Maikli at ligtas na error text (redacted + putol sa haba). */
    public static function safeMessage(?string $text, array $known = [], int $max = 400): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', static::redact($text, $known)) ?? '');

        return mb_strlen($clean) > $max ? mb_substr($clean, 0, $max - 1) . '…' : $clean;
    }
}
