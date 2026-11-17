<?php

namespace Tests\Feature\Nav;

use Illuminate\Support\Facades\DB;

/**
 * NV-4: pins. At most six, per user in users.nav_pins, only links the user can see; a pin to a link that is hidden
 * later is dropped silently.
 */
class NavPinsTest extends NavTestCase
{
    public function test_NV4_a_user_saves_their_own_pins_in_order(): void
    {
        $me = $this->user('Marketing');
        $ids = [$this->linkId('/ads_manager/cpp'), $this->linkId('/checklist'), $this->linkId('/ads_manager/report')];

        $this->actingAs($me)->postJson('/nav/pins', ['pins' => $ids])->assertOk()->assertExactJson(['pins' => $ids]);

        $this->assertSame($ids, $me->fresh()->nav_pins);
        $html = $this->actingAs($me->fresh())->get('/nav-probe')->getContent();
        $this->assertSame($ids, $this->pinsData($html));
    }

    public function test_NV4_seven_pins_are_refused(): void
    {
        $me = $this->user('CEO');
        $ids = DB::table('nav_links')->orderBy('id')->limit(7)->pluck('id')->all();

        $this->actingAs($me)->postJson('/nav/pins', ['pins' => $ids])->assertStatus(422);
        $this->assertNull($me->fresh()->nav_pins);

        $this->actingAs($me)->postJson('/nav/pins', ['pins' => array_slice($ids, 0, 6)])->assertOk();
        $this->assertCount(6, $me->fresh()->nav_pins);
    }

    public function test_NV4_pins_are_only_ever_the_signed_in_users_own(): void
    {
        $me = $this->user('CEO');
        $other = $this->user('CEO');
        $id = $this->linkId('/finance');

        $this->actingAs($me)->postJson('/nav/pins', ['pins' => [$id], 'user_id' => $other->id])->assertOk();

        $this->assertSame([$id], $me->fresh()->nav_pins);
        $this->assertNull($other->fresh()->nav_pins);
    }

    public function test_NV4_a_guest_cannot_pin(): void
    {
        $this->postJson('/nav/pins', ['pins' => [1]])->assertUnauthorized();
    }

    public function test_NV4_ids_of_links_the_user_cannot_see_are_dropped_and_junk_is_refused(): void
    {
        $me = $this->user('Data Encoder');
        $mine = $this->linkId('/jnt_upload');

        $this->actingAs($me)->postJson('/nav/pins', ['pins' => [$this->linkId('/finance'), $mine, 999999]])
            ->assertOk()->assertExactJson(['pins' => [$mine]]);
        $this->assertSame([$mine], $me->fresh()->nav_pins);

        $this->actingAs($me)->postJson('/nav/pins', ['pins' => ['abc']])->assertStatus(422);
        $this->actingAs($me)->postJson('/nav/pins', ['pins' => 'abc'])->assertStatus(422);
        $this->actingAs($me)->postJson('/nav/pins', ['pins' => [$mine, $mine]])->assertOk()->assertExactJson(['pins' => [$mine]]);
        $this->actingAs($me)->postJson('/nav/pins', ['pins' => []])->assertOk()->assertExactJson(['pins' => []]);
    }

    public function test_NV4_a_pin_to_a_link_hidden_later_is_dropped_silently(): void
    {
        $me = $this->user('Marketing');
        $cpp = $this->linkId('/ads_manager/cpp');
        $ads = $this->linkId('/ads_manager/report');
        $this->actingAs($me)->postJson('/nav/pins', ['pins' => [$cpp, $ads]])->assertOk();

        DB::table('nav_link_role_visibility')->where('nav_link_id', $cpp)->where('role', 'Marketing')->update(['is_visible' => false]);

        $html = $this->actingAs($me->fresh())->get('/nav-probe')->assertOk()->getContent();
        $this->assertSame([$ads], $this->pinsData($html));
    }

    public function test_NV4_view_as_shows_only_the_pins_the_previewed_role_could_see(): void
    {
        $me = $this->user('CEO');
        $this->actingAs($me)->postJson('/nav/pins', ['pins' => [$this->linkId('/finance'), $this->linkId('/jnt_upload')]])->assertOk();

        $html = $this->actingAs($me->fresh())->withSession(['nav_view_as_role' => 'Data Encoder'])->get('/nav-probe')->getContent();
        $this->assertSame([$this->linkId('/jnt_upload')], $this->pinsData($html));
    }
}
