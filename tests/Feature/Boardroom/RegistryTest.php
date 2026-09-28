<?php

namespace Tests\Feature\Boardroom;

use App\Boardroom\Orchestrator\MeetingOrchestrator;
use App\Boardroom\Orchestrator\Registry;
use App\Models\Boardroom\Change;
use App\Models\Boardroom\Lesson;
use App\Models\Boardroom\Message;
use App\Models\Boardroom\PaymentAccount;
use App\Models\Boardroom\ResourceEntry;
use Illuminate\Support\Facades\DB;

/**
 * MOCKED — resources registry na hawak ng AI. Walang totoong model call.
 *
 * Scripted ang sagot ng "AI" dito, kaya HINDI nito sinusukat kung gaano kahusay humati ng pangalan o
 * pumansin ng datos ang totoong model. Ang sinusubok ay ang mga pananggalang ng backend.
 */
class RegistryTest extends BoardroomTestCase
{
    private function ask($meeting, $user, string $body, int $cycles = 0): array
    {
        return app(MeetingOrchestrator::class)->postUserMessage($meeting, $user->id, $body, $cycles);
    }

    private function reply(string $answer, array $changes = [], ?array $lesson = null): string
    {
        return json_encode(['answer' => $answer, 'lesson' => $lesson, 'changes' => $changes], JSON_UNESCAPED_UNICODE);
    }

    private function change(array $fields): array
    {
        return $fields + [
            'op' => 'add', 'target' => 'resource', 'id' => null, 'type' => null, 'name' => null, 'purpose' => null, 'tags' => [],
            'location' => null, 'parent_id' => null, 'parent_name' => null, 'details' => null, 'holder' => null,
            'method' => null, 'account_name' => null, 'account_number' => null, 'notes' => null,
        ];
    }

    private function resource($user, array $fields): ResourceEntry
    {
        return ResourceEntry::create($fields + ['user_id' => $user->id, 'type' => 'contact', 'status' => 'active', 'source' => 'manual']);
    }

    /** Ang halimbawa ng user: "@CEO pag maghahanap ka ng supplier, sa Messenger. Eto ang mga names…" */
    public function test_the_ai_records_resources_from_chat_and_every_role_sees_them(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider(null, ['direct' => fn () => $this->reply('Naitala ko: Messenger, at sina Juan at Pedro.', [
            $this->change(['type' => 'channel', 'name' => 'Messenger', 'purpose' => 'Paghahanap at pakikipag-usap sa supplier', 'tags' => ['Supplier', 'supplier ']]),
            $this->change(['type' => 'contact', 'name' => 'Juan dela Cruz', 'purpose' => 'Supplier', 'parent_name' => 'Messenger']),
            $this->change(['type' => 'contact', 'name' => 'Pedro Santos', 'purpose' => 'Supplier', 'parent_name' => 'messenger']),
        ], ['applies_when' => 'Kapag naghahanap ng supplier', 'rule' => 'Sa Messenger hanapin.', 'replaces_lesson_id' => null])]);

        $m      = $this->start($this->meeting($user));
        $before = count($this->calls);
        $asked  = $this->ask($m, $user, "@CEO pag maghahanap ka ng supplier, sa Messenger. Eto ang mga names:\nJuan dela Cruz\nPedro Santos");

        $this->assertCount($before + 1, $this->calls, 'Iisang call: sagot, aral, at datos.');

        $messenger = ResourceEntry::where('type', 'channel')->firstOrFail();
        $this->assertSame(['supplier'], $messenger->tags, 'Nililinis at hindi dinodoble ang tags.');
        $contacts = ResourceEntry::where('type', 'contact')->orderBy('id')->get();
        $this->assertSame(['Juan dela Cruz', 'Pedro Santos'], $contacts->pluck('name')->all());
        foreach ($contacts as $c) {
            $this->assertSame($messenger->id, (int) $c->parent_id, 'Naka-kabit ang contact sa channel na kadaragdag lang.');
            $this->assertSame('chat', $c->source);
            $this->assertSame($this->agent('CEO')->id, (int) $c->agent_id);
            $this->assertSame($asked['message']->id, (int) $c->message_id);
            $this->assertNull($c->project_id);
        }

        // Ang patakaran ay sa playbook ng CEO; ang datos ay sa registry
        $this->assertSame('Sa Messenger hanapin.', Lesson::firstOrFail()->rule);

        // Paalala sa chat + tala ng bawat pagbabago
        $notice = Message::where('meeting_id', $m->id)->where('kind', 'changes')->firstOrFail();
        $this->assertStringContainsString('Itinala ni CEO sa Resources', $notice->body);
        $this->assertStringContainsString('Idinagdag: [contact] Juan dela Cruz', $notice->body);
        $this->assertCount(3, $notice->meta['change_ids']);
        $this->assertSame(['add', 'add', 'add'], Change::orderBy('id')->pluck('action')->all());
        $this->assertSame(['ai'], Change::distinct()->pluck('actor')->all());

        // SUSUNOD NA MEETING: nakikita ng LAHAT ng role, kasama ang id
        $this->calls = [];
        $next = $this->start($this->meeting($user, ['title' => 'Supply'], $this->project($user, 'Ibang project')));
        $this->assertSame('completed', $next->status, (string) $next->last_error);
        $seen = [];
        foreach ($this->calls as $call) {
            $this->assertStringContainsString("resource_id={$contacts[0]->id} [contact] Juan dela Cruz — belongs to: Messenger (#{$messenger->id})", $call['body']['input']);
            $seen[$call['handle']] = true;
        }
        $this->assertCount(4, $seen);

        // Magkapareho pa rin ang context ng mga proposal
        $proposals = $this->callsFor('proposal');
        $this->assertSame($proposals[0]['body']['input'], $proposals[1]['body']['input']);
    }

