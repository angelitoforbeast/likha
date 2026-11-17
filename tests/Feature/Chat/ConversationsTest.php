<?php

namespace Tests\Feature\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMember;
use Illuminate\Support\Facades\DB;

/** CH-1: the list of a person's conversations, direct chats (one per pair), the people search. */
class ConversationsTest extends ChatTestCase
{
    public function test_the_page_opens_for_any_signed_in_person_and_states_the_owner_can_read(): void
    {
        $ana = $this->person('Data Encoder', 'Ana Cruz');

        $this->actingAs($ana)->get('/chat')
            ->assertOk()
            ->assertSee('The owner can read every conversation in Likha Chat');
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/chat')->assertRedirect();
        $this->getJson('/chat/conversations')->assertUnauthorized();
    }

    public function test_the_list_is_newest_activity_first_with_last_line_time_and_unread(): void
    {
        $me = $this->person('Marketing', 'Ana Cruz');
        $ben = $this->person('Marketing', 'Ben Reyes');
        $cara = $this->person('Data Encoder', 'Cara Santos');

        $old = $this->direct($me, $ben);
        $this->travel(-2)->hours();
        $this->message($old, $ben, 'Old news');
        $this->travelBack();

        $new = $this->group($cara, [$me], 'Packing');
        $this->message($new, $cara, 'First');
        $this->message($new, $cara, "Second line\nwith a break");

        $rows = $this->actingAs($me)->getJson('/chat/conversations')->assertOk()->json('conversations');

        $this->assertSame([$new->id, $old->id], array_column($rows, 'id'));
        $this->assertSame('Packing', $rows[0]['title']);
        $this->assertSame('group', $rows[0]['kind']);
        $this->assertSame(2, $rows[0]['unread']);
        $this->assertSame('Cara Santos', $rows[0]['last']['sender']);
        $this->assertSame('Second line with a break', $rows[0]['last']['text']);
        $this->assertNotEmpty($rows[0]['at']);
        // Direct: the title is the other person, initials for the avatar.
        $this->assertSame('Ben Reyes', $rows[1]['title']);
        $this->assertSame('BR', $rows[1]['avatar']['initials']);
        $this->assertSame(1, $rows[1]['unread']);
    }

    public function test_own_messages_show_as_you_and_do_not_count_as_unread(): void
    {
        $me = $this->person('Marketing', 'Ana Cruz');
        $ben = $this->person('Marketing', 'Ben Reyes');
        $c = $this->direct($me, $ben);
        $this->message($c, $me, 'Mine');

        $row = $this->actingAs($me)->getJson('/chat/conversations')->json('conversations.0');
        $this->assertSame('You', $row['last']['sender']);
        $this->assertSame(0, $row['unread']);
    }

    public function test_conversations_of_others_are_not_listed(): void
    {
        $me = $this->person();
        $x = $this->person();
        $y = $this->person();
        $this->direct($x, $y);

        $this->actingAs($me)->getJson('/chat/conversations')->assertOk()->assertJsonCount(0, 'conversations');
    }

    public function test_opening_a_direct_chat_twice_returns_the_same_one_from_either_side(): void
    {
        $ana = $this->person('Marketing', 'Ana Cruz');
        $ben = $this->person('Marketing', 'Ben Reyes');

        $first = $this->actingAs($ana)->postJson('/chat/direct', ['user_id' => $ben->id])->assertOk()->json('id');
        $again = $this->actingAs($ana)->postJson('/chat/direct', ['user_id' => $ben->id])->assertOk()->json('id');
        $other = $this->actingAs($ben)->postJson('/chat/direct', ['user_id' => $ana->id])->assertOk()->json('id');

        $this->assertSame($first, $again);
        $this->assertSame($first, $other);
        $this->assertSame(1, ChatConversation::count());
        $this->assertSame(2, ChatMember::where('conversation_id', $first)->count());
    }

    public function test_no_direct_chat_with_yourself_or_an_inactive_person_or_nobody(): void
    {
        $ana = $this->person();
        $gone = $this->person('Marketing', 'Gone Person', 'Resigned');

        $this->actingAs($ana)->postJson('/chat/direct', ['user_id' => $ana->id])->assertUnprocessable();
        $this->actingAs($ana)->postJson('/chat/direct', ['user_id' => $gone->id])->assertUnprocessable();
        $this->actingAs($ana)->postJson('/chat/direct', ['user_id' => 9999])->assertUnprocessable();
        $this->assertSame(0, ChatConversation::count());
    }

    public function test_people_search_finds_active_employees_by_name_only(): void
    {
        $me = $this->person('Marketing', 'Ana Cruz');
        $this->person('Data Encoder', 'Ben Reyes', null);           // null status = active
        $this->person('Marketing', 'Benjie Lim', ' terminated ');
        $this->person('Marketing', 'Bena Ong', 'Inactive');
        $this->person('Marketing', 'Bert Go', 'Resigned');
        $this->person('Marketing - OIC', 'Benito Tan', 'Probationary');
        $this->person(null, 'Ben Noprofile', null, false);           // no employee profile: not an employee

        $names = array_column($this->actingAs($me)->getJson('/chat/people?q=ben')->assertOk()->json('people'), 'name');
        sort($names);
        $this->assertSame(['Ben Reyes', 'Benito Tan'], $names);

        $all = array_column($this->actingAs($me)->getJson('/chat/people')->json('people'), 'name');
        $this->assertNotContains('Ana Cruz', $all, 'you are not in your own search');
    }

    public function test_people_search_treats_like_wildcards_as_text(): void
    {
        $me = $this->person();
        $this->person('Marketing', 'Ben Reyes');

        $this->actingAs($me)->getJson('/chat/people?q=%25')->assertOk()->assertJsonCount(0, 'people');
        $this->actingAs($me)->getJson('/chat/people?q=_')->assertOk()->assertJsonCount(0, 'people');
    }

    public function test_a_user_with_no_employee_profile_can_open_chat_and_use_direct_chats(): void
    {
        $nobody = $this->person(null, 'Guest User', null, false);
        $ben = $this->person('Marketing', 'Ben Reyes');
        $this->assertNull(DB::table('employee_profiles')->where('user_id', $nobody->id)->first());

        $this->actingAs($nobody)->get('/chat')->assertOk();
        $id = $this->actingAs($nobody)->postJson('/chat/direct', ['user_id' => $ben->id])->assertOk()->json('id');
        $this->actingAs($nobody)->postJson("/chat/$id/messages", ['body' => 'Hi Ben'])->assertCreated();

        $row = $this->actingAs($ben)->getJson('/chat/conversations')->json('conversations.0');
        $this->assertSame('Guest User', $row['title']);
        $this->assertSame(1, $row['unread']);
    }

    public function test_the_page_deep_links_to_a_conversation(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $c = $this->direct($ana, $ben);

        $this->actingAs($ana)->get("/chat?c={$c->id}")->assertOk()->assertSee('"open":' . $c->id, false);
    }
}
