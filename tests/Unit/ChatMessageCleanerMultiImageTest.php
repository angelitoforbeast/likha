<?php

namespace Tests\Unit;

use App\Services\Chatbot\ChatMessageCleaner;
use PHPUnit\Framework\TestCase;

/**
 * Spec 259: ang ilang larawang sabay na pinadala ("link, link" o "link, link, link") ay iisang "[Photo]"; ang isang
 * link ay gaya pa rin ng dati; ang may text, ibang host o hindi link ay hindi ginagalaw.
 */
class ChatMessageCleanerMultiImageTest extends TestCase
{
    private const A = 'https://scontent.xx.fbcdn.net/v/t1.15752-9/893577555_1145189771193247_9184405102603098528_n.jpg?_nc_cat=103&ccb=1-7&oh=03_Q7a&oe=6B10';
    private const B = 'https://scontent.xx.fbcdn.net/v/t1.15752-9/884783588_985351577383069_5242777685843514710_n.png?_nc_cat=107&ccb=1-7&oh=03_Q7b&oe=6B11';
    private const C = 'https://scontent.fmnl4-1.fna.fbcdn.net/v/t1.15752-9/870633730_28964806856537805_376181494241147_n.jpg?_nc_cat=1';
    private const LIKE_BIG = 'https://scontent.xx.fbcdn.net/v/t39.1997-6/39178562_1505197616293642_5411344281094848512_n.png?_nc_cat=1&ccb=1-7';
    private const LIKE_BY_ID = 'https://scontent.xx.fbcdn.net/v/t39.1997-6/12345_369239263222822_678_n.png';

    private static function unchanged(string $m): array
    {
        return ['kind' => null, 'message' => $m, 'image_url' => null, 'image_urls' => null];
    }

    public static function several(): array
    {
        return [
            'two, comma space'   => [self::A . ', ' . self::B, [self::A, self::B]],
            'three, comma space' => [self::A . ', ' . self::B . ', ' . self::C, [self::A, self::B, self::C]],
            'comma, no space'    => [self::A . ',' . self::B, [self::A, self::B]],
            'space only'         => [self::A . ' ' . self::B, [self::A, self::B]],
            'newline'            => [self::A . "\n" . self::B, [self::A, self::B]],
            'spaces around'      => ['  ' . self::A . ' ,  ' . self::B . "\n", [self::A, self::B]],
        ];
    }

    /** @dataProvider several */
    public function test_259_1_several_fbcdn_links_become_one_photo(string $in, array $urls): void
    {
        $this->assertSame(['kind' => 'photo', 'message' => '[Photo]', 'image_url' => $urls[0], 'image_urls' => $urls], ChatMessageCleaner::clean($in));
    }

    public function test_259_1_each_link_is_cut_to_2000_chars_and_at_most_10_are_kept(): void
    {
        $long = self::A . '&x=' . str_repeat('9', 2100);
        $links = array_merge([$long], array_map(fn ($i) => self::B . "&n=$i", range(1, 11)));
        $r = ChatMessageCleaner::clean(implode(', ', $links));

        $this->assertSame('photo', $r['kind']);
        $this->assertCount(10, $r['image_urls']);
        $this->assertSame(2000, mb_strlen($r['image_url']));
        $this->assertSame($r['image_url'], $r['image_urls'][0]);
        $this->assertSame(self::B . '&n=9', $r['image_urls'][9]);
    }

    public function test_259_2_only_like_stickers_become_a_like(): void
    {
        $like = ['kind' => 'like', 'message' => '👍', 'image_url' => null, 'image_urls' => null];
        $this->assertSame($like, ChatMessageCleaner::clean(self::LIKE_BIG . ', ' . self::LIKE_BY_ID));
        $this->assertSame($like, ChatMessageCleaner::clean(self::LIKE_BIG . ', ' . self::LIKE_BIG . ', ' . self::LIKE_BIG));
    }

    public function test_259_3_likes_mixed_with_photos_keep_only_the_photos(): void
    {
        $this->assertSame(['kind' => 'photo', 'message' => '[Photo]', 'image_url' => self::A, 'image_urls' => [self::A, self::B]],
            ChatMessageCleaner::clean(self::LIKE_BIG . ', ' . self::A . ', ' . self::B));
        $this->assertSame(['kind' => 'photo', 'message' => '[Photo]', 'image_url' => self::C, 'image_urls' => [self::C]],
            ChatMessageCleaner::clean(self::C . ', ' . self::LIKE_BY_ID));
    }

    public function test_259_4_a_single_link_is_cleaned_as_before(): void
    {
        $this->assertSame(['kind' => 'photo', 'message' => '[Photo]', 'image_url' => self::A, 'image_urls' => null], ChatMessageCleaner::clean(self::A));
        $this->assertSame(['kind' => 'photo', 'message' => '[Photo]', 'image_url' => self::A, 'image_urls' => null], ChatMessageCleaner::clean(' ' . self::A . "\n"));
        $this->assertSame(['kind' => 'like', 'message' => '👍', 'image_url' => null, 'image_urls' => null], ChatMessageCleaner::clean(self::LIKE_BIG));
        $this->assertSame(2000, mb_strlen(ChatMessageCleaner::clean(self::A . '&x=' . str_repeat('9', 2100))['image_url']));
    }

    public static function untouched(): array
    {
        return [
            'text before the links'  => ['magkano?, ' . self::A],
            'text after the links'   => [self::A . ', ' . self::B . ' magkano po'],
            'text between the links' => [self::A . ' ito po ' . self::B],
            'one link is not fbcdn'  => [self::A . ', https://example.com/a.jpg'],
            'lookalike host'         => [self::A . ', https://fbcdn.net.evil.example/x.png'],
            'not http(s)'            => [self::A . ', ftp://scontent.xx.fbcdn.net/x.jpg'],
            'empty'                  => [''],
            'spaces only'            => ['   '],
            'plain text'             => ['Magkano po?'],
            'phone number'           => ['09171234567'],
            'phone with a link'      => ['09171234567, ' . self::A],
        ];
    }

    /** @dataProvider untouched */
    public function test_259_5_messages_with_anything_else_are_untouched(string $in): void
    {
        $this->assertSame(self::unchanged($in), ChatMessageCleaner::clean($in));
    }
}