    /** Edit at archive gamit ang id; walang "delete" para sa AI; bawal ang id na hindi sa user. */
    public function test_the_ai_can_edit_and_archive_but_never_delete(): void
    {
        $user  = $this->user();
        $other = $this->user('CEO', 'other@example.test');
        $this->giveKeys();

        $juan    = $this->resource($user, ['name' => 'Juan', 'purpose' => 'Supplier ng fan']);
        $pedro   = $this->resource($user, ['name' => 'Pedro']);
        $ana     = $this->resource($user, ['name' => 'Ana']);
        $foreign = $this->resource($other, ['name' => 'Hindi sa akin']);

        $this->fakeProvider(null, ['direct' => fn () => $this->reply('Ayos.', [
            $this->change(['op' => 'edit', 'id' => $juan->id, 'purpose' => 'Supplier ng fan at blower']),
            $this->change(['op' => 'archive', 'id' => $pedro->id]),
            ['op' => 'delete', 'target' => 'resource', 'id' => $ana->id, 'name' => 'Ana'],
            $this->change(['op' => 'edit', 'id' => $foreign->id, 'name' => 'Na-hack']),
            $this->change(['op' => 'archive', 'id' => 999999]),
        ])]);
        $m = $this->start($this->meeting($user));
        $this->ask($m, $user, '@CEO si Juan ay supplier na rin ng blower. Hindi na natin supplier si Pedro. Tanggalin si Ana.');

        $this->assertSame('Supplier ng fan at blower', $juan->fresh()->purpose);
        $this->assertSame('Juan', $juan->fresh()->name, 'Ang field na hindi binanggit ay hindi ginagalaw.');

        $this->assertSame('archived', $pedro->fresh()->status);
        $this->assertNotNull(ResourceEntry::find($pedro->id), 'Archive, hindi bura: nasa table pa rin.');

        $this->assertSame('active', $ana->fresh()->status, 'Walang delete para sa AI.');
        $this->assertSame('Hindi sa akin', $foreign->fresh()->name);
        $this->assertSame('active', $foreign->fresh()->status);

        $notice = Message::where('kind', 'changes')->firstOrFail();
        $this->assertCount(2, $notice->meta['change_ids']);
        $this->assertCount(3, $notice->meta['rejected']);
        $this->assertStringContainsString('hindi pwedeng magbura ang AI', $notice->body);

        // Tala: dati → bago
        $edit = Change::where('action', 'edit')->firstOrFail();
        $this->assertSame('Supplier ng fan', $edit->before['purpose']);
        $this->assertSame('Supplier ng fan at blower', $edit->after['purpose']);

        // Ang naka-archive ay hindi na ipinapadala sa mga role
        $block = app(Registry::class)->block($user->id, null);
        $this->assertStringContainsString('Juan', $block);
        $this->assertStringNotContainsString('Pedro', $block);
        $this->assertStringNotContainsString('Hindi sa akin', $block);
    }

