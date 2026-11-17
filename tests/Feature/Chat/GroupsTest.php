<?php

namespace Tests\Feature\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMember;
use App\Models\ChatMessage;

/** CH-1 groups: who may create and manage a group, leaving and removal. */
class GroupsTest extends ChatTestCase
{
    public function test_the_ceo_and_any_oic_may_create_a_group_and_staff_may_not(): void
    {
        $ceo = $this->ceo();
        $oic = $this->person('Data Encoder - OIC', 'Olive Ic');
        $staff = $this->person('Marketing', 'Sam Staff');
        $ben = $this->person('Marketing', 'Ben Reyes');

        $a = $this->actingAs($ceo)->postJson('/chat/groups', ['title' => 'Owners', 'user_ids' => [$ben->id]])->assertOk()->json('id');
        $b = $this->actingAs($oic)->postJson('/chat/groups', ['title' => 'Encoders', 'user_ids' => [$ben->id, $staff->id]])->assertOk()->json('id');
        $this->actingAs($staff)->postJson('/chat/groups', ['title' => 'Nope', 'user_ids' => [$ben->id]])->assertForbidden();

        $this->assertSame(2, ChatConversation::where('kind', 'group')->count());
        $this->assertSame('admin', ChatMember::where(['conversation_id' => $b, 'user_id' => $oic->id])->value('role'));
        $this->assertSame('member', ChatMember::where(['conversation_id' => $b, 'user_id' => $ben->id])->value('role'));
        $this->assertSame(3, ChatMember::where('conversation_id', $b)->count());
        $this->assertSame('admin', ChatMember::where(['conversation_id' => $a, 'user_id' => $ceo->id])->value('role'));
        // A system line records the creation, so the group shows up in everyone's list with activity.
        $this->assertSame('Olive Ic created the group', ChatMessage::where('conversation_id', $b)->value('body'));
        $this->assertNotNull(ChatConversation::find($b)->last_message_at);
    }

    public function test_the_ceo_added_to_a_group_is_an_admin(): void
    {
        $oic = $this->person('Marketing - OIC');
        $ceo = $this->ceo();

        $id = $this->actingAs($oic)->postJson('/chat/groups', ['title' => 'Mkt', 'user_ids' => [$ceo->id]])->json('id');
        $this->assertSame('admin', ChatMember::where(['conversation_id' => $id, 'user_id' => $ceo->id])->value('role'));
    }

    public function test_a_group_needs_a_name_and_active_members(): void
    {
        $oic = $this->person('Marketing - OIC');
        $ben = $this->person();
        $gone = $this->person('Marketing', 'Gone', 'Resigned');

        $this->actingAs($oic)->postJson('/chat/groups', ['title' => '', 'user_ids' => [$ben->id]])->assertUnprocessable();
        $this->actingAs($oic)->postJson('/chat/groups', ['title' => str_repeat('a', 81), 'user_ids' => [$ben->id]])->assertUnprocessable();
        $this->actingAs($oic)->postJson('/chat/groups', ['title' => 'G', 'user_ids' => []])->assertUnprocessable();
        $this->actingAs($oic)->postJson('/chat/groups', ['title' => 'G', 'user_ids' => [$gone->id]])->assertUnprocessable();
        $this->assertSame(0, ChatConversation::count());
    }

    public function test_admins_and_the_ceo_may_rename_and_plain_members_may_not(): void
    {
        $oic = $this->person('Marketing - OIC', 'Olive');
        $otherOic = $this->person('Data Encoder - OIC', 'Other Oic');
        $ben = $this->person();
        $ceo = $this->ceo();
        $g = $this->group($oic, [$ben, $otherOic], 'Old name');

        $this->actingAs($ben)->patchJson("/chat/{$g->id}", ['title' => 'Mine now'])->assertForbidden();
        $this->actingAs($otherOic)->patchJson("/chat/{$g->id}", ['title' => 'Mine now'])->assertForbidden();
        $this->actingAs($oic)->patchJson("/chat/{$g->id}", ['title' => 'New name'])->assertOk();
        $this->assertSame('New name', $g->fresh()->title);
        // The CEO is an admin of every group, member or not.
        $this->actingAs($ceo)->patchJson("/chat/{$g->id}", ['title' => 'Owner name'])->assertOk();
        $this->assertSame('Owner name', $g->fresh()->title);
        $this->assertTrue(ChatMessage::where('conversation_id', $g->id)->where('body', 'Olive renamed the group to "New name"')->exists());
    }

