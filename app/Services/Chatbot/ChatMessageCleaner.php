<?php

namespace App\Services\Chatbot;

/**
 * Ang mensahe ng customer na isang link lang sa Facebook CDN (fbcdn.net) ay nililinis bago iimbak: ang Like sticker
 * ay "👍" (walang link na iniimbak), at ang ibang larawan ay "[Photo]" na ang link ay nasa payload.image_url. Ang ibang
 * mensahe ay hindi ginagalaw. Iisang patakaran para sa /api/botcake/chat at sa botcake:chats-normalize.
 * Ang ilang larawang sabay na pinadala ("link, link" o "link, link, link") ay iisang "[Photo]" din (spec 259): ang
 * unang larawan ang image_url, at lahat ng larawan (hindi Like) ay nasa image_urls.
 */
class ChatMessageCleaner
{
    public const LIKE = '👍';
    public const PHOTO = '[Photo]';

    /** Ang pinakamaraming link na itinatabi sa image_urls. */
    public const MAX_IMAGES = 10;

    /** Ang mga file ng Like sticker na nakita (dalawang laki) at ang mga sticker id ng Like ng Facebook. */
    private const LIKE_FILES = ['39178562_1505197616293642_5411344281094848512_n.png', '851587_369239346556147_162929011_n.png'];
    private const LIKE_IDS = ['369239263222822', '369239346556147', '369239383222810', '369239343222814'];

    /**
     * @return array{kind: ?string, message: string, image_url: ?string, image_urls: ?list<string>}
     *         kind: like | photo | null (walang binago); image_urls: ang mga larawan kapag ilang link ang mensahe
     */
    public static function clean(string $message): array
    {
        $url = trim($message);
        // Ilang link na pinaghihiwalay ng kuwit at/o espasyo: hinahati lang bago ang bawat bagong http(s) link, kaya
        // ang kuwit sa loob ng link ay hindi nahahati. Inuuna ito dahil ang "link,link" (walang espasyo) ay pumapasa
        // rin bilang isang URL. Kapag may bahaging hindi fbcdn link (may text, ibang host), isang link ang tinitingnan.
        $links = preg_split('#(?:\s*,\s*|\s+)(?=https?://)#i', $url);
        if (count($links) > 1 && count(array_filter($links, fn ($link) => self::isFbcdn($link))) === count($links)) return self::several($links);

        if (!self::isFbcdn($url)) return ['kind' => null, 'message' => $message, 'image_url' => null, 'image_urls' => null];
        if (self::isLike($url)) return ['kind' => 'like', 'message' => self::LIKE, 'image_url' => null, 'image_urls' => null];

        return ['kind' => 'photo', 'message' => self::PHOTO, 'image_url' => mb_substr($url, 0, 2000), 'image_urls' => null];
    }

    /**
     * Ilang fbcdn link sa iisang mensahe: puro Like = "👍"; kung hindi, "[Photo]" at ang mga larawan lang (hindi Like).
     *
     * @param  list<string>  $links
     * @return array{kind: string, message: string, image_url: ?string, image_urls: ?list<string>}
     */
    private static function several(array $links): array
    {
        $photos = array_values(array_filter($links, fn ($link) => !self::isLike($link)));
        if ($photos === []) return ['kind' => 'like', 'message' => self::LIKE, 'image_url' => null, 'image_urls' => null];
        $photos = array_map(fn ($link) => mb_substr($link, 0, 2000), array_slice($photos, 0, self::MAX_IMAGES));

        return ['kind' => 'photo', 'message' => self::PHOTO, 'image_url' => $photos[0], 'image_urls' => $photos];
    }

    /** Ang link ay Like sticker (file o sticker id). */
    private static function isLike(string $url): bool
    {
        foreach (array_merge(self::LIKE_FILES, self::LIKE_IDS) as $mark) {
            if (str_contains($url, $mark)) return true;
        }

        return false;
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