    /** Parehong pangalan (iba lang ang laki ng titik o bantas) ay hindi dinodoble. */
    public function test_an_existing_record_is_not_added_twice(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->resource($user, ['name' => 'Juan dela Cruz']);
        $this->fakeProvider(null, ['direct' => fn () => $this->reply('Ok.', [
            $this->change(['type' => 'contact', 'name' => 'JUAN  Dela-Cruz.']),
            $this->change(['type' => 'contact', 'name' => 'Maria Reyes']),
            $this->change(['type' => 'contact', 'name' => 'maria reyes']),
        ])]);
        $m = $this->start($this->meeting($user));

        $this->ask($m, $user, '@CEO idagdag sina Juan dela Cruz at Maria Reyes.');

        $this->assertSame(['Juan dela Cruz', 'Maria Reyes'], ResourceEntry::orderBy('id')->pluck('name')->all());
        $this->assertStringContainsString('meron na', Message::where('kind', 'changes')->value('body'));
    }

    /** Iisang role lang ang tagatala kada message: ang UNANG na-mention. */
    public function test_only_the_first_mentioned_role_records_data(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider(null, ['direct' => fn ($handle) => $this->reply("Sagot ni {$handle}", [
            $this->change(['type' => 'contact', 'name' => "Itinala ni {$handle}"]),
        ])]);
        $m      = $this->start($this->meeting($user));
        $before = count($this->calls);

        $this->ask($m, $user, '@CTO @CEO idagdag ang bagong supplier.');

        $this->assertSame(['Itinala ni CTO'], ResourceEntry::pluck('name')->all());
        $this->assertSame(1, Message::where('kind', 'changes')->count());

        $calls = collect(array_slice($this->calls, $before))->keyBy('handle');
        $this->assertStringContainsString('If the user\'s message gives data to record', $calls['CTO']['body']['input']);
        $this->assertStringContainsString('another participant records data', $calls['CEO']['body']['input']);
    }

