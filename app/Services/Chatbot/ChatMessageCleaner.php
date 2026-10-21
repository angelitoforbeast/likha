<?php

namespace App\Services\Chatbot;

/**
 * Ang mensahe ng customer na isang link lang sa Facebook CDN (fbcdn.net) ay nililinis bago iimbak: ang Like sticker
 * ay "👍" (walang link na iniimbak), at ang ibang larawan ay "[Photo]" na ang link ay nasa payload.image_url. Ang ibang
 * mensahe ay hindi ginagalaw. Iisang patakaran para sa /api/botcake/chat at sa botcake:chats-normalize.
 */
class ChatMessageCleaner
{
    public const LIKE = '👍';
    public const PHOTO = '[Photo]';

    /** Ang mga file ng Like sticker na nakita (dalawang laki) at ang mga sticker id ng Like ng Facebook. */
    private const LIKE_FILES = ['39178562_1505197616293642_5411344281094848512_n.png', '851587_369239346556147_162929011_n.png'];
    private const LIKE_IDS = ['369239263222822', '369239346556147', '369239383222810', '369239343222814'];

    /** @return array{kind: ?string, message: string, image_url: ?string} kind: like | photo | null (walang binago) */
    public static function clean(string $message): array
    {
        $url = trim($message);
        if (!self::isFbcdn($url)) return ['kind' => null, 'message' => $message, 'image_url' => null];

        foreach (array_merge(self::LIKE_FILES, self::LIKE_IDS) as $mark) {
            if (str_contains($url, $mark)) return ['kind' => 'like', 'message' => self::LIKE, 'image_url' => null];
        }

        return ['kind' => 'photo', 'message' => self::PHOTO, 'image_url' => mb_substr($url, 0, 2000)];
    }

    /** Buong mensahe na http(s) link sa fbcdn.net o sa sub-domain nito. */
    private static function isFbcdn(string $url): bool
    {
        if ($url === '' || preg_match('/\s/', $url) || !filter_var($url, FILTER_VALIDATE_URL)) return false;
        if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) return false;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === 'fbcdn.net' || str_ends_with($host, '.fbcdn.net');
    }
}
