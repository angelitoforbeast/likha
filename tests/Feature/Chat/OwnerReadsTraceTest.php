<?php

namespace Tests\Feature\Chat;

use App\Models\ChatMember;
use App\Models\ChatMessage;

/** OL-2 (spec 304): the owner reads a conversation he is not in without leaving any trace for its members. */
class OwnerReadsTraceTest extends ChatTestCase
{
    /** The CEO opens, polls, marks read and tries to type in $c, like the page does while he reads it. */
    private function ceoReads($ceo, $c, int $after): void
    {
        $this->actingAs($ceo)->get("/chat?c={$c->id}")->assertOk();
        $this->actingAs($ceo)->getJson("/chat/{$c->id}")->assertOk();
        $this->actingAs($ceo)->getJson("/chat/poll?c={$c->id}&after=0")->assertOk();
        $this->actingAs($ceo)->getJson("/chat/poll?c={$c->id}&after={$after}&since=" . urlencode(now()->subMinute()->toIso8601String()) . '&all=1')->assertOk();
        $this->actingAs($ceo)->postJson("/chat/{$c->id}/read")->assertOk();
        $this->actingAs($ceo)->postJson("/chat/{$c->id}/typing")->assertForbidden();
        $this->actingAs($ceo)->getJson('/chat/conversations?all=1')->assertOk();
        $this->actingAs($ceo)->getJson('/chat/unread?alerts=1')->assertOk();
    }

    public function test_members_see_no_seen_head_for_the_owner(): void
    {
        $ceo = $this->ceo();
        $ana = $this->person('Marketing', 'Ana');
        $ben = $this->person('Marketing', 'Ben');
        $g = $this->group($ana, [$ben]);
        $m = $this->message($g, $ana, 'for us');

        $this->ceoReads($ceo, $g, $m->id);

        foreach (["/chat/{$g->id}", "/chat/poll?c={$g->id}&after=0"] as $url) {
            $reads = $this->actingAs($ana)->getJson($url)->json(str_contains($url, 'poll') ? 'thread.reads' : 'reads');
            $this->assertSame([$ben->id], array_column($reads, 'user_id'), "$url: only Ben can show as Seen");
        }
    }

    public function test_members_see_no_typing_line_or_active_dot_from_the_owner(): void
    {
        $ceo = $this->ceo();
        $ana = $this->person('Marketing', 'Ana');
        $ben = $this->person('Marketing', 'Ben');
        $d = $this->direct($ana, $ben);
        $g = $this->group($ana, [$ben]);
        $m = $this->message($g, $ana, 'for us');

        $this->ceoReads($ceo, $g, $m->id);
        $this->ceoReads($ceo, $d, 0);

        $this->assertSame([], $this->actingAs($ana)->getJson("/chat/poll?c={$g->id}&after=0")->json('thread.typing'));
        $members = $this->actingAs($ana)->getJson("/chat/{$g->id}")->json('conversation.members');
        $this->assertEqualsCanonicalizing([$ana->id, $ben->id], array_column($members, 'id'), 'he is not listed, so no dot');
        // The direct chat's dot is Ben's; Ben has not used Likha, so none, even though the CEO just did.
        $row = collect($this->actingAs($ana)->getJson('/chat/conversations')->json('conversations'))->firstWhere('id', $d->id);
        $this->assertNull($row['active_at']);
    }

    public function test_no_member_row_joined_line_or_system_message_is_created(): void
    {
        $ceo = $this->ceo('Owner Boss');
        $ana = $this->person('Marketing', 'Ana');
        $ben = $this->person('Marketing', 'Ben');
        $g = $this->group($ana, [$ben]);
        $d = $this->direct($ana, $ben);
        $m = $this->message($g, $ana, 'for us');
        $members = ChatMember::count();
        $messages = ChatMessage::withTrashed()->count();

        $this->ceoReads($ceo, $g, $m->id);
        $this->ceoReads($ceo, $d, 0);

        $this->assertFalse(ChatMember::where('user_id', $ceo->id)->exists());
        $this->assertSame($members, ChatMember::count());
        $this->assertSame($messages, ChatMessage::withTrashed()->count());
        $this->assertFalse(ChatMessage::where('body', 'like', '%Owner Boss%')->exists(), 'no "joined" or system line');
    }

    public function test_the_members_payloads_carry_nothing_about_the_owner(): void
    {
        $this->freezeTime();
        $ceo = $this->ceo('Owner Boss');
        $ana = $this->person('Marketing', 'Ana');
        $ben = $this->person('Marketing', 'Ben');
        $g = $this->group($ana, [$ben]);
        $d = $this->direct($ana, $ben);
        $m = $this->message($g, $ben, 'for us');
        $this->message($d, $ben, 'just us');
        $since = urlencode(now()->subMinute()->toIso8601String());

        // What Ana's page receives: the list, the thread, the poll and the unread alerts.
        $payloads = function () use ($ana, $g, $d, $since) {
            $out = [];
            foreach (['/chat/conversations', "/chat/{$g->id}", "/chat/{$d->id}", "/chat/poll?c={$g->id}&after=0&since=$since",
                "/chat/poll?c={$d->id}&after=0&since=$since", '/chat/unread', '/chat/unread?alerts=1&after=0'] as $url) {
                $out[$url] = $this->actingAs($ana)->getJson($url)->assertOk()->getContent();
            }

            return $out;
        };
        $payloads(); // Ana's own first visit marks her active and her reads; the snapshot is from her second.
        $before = $payloads();

        $this->ceoReads($ceo, $g, $m->id);
        $this->ceoReads($ceo, $d, 0);
        $after = $payloads();

        $this->assertSame($before, $after, 'the owner reading changes nothing members receive');
        foreach ($after as $url => $json) {
            $this->assertStringNotContainsString('Owner Boss', $json, $url);
            $this->assertStringNotContainsString('"OB"', $json, "$url: no initials either");
        }
        // Ben's unread counts are his own too.
        $this->assertSame(0, $this->actingAs($ben)->getJson('/chat/unread')->json('unread'));
    }

    public function test_where_the_owner_is_a_member_his_seen_head_shows_as_before(): void
    {
        $ceo = $this->ceo('Owner Boss');
        $ana = $this->person('Marketing', 'Ana');
        $d = $this->direct($ana, $ceo);
        $m = $this->message($d, $ana, 'hi boss');

        $this->actingAs($ceo)->getJson("/chat/{$d->id}")->assertOk();

        $reads = collect($this->actingAs($ana)->getJson("/chat/{$d->id}")->json('reads'))->keyBy('user_id');
        $this->assertSame($m->id, $reads[$ceo->id]['last_read_message_id']);
        $this->assertSame('Owner Boss', $reads[$ceo->id]['name']);
    }
}