    /** Account number: ise-save lang kung EKSAKTONG nasa message ng user; naka-encrypt; naka-mask sa AI. */
    public function test_account_numbers_must_match_the_users_message_exactly(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $juan = $this->resource($user, ['name' => 'Juan dela Cruz']);

        $number = '09171234567';
        $this->fakeProvider(null, ['direct' => function () use (&$number, $juan) {
            return $this->reply("Naitala ko ang GCash {$number} ni Juan.", [
                $this->change(['target' => 'account', 'parent_id' => $juan->id, 'method' => 'GCash', 'account_name' => 'Juan D. Cruz', 'account_number' => $number]),
            ]);
        }]);
        $m = $this->start($this->meeting($user));

        // MALI ang isang digit sa ibinalik ng AI → hindi ise-save
        $number = '09171234568';
        $wrong  = $this->ask($m, $user, '@CEO ang GCash ni Juan dela Cruz ay 0917 123 4567, pangalan Juan D. Cruz.');
        $this->assertSame(0, PaymentAccount::count());
        $this->assertStringContainsString('hindi tugma ang account number sa message mo', Message::where('kind', 'changes')->orderByDesc('id')->value('body'));
        $this->assertStringContainsString('0917 123 4567', $wrong['message']->fresh()->body, 'Walang na-save, kaya hindi ginalaw ang message.');

        // TAMA (iba lang ang espasyo) → ise-save
        $number = '09171234567';
        $right  = $this->ask($m, $user, '@CEO ulitin ko: ang GCash ni Juan dela Cruz ay 0917-123-4567.');

        $account = PaymentAccount::firstOrFail();
        $this->assertSame($juan->id, (int) $account->resource_id);
        $this->assertSame('GCash', $account->method);
        $this->assertSame('4567', $account->last4);
        $this->assertSame('09171234567', PaymentAccount::normalize($account->number()));

        // Naka-encrypt sa database
        $raw = DB::table('br_payment_accounts')->first();
        $this->assertStringNotContainsString('0917', (string) $raw->account_number_encrypted);
        $this->assertArrayNotHasKey('account_number_encrypted', $account->toArray());

        // Naka-mask sa message ng user, sa sagot ng role, sa paalala, at sa tala
        $this->assertSame('@CEO ulitin ko: ang GCash ni Juan dela Cruz ay ****4567.', $right['message']->fresh()->body);
        $this->assertSame('Naitala ko ang GCash ****4567 ni Juan.', Message::where('kind', 'answer')->orderByDesc('id')->value('body'));
        $this->assertStringContainsString('GCash ****4567 ni Juan dela Cruz', Message::where('kind', 'changes')->orderByDesc('id')->value('body'));
        foreach (['br_changes', 'br_resources', 'br_lessons'] as $table) {
            $this->assertStringNotContainsString('09171234567', json_encode(DB::table($table)->get()));
        }

        // Sa mga SUSUNOD na call: huling 4 na digit lang ang nakikita ng AI — mula sa registry at mula sa na-save na message
        $start = count($this->calls);
        $this->ask($m, $user, '@CTO may tanong ako tungkol sa bayad.');
        $later = array_slice($this->calls, $start)[0]['body'];
        $this->assertStringContainsString("account_id={$account->id} GCash — Juan D. Cruz — ****4567", $later['input']);
        $this->assertStringNotContainsString('09171234567', json_encode($later));
        $this->assertStringNotContainsString('0917-123-4567', json_encode($later));
    }

    /** Hindi tinatanggap ang numerong bahagi lang ng mas mahabang numero, o hindi mukhang account number. */
    public function test_account_number_guard_rejects_partial_and_malformed_numbers(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $juan = $this->resource($user, ['name' => 'Juan']);

        $given = '';
        $this->fakeProvider(null, ['direct' => function () use (&$given, $juan) {
            return $this->reply('Ok.', [$this->change(['target' => 'account', 'parent_id' => $juan->id, 'method' => 'BDO', 'account_number' => $given])]);
        }]);
        $m = $this->start($this->meeting($user));

        foreach (['123456' /* bahagi lang ng 0012345678 */, '12 34' /* masyadong maikli */, '0012345678; DROP' /* may ibang character */] as $given) {
            $this->ask($m, $user, '@CEO ang BDO ni Juan ay 0012345678.');
        }
        $this->assertSame(0, PaymentAccount::count());

        $given = '0012345678';
        $this->ask($m, $user, '@CEO ang BDO ni Juan ay 0012345678.');
        $this->assertSame(1, PaymentAccount::count());

        // Parehong numero ulit → hindi nadodoble
        $this->ask($m, $user, '@CEO ulit: BDO ni Juan 0012345678.');
        $this->assertSame(1, PaymentAccount::count());
    }

    /** Password, PIN, OTP, at CVV ay hindi itinatala. */
    public function test_passwords_pins_and_otps_are_never_recorded(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $juan = $this->resource($user, ['name' => 'Juan']);
        $this->fakeProvider(null, ['direct' => fn () => $this->reply('Ok.', [
            $this->change(['target' => 'account', 'parent_id' => $juan->id, 'method' => 'GCash MPIN', 'account_number' => '123456']),
            $this->change(['type' => 'link', 'name' => 'Admin login', 'details' => 'password: hunter2secret']),
            $this->change(['type' => 'link', 'name' => 'Pinned message ng GC', 'details' => 'Nandito ang presyo.']),
        ])]);
        $m = $this->start($this->meeting($user));

        $this->ask($m, $user, '@CEO ang MPIN ay 123456. Ang password ng admin ay hunter2secret. Nasa pinned message ng GC ang presyo.');

        $this->assertSame(0, PaymentAccount::count());
        $this->assertSame(['Juan', 'Pinned message ng GC'], ResourceEntry::orderBy('id')->pluck('name')->all(), 'Ang salitang "pinned" ay hindi PIN.');
        $this->assertStringNotContainsString('hunter2secret', json_encode(DB::table('br_resources')->get()));
    }

