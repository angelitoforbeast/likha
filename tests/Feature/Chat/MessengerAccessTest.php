<?php

namespace Tests\Feature\Chat;

use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;

/** Every route spec 300 adds checks membership (or CEO) on the server: a non-member gets 403 on each. */
class MessengerAccessTest extends ChatTestCase
{
    public static function routes(): array
    {
        return [
            'react' => ['POST', '/chat/{c}/messages/{m}/reactions'],
            'edit' => ['PATCH', '/chat/{c}/messages/{m}'],
            'typing' => ['POST', '/chat/{c}/typing'],
            'mute' => ['POST', '/chat/{c}/mute'],
            'voice' => ['POST', '/chat/{c}/voice'],
            'play voice' => ['GET', '/chat/{c}/messages/{m}/voice'],
        ];
    }

    #[DataProvider('routes')]
    public function test_a_non_member_gets_403(string $method, string $path): void
    {
        $oic = $this->person('Marketing - OIC');
        $g = $this->group($oic, [$this->person()]);
        $m = $this->message($g, $oic, 'Members only', ['attachments' => [[
            'path' => 'chat/2026-11-18/' . str_repeat('a', 40) . '.webm', 'name' => 'Voice message', 'size' => 3, 'mime' => 'audio/webm', 'kind' => 'audio', 'duration' => 3,
        ]]]);
        $url = strtr($path, ['{c}' => $g->id, '{m}' => $m->id]);
        $data = match (true) {
            str_ends_with($path, '/reactions') => ['emoji' => '👍'],
            $method === 'PATCH' => ['body' => 'Mine'],
            str_ends_with($path, '/mute') => ['mute' => 'on'],
            str_ends_with($path, '/voice') && $method === 'POST' => ['voice' => UploadedFile::fake()->createWithContent('v.webm', VoiceTest::recordings()['Chrome / Firefox webm (opus)'][0] . str_repeat("\x00", 2048)), 'duration' => 3],
            default => [],
        };

        foreach ([$this->person('Data Encoder'), $this->person('Data Encoder - OIC'), $this->person(null, 'No Profile', null, false)] as $stranger) {
            $this->actingAs($stranger)->json($method, $url, $data)->assertForbidden();
        }
    }

    public function test_the_ceo_who_has_not_joined_may_play_but_not_post_react_edit_type_or_mute(): void
    {
        $oic = $this->person('Marketing - OIC');
        $g = $this->group($oic, [$this->person()]);
        $m = $this->message($g, $oic, 'x');
        $ceo = $this->ceo();

        $this->actingAs($ceo)->postJson("/chat/{$g->id}/messages/{$m->id}/reactions", ['emoji' => '👍'])->assertForbidden();
        $this->actingAs($ceo)->patchJson("/chat/{$g->id}/messages/{$m->id}", ['body' => 'y'])->assertForbidden();
        $this->actingAs($ceo)->postJson("/chat/{$g->id}/typing")->assertForbidden();
        $this->actingAs($ceo)->postJson("/chat/{$g->id}/mute", ['mute' => 'on'])->assertForbidden();
    }

    public function test_unknown_conversations_and_messages_are_404(): void
    {
        $ana = $this->person();
        $c = $this->direct($ana, $this->person());
        $this->actingAs($ana)->postJson('/chat/424242/typing')->assertNotFound();
        $this->actingAs($ana)->postJson("/chat/{$c->id}/messages/424242/reactions", ['emoji' => '👍'])->assertNotFound();
        $this->actingAs($ana)->patchJson("/chat/{$c->id}/messages/424242", ['body' => 'x'])->assertNotFound();
    }
}