    public function test_a_direct_chat_cannot_be_renamed_left_or_given_members(): void
    {
        $ceo = $this->ceo();
        $ben = $this->person();
        $c = $this->direct($ceo, $ben);
        $cara = $this->person();

        $this->actingAs($ceo)->patchJson("/chat/{$c->id}", ['title' => 'X'])->assertUnprocessable();
        $this->actingAs($ceo)->postJson("/chat/{$c->id}/members", ['user_ids' => [$cara->id]])->assertUnprocessable();
        $this->actingAs($ben)->postJson("/chat/{$c->id}/leave")->assertUnprocessable();
    }

    public function test_admins_add_and_remove_members_and_plain_members_may_not(): void
    {
        $oic = $this->person('Marketing - OIC', 'Olive');
        $ben = $this->person('Marketing', 'Ben');
        $cara = $this->person('Marketing', 'Cara');
        $g = $this->group($oic, [$ben]);

        $this->actingAs($ben)->postJson("/chat/{$g->id}/members", ['user_ids' => [$cara->id]])->assertForbidden();
        $this->actingAs($ben)->deleteJson("/chat/{$g->id}/members/{$oic->id}")->assertForbidden();

        $this->actingAs($oic)->postJson("/chat/{$g->id}/members", ['user_ids' => [$cara->id]])->assertOk();
        $this->assertTrue(ChatMember::where(['conversation_id' => $g->id, 'user_id' => $cara->id])->whereNull('left_at')->exists());
        $this->assertTrue(ChatMessage::where('conversation_id', $g->id)->where('body', 'Olive added Cara')->exists());

        $this->actingAs($oic)->deleteJson("/chat/{$g->id}/members/{$ben->id}")->assertOk();
        $row = ChatMember::where(['conversation_id' => $g->id, 'user_id' => $ben->id])->first();
        $this->assertNotNull($row->left_at, 'the row is kept with left_at');
        $this->assertTrue(ChatMessage::where('conversation_id', $g->id)->where('body', 'Olive removed Ben')->exists());
    }

    public function test_a_removed_or_departed_member_sees_you_left_and_gets_nothing_new(): void
    {
        $oic = $this->person('Marketing - OIC', 'Olive');
        $ben = $this->person('Marketing', 'Ben');
        $g = $this->group($oic, [$ben]);
        $before = $this->message($g, $oic, 'Before you left');

        $this->actingAs($ben)->postJson("/chat/{$g->id}/leave")->assertOk();
        $this->assertTrue(ChatMessage::where('conversation_id', $g->id)->where('body', 'Ben left the group')->exists());

        $this->travel(5)->seconds();
        $after = $this->message($g, $oic, 'After you left');

        $thread = $this->actingAs($ben)->getJson("/chat/{$g->id}")->assertOk()->json();
        $this->assertTrue($thread['conversation']['me']['left']);
        $this->assertFalse($thread['conversation']['me']['can_post']);
        $ids = array_column($thread['messages'], 'id');
        $this->assertContains($before->id, $ids);
        $this->assertNotContains($after->id, $ids);

        $poll = $this->actingAs($ben)->getJson("/chat/poll?c={$g->id}&after={$before->id}")->assertOk()->json('thread.messages');
        $this->assertNotContains($after->id, array_column($poll, 'id'));

        $this->actingAs($ben)->postJson("/chat/{$g->id}/messages", ['body' => 'Still here?'])->assertForbidden();

        $row = collect($this->actingAs($ben)->getJson('/chat/conversations')->json('conversations'))->firstWhere('id', $g->id);
        $this->assertTrue($row['left']);
        $this->assertSame(0, $row['unread']);
        $this->assertSame('You left this group', $row['last']['text']);
    }

    public function test_a_removed_member_added_again_is_back(): void
    {
        $oic = $this->person('Marketing - OIC');
        $ben = $this->person();
        $g = $this->group($oic, [$ben]);
        $this->actingAs($oic)->deleteJson("/chat/{$g->id}/members/{$ben->id}")->assertOk();
        $this->actingAs($oic)->postJson("/chat/{$g->id}/members", ['user_ids' => [$ben->id]])->assertOk();

        $this->assertNull(ChatMember::where(['conversation_id' => $g->id, 'user_id' => $ben->id])->value('left_at'));
        $this->actingAs($ben)->postJson("/chat/{$g->id}/messages", ['body' => 'Back'])->assertCreated();
    }

    public function test_leaving_twice_or_a_group_you_are_not_in_fails(): void
    {
        $oic = $this->person('Marketing - OIC');
        $ben = $this->person();
        $stranger = $this->person();
        $g = $this->group($oic, [$ben]);

        $this->actingAs($ben)->postJson("/chat/{$g->id}/leave")->assertOk();
        $this->actingAs($ben)->postJson("/chat/{$g->id}/leave")->assertUnprocessable();
        $this->actingAs($stranger)->postJson("/chat/{$g->id}/leave")->assertForbidden();
    }
}
