<?php

namespace Tests\Feature\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Services\Chat\ChatText;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** CH-2: sending (text limits, escaping, links), photos and files, reply, remove, "Seen by". */
class MessagesTest extends ChatTestCase
{
    private $ana;
    private $ben;
    private ChatConversation $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = $this->person('Marketing', 'Ana Cruz');
        $this->ben = $this->person('Marketing', 'Ben Reyes');
        $this->c = $this->direct($this->ana, $this->ben);
    }

    private function send(array $data, $as = null)
    {
        return $this->actingAs($as ?? $this->ana)->post("/chat/{$this->c->id}/messages", $data, ['Accept' => 'application/json']);
    }

    public function test_text_from_1_to_4000_characters_is_sent(): void
    {
        $m = $this->send(['body' => 'Hello Ben'])->assertCreated()->json('message');

        $this->assertSame('Hello Ben', $m['text']);
        $this->assertTrue($m['sender']['me']);
        $this->assertSame('Ana Cruz', $m['sender']['name']);
        $this->assertSame('AC', $m['sender']['initials']);
        $this->send(['body' => str_repeat('a', 4000)])->assertCreated();
        $this->assertNotNull($this->c->fresh()->last_message_at);
        // Your own message is read by you.
        $this->assertSame(ChatMessage::max('id'), (int) ChatMember::where(['conversation_id' => $this->c->id, 'user_id' => $this->ana->id])->value('last_read_message_id'));
    }

    public function test_empty_blank_and_too_long_text_is_refused(): void
    {
        $this->send(['body' => ''])->assertUnprocessable();
        $this->send(['body' => "   \n  "])->assertUnprocessable();
        $this->send(['body' => str_repeat('a', 4001)])->assertUnprocessable();
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_text_is_escaped_and_only_web_links_become_links(): void
    {
        $html = $this->send(['body' => "<script>alert(1)</script> <b>x</b>\nsee https://likha.test/a?b=1&c=2. or javascript:alert(1) \"q\""])
            ->assertCreated()->json('message.html');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<a href="https://likha.test/a?b=1&amp;c=2" target="_blank" rel="noopener noreferrer nofollow">https://likha.test/a?b=1&amp;c=2</a>.', $html);
        $this->assertStringNotContainsString('href="javascript', $html);
        $this->assertStringContainsString('<br>', $html);
        $this->assertStringContainsString('&quot;q&quot;', $html);
    }

    public function test_link_text_cannot_break_out_of_the_attribute(): void
    {
        $html = ChatText::toHtml('https://x.test/"onmouseover="alert(1) and https://y.test/<img>');

        $this->assertStringNotContainsString('"onmouseover', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_photos_and_files_up_to_5_of_10_mb_each_are_stored_and_listed(): void
    {
        $m = $this->send([
            'body' => '',
            'files' => [
                UploadedFile::fake()->image('shelf.jpg', 40, 40),
                UploadedFile::fake()->create('report.pdf', 120, 'application/pdf'),
            ],
        ])->assertCreated()->json('message');

        $this->assertCount(2, $m['files']);
        $this->assertSame('image', $m['files'][0]['kind']);
        $this->assertSame('shelf.jpg', $m['files'][0]['name']);
        $this->assertSame('file', $m['files'][1]['kind']);
        $this->assertSame('report.pdf', $m['files'][1]['name']);
        $this->assertSame(120 * 1024, $m['files'][1]['size']);
        $this->assertStringContainsString("/chat/{$this->c->id}/messages/{$m['id']}/files/0", $m['files'][0]['url']);

        $stored = ChatMessage::find($m['id'])->attachments;
        foreach ($stored as $f) {
            Storage::disk('public')->assertExists($f['path']);
            // Unguessable stored name, never the uploaded name.
            $this->assertStringNotContainsString('shelf', $f['path']);
            $this->assertMatchesRegularExpression('#^chat/\d{4}-\d{2}-\d{2}/[A-Za-z0-9]{40}\.\w+$#', $f['path']);
        }
    }

    public function test_upload_limits_and_types_are_enforced(): void
    {
        $six = array_map(fn ($i) => UploadedFile::fake()->image("p$i.png"), range(1, 6));
        $this->send(['files' => $six])->assertUnprocessable();
        $this->send(['files' => [UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')]])->assertUnprocessable();
        $this->send(['files' => [UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')]])->assertUnprocessable();
        $this->send(['files' => [UploadedFile::fake()->createWithContent('page.html', '<html><script>alert(1)</script></html>')]])->assertUnprocessable();
        $this->send(['files' => [UploadedFile::fake()->createWithContent('run.exe', 'MZ binary')]])->assertUnprocessable();
        // The type comes from the content, not the name: an HTML or SVG file renamed to .jpg is refused.
        $this->send(['files' => [$this->realUpload('fake.jpg', '<html><body><script>alert(1)</script></body></html>')]])->assertUnprocessable();
        $this->send(['files' => [$this->realUpload('logo.png', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')]])->assertUnprocessable();

        $this->assertSame(0, ChatMessage::count());
        $this->send(['files' => array_slice($six, 0, 5)])->assertCreated();
    }

    /** An upload whose type is read from its bytes (finfo), as a real request's would be. */
    private function realUpload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'chat');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_files_are_served_to_members_and_the_ceo_with_a_safe_type(): void
    {
        $m = $this->send(['files' => [
            UploadedFile::fake()->image('shelf.png', 20, 20),
            UploadedFile::fake()->create('notes.csv', 1, 'text/csv'),
        ]])->json('message');
        $img = "/chat/{$this->c->id}/messages/{$m['id']}/files/0";
        $doc = "/chat/{$this->c->id}/messages/{$m['id']}/files/1";

        $r = $this->actingAs($this->ben)->get($img)->assertOk();
        $this->assertSame('image/png', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', $r->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));

        $r = $this->actingAs($this->ben)->get($doc)->assertOk();
        $this->assertStringStartsWith('attachment', $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('notes.csv', $r->headers->get('Content-Disposition'));

        $this->actingAs($this->ceo())->get($img)->assertOk();
        $this->actingAs($this->person())->get($img)->assertForbidden();
        $this->actingAs($this->ben)->get("/chat/{$this->c->id}/messages/{$m['id']}/files/7")->assertNotFound();

        // A file in another conversation is not reachable through this one.
        $other = $this->direct($this->ben, $this->person());
        $this->actingAs($this->ben)->get("/chat/{$other->id}/messages/{$m['id']}/files/0")->assertNotFound();
    }

    public function test_reply_quotes_the_message_above_and_must_be_in_the_same_conversation(): void
    {
        $orig = $this->message($this->c, $this->ben, 'Can you check the stock?');
        $m = $this->send(['body' => 'On it', 'reply_to_id' => $orig->id])->assertCreated()->json('message');

        $this->assertSame($orig->id, $m['reply']['id']);
        $this->assertSame('Ben Reyes', $m['reply']['name']);
        $this->assertSame('Can you check the stock?', $m['reply']['text']);

        $elsewhere = $this->message($this->direct($this->ben, $this->person()), $this->ben, 'secret');
        $this->send(['body' => 'x', 'reply_to_id' => $elsewhere->id])->assertUnprocessable();
    }

    public function test_removing_your_own_message_keeps_the_row_and_shows_message_removed(): void
    {
        $mine = $this->message($this->c, $this->ana, 'Oops wrong chat');
        $reply = $this->message($this->c, $this->ben, 'What?', ['reply_to_id' => $mine->id]);

        $this->actingAs($this->ana)->deleteJson("/chat/{$this->c->id}/messages/{$mine->id}")->assertOk();
        $this->assertSoftDeleted('chat_messages', ['id' => $mine->id]);
        $this->assertSame('Oops wrong chat', ChatMessage::withTrashed()->find($mine->id)->body, 'nothing is deleted');

        $msgs = collect($this->actingAs($this->ben)->getJson("/chat/{$this->c->id}")->json('messages'))->keyBy('id');
        $this->assertTrue($msgs[$mine->id]['removed']);
        $this->assertNull($msgs[$mine->id]['html']);
        $this->assertNull($msgs[$mine->id]['text']);
        $this->assertSame([], $msgs[$mine->id]['files']);
        $this->assertTrue($msgs[$reply->id]['reply']['removed']);
        $this->assertNull($msgs[$reply->id]['reply']['text']);

        $row = $this->actingAs($this->ben)->getJson('/chat/conversations')->json('conversations.0');
        $this->assertSame('What?', $row['last']['text']);
    }

    public function test_you_may_not_remove_someone_elses_message(): void
    {
        $his = $this->message($this->c, $this->ben, 'Mine');
        $this->actingAs($this->ana)->deleteJson("/chat/{$this->c->id}/messages/{$his->id}")->assertForbidden();
        $this->actingAs($this->ceo())->deleteJson("/chat/{$this->c->id}/messages/{$his->id}")->assertForbidden();
        $this->assertNotSoftDeleted('chat_messages', ['id' => $his->id]);
    }

    public function test_a_removed_messages_files_are_no_longer_served(): void
    {
        $m = $this->send(['files' => [UploadedFile::fake()->image('a.png')]])->json('message');
        $this->actingAs($this->ana)->deleteJson("/chat/{$this->c->id}/messages/{$m['id']}")->assertOk();
        $this->actingAs($this->ben)->get("/chat/{$this->c->id}/messages/{$m['id']}/files/0")->assertNotFound();
    }

    public function test_seen_by_comes_from_each_members_last_read_message(): void
    {
        $cara = $this->person('Marketing', 'Cara Santos');
        $g = $this->group($this->ana, [$this->ben, $cara]);
        $mine = $this->message($g, $this->ana, 'Meeting at 3');

        $this->actingAs($this->ben)->getJson("/chat/{$g->id}")->assertOk();   // opening marks it read

        $reads = collect($this->actingAs($this->ana)->getJson("/chat/{$g->id}")->json('reads'))->keyBy('user_id');
        $this->assertSame($mine->id, $reads[$this->ben->id]['last_read_message_id']);
        $this->assertSame('BR', $reads[$this->ben->id]['initials']);
        $this->assertSame(0, $reads[$cara->id]['last_read_message_id']);
        $this->assertFalse($reads->has($this->ana->id), 'you are not in your own Seen by');
    }
}
