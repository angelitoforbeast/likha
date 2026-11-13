<?php

namespace Tests\Feature\Chatblast;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * Spec 275, ang tunog ng /chatbot/live: ang toggle at ang chip sa header, ang file ng tunog na galing sa app, at ang tuntunin
 * (public/js/chatbot-live-sound.js, pinapatakbo sa node gaya ng ibang pure function ng repo): bagong mensahe ng customer lang
 * ang tumutunog, isang beses kada 3 segundo, at ang bilang sa title habang nakatago ang tab.
 */
class ChatbotLiveSoundTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_04_100000_create_order_encodes_table.php',
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
            'database/migrations/2026_11_11_100000_add_real_send_to_chatbot_replies.php',
            'database/migrations/2026_11_13_100000_widen_via_on_chatbot_replies.php',
        ]]);
        $this->travelTo('2026-10-21 12:30:00');
        $this->page('Page Uno', '1001');
        config(['services.gateway.url' => 'https://forbeast.example.test/api', 'services.forbeast_site.key' => 'fbs_test_key',
            'services.forbeast_site.secret' => 'site-secret', 'services.incepxionbot.url' => 'https://ixb.example.test']);
        Http::fake(['https://forbeast.example.test/api/v1/site/reply-speed*' => Http::response(['date' => '2026-10-21', 'page_id' => null, 'timezone' => 'Asia/Manila',
            'hours' => [], 'total' => ['messages' => 0, 'bot_answered' => 0, 'person_answered' => 0, 'unanswered' => 0, 'avg_s' => null, 'median_s' => null,
                'p90_s' => null, 'max_s' => null, 'meta_to_forbeast_avg_s' => null], 'generated_at' => '2026-10-21T04:30:00Z'])]);
    }

    /** @param array<int, array{0: string, ...}> $calls [fn, ...args] @return array<int, array{value: mixed, args: array}> */
    private function js(array $calls): array
    {
        try {
            $probe = new Process(['node', '--version']);
            $probe->run();
            $found = $probe->isSuccessful();
        } catch (\Throwable $e) {
            $found = false;
        }
        if (! $found) {
            $this->markTestSkipped('skipped: node not found');
        }
        $p = new Process(['node', base_path('tests/js/call.cjs'), public_path('js/chatbot-live-sound.js'), 'LiveSound']);
        $p->setInput(json_encode(array_map(fn ($c) => ['fn' => $c[0], 'args' => array_slice($c, 1)], $calls), JSON_THROW_ON_ERROR));
        $p->run();
        $this->assertTrue($p->isSuccessful(), 'node: '.trim($p->getErrorOutput()));
        $out = json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        foreach ($out as $o) {
            $this->assertArrayNotHasKey('error', $o, json_encode($o));
        }

        return $out;
    }

    private static function ev(string $type, int $id): array
    {
        return ['type' => $type, 'at' => '2026-10-21 12:30:0'.($id % 10), 'page' => 'Page Uno', 'id' => $id, 'bot' => 'forbeast'];
    }

    public function test_s_275_2_the_page_renders_the_sound_toggle_the_enable_chip_and_the_app_served_sound_file(): void
    {
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $html = $this->get('/chatbot/live')->assertOk()->getContent();

        $wav = public_path('sounds/new-message.wav');
        $this->assertFileExists($wav);
        $this->assertLessThan(50000, filesize($wav), 'a small file');
        $head = file_get_contents($wav, false, null, 0, 12);
        $this->assertSame(['RIFF', 'WAVE'], [substr($head, 0, 4), substr($head, 8, 4)]);

        // Ang toggle: "Sound on" ang default sa render, at ang tunog ay ang file ng app (may bersyon ng laman), hindi CDN.
        $this->assertMatchesRegularExpression('#<button class="btn snd ctl" type="button" data-live-sound data-on="true" data-live-sound-src="[^"]*/sounds/new-message\.wav\?v='
            .substr(md5_file($wav), 0, 10).'">.*?<span data-live-sound-label>Sound on</span></button>#s', $html);
        $this->assertStringContainsString('<button class="chip-snd" type="button" data-live-sound-chip hidden>Click to enable sound</button>', $html);
        $this->assertMatchesRegularExpression('#<script src="[^"]*/js/chatbot-live-sound\.js\?v='.substr(md5_file(public_path('js/chatbot-live-sound.js')), 0, 10).'"></script>#', $html);
        $this->assertSame(0, preg_match('/[^\x00-\x7F]/', file_get_contents(public_path('js/chatbot-live-sound.js'))), 'sound file is ASCII');
        // Ang tanging audio URL ng page ay ang file ng app mismo (walang CDN).
        preg_match_all('#https?://[^"\'\s?]*\.(?:mp3|wav|ogg)#i', $html, $audio);
        $this->assertSame([asset('sounds/new-message.wav')], array_values(array_unique($audio[0])));
    }

    public function test_s_275_1_only_new_customer_messages_ring_and_a_burst_rings_once_per_three_seconds(): void
    {
        $others = [self::ev('confirmed', 1), self::ev('follow_up', 2), self::ev('ai', 3), self::ev('order', 4)];
        $burst = [self::ev('message', 5), self::ev('message', 6), self::ev('message', 7)];
        $out = array_column($this->js([
            ['inbound', array_merge($others, $burst)],
            // Ang bot, ang follow-up, ang AI at ang order ay hindi mensahe ng customer: walang tunog.
            ['arrive', ['last' => null, 'unread' => 0], $others, 10000, true, false],
            // Tatlong mensahe sa isang poll: isang tunog.
            ['arrive', ['last' => null, 'unread' => 0], $burst, 10000, true, false],
            // 2 segundo pagkatapos ng tunog: wala pa; eksaktong 3 segundo: tumutunog ulit.
            ['arrive', ['last' => 10000, 'unread' => 0], [self::ev('message', 8)], 12000, true, false],
            ['arrive', ['last' => 10000, 'unread' => 0], [self::ev('message', 9)], 13000, true, false],
            // Walang bagong event (ang unang poll mula sa cursor ng pagbukas): walang tunog.
            ['arrive', ['last' => null, 'unread' => 0], [], 10000, true, false],
            // Naka-off: walang tunog at walang bilang.
            ['arrive', ['last' => null, 'unread' => 4], $burst, 10000, false, true],
        ]), 'value');

        $this->assertSame(3, $out[0]);
        $this->assertSame(['ring' => false, 'last' => null, 'unread' => 0, 'n' => 0], $out[1]);
        $this->assertSame(['ring' => true, 'last' => 10000, 'unread' => 0, 'n' => 3], $out[2]);
        $this->assertSame([false, 10000], [$out[3]['ring'], $out[3]['last']]);
        $this->assertSame([true, 13000], [$out[4]['ring'], $out[4]['last']]);
        $this->assertFalse($out[5]['ring']);
        $this->assertSame(['ring' => false, 'last' => null, 'unread' => 0, 'n' => 3], $out[6]);
    }

    public function test_s_275_4_the_unread_count_grows_only_while_the_tab_is_hidden_and_prefixes_the_title(): void
    {
        $out = array_column($this->js([
            ['arrive', ['last' => null, 'unread' => 0], [self::ev('message', 1), self::ev('message', 2)], 10000, true, true],
            ['arrive', ['last' => 10000, 'unread' => 2], [self::ev('message', 3)], 11000, true, true],
            ['arrive', ['last' => 10000, 'unread' => 3], [self::ev('message', 4)], 20000, true, false],
            ['title', 'Chatbot - Live', 3],
            ['title', 'Chatbot - Live', 0],
        ]), 'value');

        $this->assertSame([true, 2], [$out[0]['ring'], $out[0]['unread']]);
        // Sa loob ng 3 segundo: walang tunog, pero binibilang pa rin.
        $this->assertSame([false, 3], [$out[1]['ring'], $out[1]['unread']]);
        // Nakikita ang tab: wala nang bilang.
        $this->assertSame(0, $out[2]['unread']);
        $this->assertSame(['(3) Chatbot - Live', 'Chatbot - Live'], [$out[3], $out[4]]);
    }

    public function test_s_275_2_the_choice_is_remembered_and_on_is_the_default_even_without_storage(): void
    {
        $out = $this->js([
            ['readOn', new \stdClass],
            ['readOn', ['chatbot-live-sound' => 'off']],
            ['readOn', ['chatbot-live-sound' => 'on']],
            ['readOn', null],
            ['saveOn', new \stdClass, false],
            ['saveOn', null, true],
        ]);

        $this->assertSame([true, false, true, true], array_column(array_slice($out, 0, 4), 'value'));
        $this->assertSame([true, ['chatbot-live-sound' => 'off']], [$out[4]['value'], $out[4]['args'][0]]);
        $this->assertFalse($out[5]['value']);
    }
}
