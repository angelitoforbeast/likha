<?php

namespace Tests\Unit;

use App\Services\Chatbot\ChatAttachments;
use PHPUnit\Framework\TestCase;

/**
 * Ang GIF na pinili ng customer sa Messenger ay dumarating mula sa Botcake bilang link lang (cdn.fbsbx.com, t59.2708-21,
 * .gif): attachment ito, gaya ng larawan. Ang ibang link at ang link na may kasamang salita ay text pa rin.
 */
class ChatAttachmentsGifTest extends TestCase
{
    private const GIF = 'https://cdn.fbsbx.com/v/t59.2708-21/111111111_2222222222222222_3333333333333333333_n.gif';

    public function test_S_241_1_3_a_messenger_gif_link_is_a_gif_with_a_query_upper_case_or_http(): void
    {
        $cases = [
            'bare' => self::GIF,
            'query string' => self::GIF . '?_nc_cat=1&ccb=1-7&_nc_sid=cf94fc&oh=00_abc&oe=12345678',
            'upper-case .GIF' => substr(self::GIF, 0, -4) . '.GIF',
            'http' => 'http://cdn.fbsbx.com/v/t59.2708-21/1_2_3_n.gif',
            'upper-case host' => 'https://CDN.FBSBX.COM/v/t59.2708-21/1_2_3_n.gif',
            'spaces around' => '  ' . self::GIF . "\n",
        ];
        foreach ($cases as $label => $message) {
            $this->assertSame('gif', ChatAttachments::type($message), $label);
        }
        $long = self::GIF . '?x=' . str_repeat('a', 2100);
        $this->assertSame(['type' => 'gif', 'url' => mb_substr($long, 0, 2000)], ChatAttachments::gif($long), 'the link is capped as today');
    }

    public function test_S_241_2_other_links_text_and_existing_markers_keep_todays_type(): void
    {
        $cases = [
            // S-241.2.1: .gif sa ibang host
            'giphy' => ['https://media.giphy.com/media/abc123/x.gif', null],
            'example.com' => ['https://example.com/a.gif', null],
            'look-alike host' => ['https://cdn.fbsbx.com.example.com/v/t59.2708-21/1_n.gif', null],
            // S-241.2.2: cdn.fbsbx.com na hindi .gif, o wala sa t59.2708-21
            'fbsbx mp4' => ['https://cdn.fbsbx.com/v/t59.2708-21/1_n.mp4', null],
            'fbsbx png' => ['https://cdn.fbsbx.com/v/t59.2708-21/1_n.png', null],
            'other segment' => ['https://cdn.fbsbx.com/v/t59.3654-21/1_n.gif', null],
            'longer segment' => ['https://cdn.fbsbx.com/v/t59.2708-210/1_n.gif', null],
            'gif only in the query' => ['https://cdn.fbsbx.com/v/t59.2708-21/1_n.mp4?f=a.gif', null],
            // S-241.2.3: may kasamang salita
            'words before' => ['ito po ' . self::GIF, null],
            'words after' => [self::GIF . ' ito po', null],
            // S-241.2.4: gaya ng dati
            'photo marker' => ['[Photo]', 'photo'],
            'sticker' => ['[sticker] https://scontent.xx.fbcdn.net/v/t39.1997-6/cat_n.png', null],
            'like' => ['👍', null],
            'audio marker' => ['[audio](https://cdn.fbsbx.com/v/t59.3654-21/voice.mp4)', 'audio'],
            'photo with text' => ['[photo] ito', null],
            'plain text' => ['09171234567', null],
        ];
        foreach ($cases as $label => [$message, $type]) {
            $this->assertSame($type, ChatAttachments::type($message), $label);
        }
        $this->assertNull(ChatAttachments::type('[Photo]', ['image_url' => 'https://scontent.xx.fbcdn.net/v/t39.1997-6/s_n.png']), 'a sticker photo');
    }
}