    /** Undo: dagdag → archive; edit → ibalik ang dati; bawal kung may mas bagong pagbabago. */
    public function test_changes_can_be_undone(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $juan = $this->resource($user, ['name' => 'Juan', 'purpose' => 'Una']);
        $step = 1;
        $this->fakeProvider(null, ['direct' => function () use (&$step, $juan) {
            return $this->reply('Ok.', $step === 1
                ? [$this->change(['type' => 'contact', 'name' => 'Bagong Contact']), $this->change(['op' => 'edit', 'id' => $juan->id, 'purpose' => 'Pangalawa'])]
                : [$this->change(['op' => 'edit', 'id' => $juan->id, 'purpose' => 'Pangatlo'])]);
        }]);
        $m = $this->start($this->meeting($user));

        $this->ask($m, $user, '@CEO idagdag si Bagong Contact; si Juan ay Pangalawa na.');
        [$added, $edited] = Change::orderBy('id')->get()->all();

        // Undo ng dagdag, mula sa chat
        $state = $this->actingAs($user)->postJson("/boardroom/api/changes/{$added->id}/undo", ['meeting_id' => $m->id])->assertOk();
        $this->assertSame('archived', ResourceEntry::where('name', 'Bagong Contact')->value('status'));
        $row = collect($state->json('state.messages'))->firstWhere('kind', 'changes');
        $this->assertTrue(collect($row['changes'])->firstWhere('id', $added->id)['undone']);
        $this->assertFalse(collect($row['changes'])->firstWhere('id', $edited->id)['undone']);
        $this->actingAs($user)->postJson("/boardroom/api/changes/{$added->id}/undo")->assertStatus(422);

        // May mas bagong edit kay Juan → bawal i-undo ang luma
        $step = 2;
        $this->ask($m, $user, '@CEO si Juan ay Pangatlo na.');
        $this->actingAs($user)->postJson("/boardroom/api/changes/{$edited->id}/undo")->assertStatus(422)
            ->assertJsonPath('message', 'May mas bagong pagbabago sa record na ito. I-edit na lang sa Resources page.');
        $this->assertSame('Pangatlo', $juan->fresh()->purpose);

        // Ang pinakabago ay pwede
        $latest = Change::orderByDesc('id')->first();
        $this->actingAs($user)->postJson("/boardroom/api/changes/{$latest->id}/undo")->assertOk();
        $this->assertSame('Pangalawa', $juan->fresh()->purpose);
    }

    /** Hindi JSON ang sagot, o sobrang dami ng pagbabago. */
    public function test_plain_answers_change_nothing_and_batches_are_capped(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $many = [];
        for ($n = 1; $n <= 25; $n++) {
            $many[] = $this->change(['type' => 'contact', 'name' => "Contact {$n}"]);
        }
        $plain = true;
        $this->fakeProvider(null, ['direct' => function () use (&$plain, $many) {
            return $plain ? 'Plain na sagot lang, idagdag si Juan.' : $this->reply('Ok.', $many);
        }]);
        $m = $this->start($this->meeting($user));

        $this->ask($m, $user, '@CEO idagdag si Juan.');
        $this->assertSame(0, ResourceEntry::count());
        $this->assertSame(0, Message::where('kind', 'changes')->count());

        $plain = false;
        $this->ask($m, $user, '@CEO idagdag ang 25 contact.');
        $this->assertSame(Registry::MAX_CHANGES, ResourceEntry::count());
        $this->assertStringContainsString('Sobra sa 20', Message::where('kind', 'changes')->value('body'));
    }

