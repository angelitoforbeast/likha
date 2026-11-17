<?php

namespace Tests\Feature\Chat;

use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;

/** Every route of one conversation checks membership (or CEO) on the server: a non-member gets 403 on each. */
class AccessTest extends ChatTestCase
{
    /** [method, path template]; {c} = conversation, {m} = message, {u} = member. */
    public static function routes(): array
    {
        return [
            'thread' => ['GET', '/chat/{c}'],
            'earlier' => ['GET', '/chat/{c}?before={m}'],
            'poll' => ['GET', '/chat/poll?c={c}&after=0'],
            'send' => ['POST', '/chat/{c}/messages'],
            'remove' => ['DELETE', '/chat/{c}/messages/{m}'],
            'read' => ['POST', '/chat/{c}/read'],
            'file' => ['GET', '/chat/{c}/messages/{m}/files/0'],
            'rename' => ['PATCH', '/chat/{c}'],
            'add' => ['POST', '/chat/{c}/members'],
            'remove member' => ['DELETE', '/chat/{c}/members/{u}'],
            'leave' => ['POST', '/chat/{c}/leave'],
        ];
    }

    #[DataProvider('routes')]
    public function test_a_non_member_gets_403(string $method, string $path): void
    {
        $oic = $this->person('Marketing - OIC');
        $ben = $this->person();
        $g = $this->group($oic, [$ben]);
        $m = $this->message($g, $oic, 'Members only', ['attachments' => [[
            'path' => 'chat/2026-11-18/' . str_repeat('a', 40) . '.png', 'name' => 'a.png', 'size' => 3, 'mime' => 'image/png', 'kind' => 'image',
        ]]]);
        $url = strtr($path, ['{c}' => $g->id, '{m}' => $m->id, '{u}' => $ben->id]);
        $data = match (true) {
            str_ends_with($path, '/messages') => ['body' => 'hi', 'files' => [UploadedFile::fake()->image('x.png')]],
            str_ends_with($path, '/members') => ['user_ids' => [$this->person()->id]],
            $method === 'PATCH' => ['title' => 'Mine'],
            default => [],
        };

        // Also an OIC (may create groups) who is not in this one.
        foreach ([$this->person('Data Encoder'), $this->person('Data Encoder - OIC'), $this->person(null, 'No Profile', null, false)] as $stranger) {
            $this->actingAs($stranger)->json($method, $url, $data)->assertForbidden();
        }
    }

    public function test_unknown_conversations_are_404_not_403(): void
    {
        $this->actingAs($this->person())->getJson('/chat/424242')->assertNotFound();
    }
}
