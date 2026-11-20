<?php

namespace Tests\Feature\Chat;

use App\Models\User;

/** WP-1: saving, listing and removing this device's subscription; several devices per person; your own only. */
class PushSubscriptionTest extends PushTestCase
{
    public function test_a_person_saves_lists_and_removes_a_device(): void
    {
        $ana = $this->person();
        $phone = $this->device('phone');

        $res = $this->actingAs($ana)->postJson('/chat/push', $phone)->assertCreated()->json();
        $this->assertSame(substr(hash('sha256', $phone['endpoint']), 0, 16), $res['device']['key']);
        $this->assertSame('Google', $res['device']['service']);

        $list = $this->actingAs($ana)->getJson('/chat/push')->assertOk()->json('devices');
        $this->assertCount(1, $list);
        $this->assertSame($res['device']['key'], $list[0]['key']);
        // The listing never carries the endpoint or the keys.
        $raw = $this->actingAs($ana)->getJson('/chat/push')->getContent();
        $this->assertStringNotContainsString('fcm.googleapis.com', $raw);
        $this->assertStringNotContainsString($phone['keys']['auth'], $raw);

        $this->actingAs($ana)->deleteJson('/chat/push', ['endpoint' => $phone['endpoint']])->assertOk()->assertJson(['removed' => true]);
        $this->assertSame([], $this->actingAs($ana)->getJson('/chat/push')->json('devices'));
        $this->assertSame([], (array) User::find($ana->id)->chat_push);
    }

    public function test_one_person_keeps_several_devices_and_saving_the_same_one_twice_keeps_one(): void
    {
        $ana = $this->person();
        $phone = $this->device('phone');
        $pc = $this->device('pc', 'updates.push.services.mozilla.com', '/wpush/v2/');

        $this->subscribe($ana, $phone);
        $this->subscribe($ana, $pc);
        $this->actingAs($ana)->postJson('/chat/push', $phone)->assertOk(); // again: updated, not added

        $devices = $this->actingAs($ana)->getJson('/chat/push')->json('devices');
        $this->assertCount(2, $devices);
        $this->assertEqualsCanonicalizing(['Google', 'Mozilla'], array_column($devices, 'service'));

        $this->actingAs($ana)->deleteJson('/chat/push', ['endpoint' => $phone['endpoint']])->assertOk();
        $devices = $this->actingAs($ana)->getJson('/chat/push')->json('devices');
        $this->assertSame(['Mozilla'], array_column($devices, 'service'));
    }

    public function test_another_user_cannot_remove_your_device(): void
    {
        $ana = $this->person();
        $ben = $this->person();
        $phone = $this->device('phone');
        $this->subscribe($ana, $phone);

        $this->actingAs($ben)->deleteJson('/chat/push', ['endpoint' => $phone['endpoint']])->assertOk()->assertJson(['removed' => false]);
        $this->assertCount(1, $this->actingAs($ana)->getJson('/chat/push')->json('devices'));
        $this->assertSame([], $this->actingAs($ben)->getJson('/chat/push')->json('devices'));
    }

    public function test_the_same_browser_saved_by_a_second_person_moves_to_that_person(): void
    {
        // A shared office PC: Ana turned alerts on, then Ben signed in on the same browser and turned them on.
        $ana = $this->person();
        $ben = $this->person();
        $pc = $this->device('office-pc');
        $this->subscribe($ana, $pc);
        $this->subscribe($ben, $pc);

        $this->assertSame([], $this->actingAs($ana)->getJson('/chat/push')->json('devices'));
        $this->assertCount(1, $this->actingAs($ben)->getJson('/chat/push')->json('devices'));
    }

    public function test_at_most_ten_devices_and_the_oldest_drops(): void
    {
        $ana = $this->person();
        for ($i = 1; $i <= 11; $i++) {
            $this->subscribe($ana, $this->device("d$i"));
            $this->travel(1)->minutes();
        }
        $keys = array_column($this->actingAs($ana)->getJson('/chat/push')->json('devices'), 'key');
        $this->assertCount(10, $keys);
        $this->assertNotContains(substr(hash('sha256', $this->device('d1')['endpoint']), 0, 16), $keys);
    }

    public function test_signing_out_keeps_the_devices(): void
    {
        $ana = $this->person();
        $this->subscribe($ana, $this->device('phone'));
        $this->actingAs($ana)->post('/logout');
        $this->assertCount(1, (array) User::find($ana->id)->chat_push);
    }

    public function test_guests_get_401_on_every_push_route(): void
    {
        $this->getJson('/chat/push')->assertUnauthorized();
        $this->postJson('/chat/push', $this->device())->assertUnauthorized();
        $this->deleteJson('/chat/push', ['endpoint' => $this->device()['endpoint']])->assertUnauthorized();
    }

    public function test_without_keys_the_push_routes_are_404_and_save_nothing(): void
    {
        $this->pushOff();
        $ana = $this->person();
        $this->actingAs($ana)->getJson('/chat/push')->assertNotFound();
        $this->actingAs($ana)->postJson('/chat/push', $this->device())->assertNotFound();
        $this->actingAs($ana)->deleteJson('/chat/push', ['endpoint' => $this->device()['endpoint']])->assertNotFound();
        $this->assertNull(User::find($ana->id)->chat_push);
    }
}