    /** Resources page: CRUD, tuluyang pagbura (user lang), Ipakita, at access. */
    public function test_the_user_manages_resources_and_only_the_owner_can_see_them(): void
    {
        $owner = $this->user('CEO', 'owner@example.test');
        $other = $this->user('CEO', 'other@example.test');
        $staff = $this->user('Encoder', 'staff@example.test');

        $this->actingAs($owner)->get('/boardroom/resources')->assertOk();
        $this->actingAs($staff)->get('/boardroom/resources')->assertStatus(403);
        $this->actingAs($staff)->getJson('/boardroom/api/resources')->assertStatus(403);

        $this->actingAs($owner)->postJson('/boardroom/api/resources', [
            'type' => 'contact', 'name' => 'Juan dela Cruz', 'purpose' => 'Supplier ng fan', 'tags' => ['Supplier', 'fan'],
        ])->assertOk()->assertJsonPath('resources.0.source', 'manual');
        $juan = ResourceEntry::firstOrFail();

        $this->actingAs($owner)->postJson("/boardroom/api/resources/{$juan->id}/accounts", [
            'method' => 'BDO', 'account_name' => 'Juan D. Cruz', 'account_number' => '0012-3456-7890',
        ])->assertOk();
        $account = PaymentAccount::firstOrFail();

        // Listahan: naka-mask; walang buong numero at walang encrypted value
        $list = $this->actingAs($owner)->getJson('/boardroom/api/resources')->assertOk();
        $this->assertSame('****7890', $list->json('resources.0.accounts.0.masked'));
        foreach (['0012-3456-7890', '001234567890', 'account_number_encrypted', (string) $account->account_number_encrypted] as $secret) {
            $this->assertStringNotContainsString($secret, $list->getContent());
        }
        $this->assertStringNotContainsString('001234567890', json_encode(DB::table('br_changes')->get()));

        // Ipakita: sa may-ari lang, at hindi naka-cache
        $reveal = $this->actingAs($owner)->postJson("/boardroom/api/accounts/{$account->id}/reveal")->assertOk();
        $this->assertSame('0012-3456-7890', $reveal->json('account_number'));
        $this->assertStringContainsString('no-store', (string) $reveal->headers->get('Cache-Control'));
        $this->actingAs($other)->postJson("/boardroom/api/accounts/{$account->id}/reveal")->assertStatus(404);
        $this->actingAs($staff)->postJson("/boardroom/api/accounts/{$account->id}/reveal")->assertStatus(403);

        // Ang iba ay walang nakikita at walang nagagalaw
        $this->assertSame([], $this->actingAs($other)->getJson('/boardroom/api/resources')->json('resources'));
        $this->actingAs($other)->putJson("/boardroom/api/resources/{$juan->id}", ['type' => 'contact', 'name' => 'Binago ng iba'])->assertStatus(404);
        $this->actingAs($other)->deleteJson("/boardroom/api/resources/{$juan->id}")->assertStatus(404);

        // Edit na walang bagong numero: hindi nababago ang numero
        $this->actingAs($owner)->putJson("/boardroom/api/accounts/{$account->id}", ['method' => 'BDO Savings', 'account_name' => 'Juan D. Cruz'])->assertOk();
        $this->assertSame('0012-3456-7890', $account->fresh()->number());
        $this->assertSame('BDO Savings', $account->fresh()->method);

        // Archive, ibalik, tapos tuluyang pagbura (kasama ang mga account)
        $this->actingAs($owner)->postJson("/boardroom/api/resources/{$juan->id}/archive")->assertOk();
        $this->assertSame('archived', $juan->fresh()->status);
        $this->actingAs($owner)->postJson("/boardroom/api/resources/{$juan->id}/archive", ['restore' => true])->assertOk();
        $this->assertSame('active', $juan->fresh()->status);

        $this->actingAs($owner)->deleteJson("/boardroom/api/resources/{$juan->id}")->assertOk()->assertJsonPath('resources', []);
        $this->assertSame(0, ResourceEntry::count());
        $this->assertSame(0, PaymentAccount::count());
        $this->assertSame('Ikaw', $this->actingAs($owner)->getJson('/boardroom/api/resources')->json('history.0.actor'));
    }
}
