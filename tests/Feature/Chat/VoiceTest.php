<?php

namespace Tests\Feature\Chat;

use App\Models\ChatMember;
use App\Models\ChatMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

/** MP-7: hold-to-record voice messages, stored and served like 296's files, behind the same membership checks. */
class VoiceTest extends ChatTestCase
{
    /** The first bytes of real recordings, as the browsers make them (the type is read from the bytes, not the name). */
    public static function recordings(): array
    {
        return [
            'Chrome / Firefox webm (opus)' => ["\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x04\x42\x85\x81\x02", 'webm', 'audio/webm'],
            'Firefox ogg (opus)' => ["OggS\x00\x02" . str_repeat("\x00", 20) . "\x01\x13OpusHead\x01\x01\x38\x01\x80\xBB\x00\x00\x00\x00\x00", 'ogg', 'audio/ogg'],
            'Safari mp4 (aac)' => ["\x00\x00\x00\x1CftypM4A \x00\x00\x00\x00M4A isommp42\x00\x00\x00\x08free", 'm4a', 'audio/mp4'],
            'mp3' => ["ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xFF\xFB\x90\x64" . str_repeat("\x00", 413), 3), 'mp3', 'audio/mpeg'],
        ];
    }

    private function voice(string $head, string $name = 'voice.webm', int $kb = 0): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $head . str_repeat("\x00", max(0, $kb * 1024 - strlen($head)) + 2048));
    }

    #[DataProvider('recordings')]
    public function test_a_member_sends_a_voice_message_and_members_play_it(string $head, string $ext, string $type): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);

        $msg = $this->actingAs($ana)->post("/chat/{$c->id}/voice", ['voice' => $this->voice($head, "clip.$ext"), 'duration' => 42], ['Accept' => 'application/json'])
            ->assertCreated()->json('message');

        $this->assertSame('', $msg['text'], 'no text, as a photo-only message');
        $this->assertCount(1, $msg['files']);
        $this->assertSame('audio', $msg['files'][0]['kind']);
        $this->assertSame(42, $msg['files'][0]['duration']);
        $this->assertStringEndsWith("/chat/{$c->id}/messages/{$msg['id']}/voice", $msg['files'][0]['url']);

        $stored = ChatMessage::find($msg['id'])->attachments[0];
        $this->assertMatchesRegularExpression('~^chat/\d{4}-\d{2}-\d{2}/[A-Za-z0-9]{40}\.' . $ext . '$~', $stored['path']);
        Storage::disk('public')->assertExists($stored['path']);

        $res = $this->actingAs($ben)->get("/chat/{$c->id}/messages/{$msg['id']}/voice")->assertOk();
        $this->assertSame($type, $res->headers->get('Content-Type'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', $res->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('private', $res->headers->get('Cache-Control'));

        // The list's last line and the alert say what it is.
        $row = collect($this->actingAs($ben)->getJson('/chat/conversations')->json('conversations'))->firstWhere('id', $c->id);
        $this->assertSame('Voice message', $row['last']['text']);
    }

    public function test_type_size_and_length_limits(): void
    {
        $ana = $this->person();
        $c = $this->direct($ana, $this->person());
        $webm = self::recordings()['Chrome / Firefox webm (opus)'][0];
        $send = fn (array $data) => $this->actingAs($ana)->post("/chat/{$c->id}/voice", $data, ['Accept' => 'application/json']);

        $send(['voice' => UploadedFile::fake()->createWithContent('voice.webm', 'just text pretending'), 'duration' => 3])->assertUnprocessable();
        $send(['voice' => UploadedFile::fake()->createWithContent('voice.webm', '<html><script>alert(1)</script></html>'), 'duration' => 3])->assertUnprocessable();
        $send(['voice' => UploadedFile::fake()->image('voice.png'), 'duration' => 3])->assertUnprocessable();
        $send(['voice' => $this->voice($webm, 'voice.webm', 5121), 'duration' => 100])->assertUnprocessable();
        $send(['voice' => $this->voice($webm), 'duration' => 121])->assertUnprocessable();
        $send(['voice' => $this->voice($webm), 'duration' => 0])->assertUnprocessable();
        $send(['voice' => $this->voice($webm)])->assertUnprocessable();
        $send(['duration' => 5])->assertUnprocessable();
        $this->assertDatabaseCount('chat_messages', 0);

        $send(['voice' => $this->voice($webm, 'voice.webm', 5000), 'duration' => 120])->assertCreated();
    }

    public function test_only_active_members_send_and_only_members_or_the_ceo_play(): void
    {
        $oic = $this->person('Marketing - OIC');
        $ana = $this->person();
        $g = $this->group($oic, [$ana]);
        $webm = self::recordings()['Chrome / Firefox webm (opus)'][0];

        $this->actingAs($this->ceo())->post("/chat/{$g->id}/voice", ['voice' => $this->voice($webm), 'duration' => 3], ['Accept' => 'application/json'])->assertForbidden();
        $id = $this->actingAs($oic)->post("/chat/{$g->id}/voice", ['voice' => $this->voice($webm), 'duration' => 3], ['Accept' => 'application/json'])->assertCreated()->json('message.id');

        $this->actingAs($ana)->get("/chat/{$g->id}/messages/$id/voice")->assertOk();
        $this->actingAs($this->ceo())->get("/chat/{$g->id}/messages/$id/voice")->assertOk();
        $this->actingAs($this->person())->get("/chat/{$g->id}/messages/$id/voice")->assertForbidden();

        // Removed: no longer served. A text message has no voice: 404.
        ChatMessage::find($id)->delete();
        $this->actingAs($ana)->get("/chat/{$g->id}/messages/$id/voice")->assertNotFound();
        $text = $this->message($g, $oic, 'just text');
        $this->actingAs($ana)->get("/chat/{$g->id}/messages/{$text->id}/voice")->assertNotFound();

        // Departed: cannot send.
        ChatMember::where(['conversation_id' => $g->id, 'user_id' => $ana->id])->update(['left_at' => now()]);
        $this->actingAs($ana)->post("/chat/{$g->id}/voice", ['voice' => $this->voice($webm), 'duration' => 3], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_the_page_hides_the_mic_where_the_browser_cannot_record(): void
    {
        $html = $this->actingAs($this->person())->get('/chat')->assertOk()->getContent();
        $this->assertStringContainsString('id="lc-mic"', $html);
        $this->assertStringContainsString('MediaRecorder', $html);
        $this->assertStringContainsString('"voice_max_seconds":120', $html);
    }
}
