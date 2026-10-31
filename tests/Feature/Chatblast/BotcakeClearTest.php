<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\PageSetting;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Spec 206, Connections → Botcake → Clear: ang Botcake page ID, token at chat key ng isang page lang ang inaalis (may
 * user), para mapalaya ang ID ng dobleng row. Walang tawag sa Botcake; ang On/Off at ang chat block ay hindi ginagalaw.
 */
class BotcakeClearTest extends ChatblastTestCase
{
    private const ID = '1085660977960440';
    private const REAL = 'Alaga Natural Collective';
    private const DUP = '4RO Alaga Natural Collective';
    private const DUP_TOKEN = 'tok-duplicate-row-test-7r4q';
    private $ceo;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_20_100000_create_botcake_chats_table.php']]);
        config(['app.url' => 'https://app.example.test/']);
        $s = $this->settings();
        // Ang live case: ang tunay na row, saka ang doble 4 na segundo pagkatapos, parehong Botcake page ID.
        $this->travelTo('2026-10-31 09:00:00');
        $this->page(self::REAL, self::ID);
        $s->set(self::REAL, 'botcake', 'chat.key', 'real-chat-key-1111', true, null, true);
        $this->travelTo('2026-10-31 09:00:04');
        $s->set(self::DUP, 'botcake', 'page_id', self::ID);
        $s->set(self::DUP, 'botcake', 'token', self::DUP_TOKEN, true);
        $s->set(self::DUP, 'botcake', 'chat.key', 'dup-chat-key-2222', true, null, true);
        $s->set(self::DUP, 'chatbot', 'active', '1', false, null, true);
        $s->set(self::DUP, 'botcake', 'chat_block', 'on 2026-10-31 09:00:04', false, null, true);
        $this->travelTo('2026-10-31 10:00:00');
        $this->actingAs($this->ceo = $this->user());
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    private function clear(string $page)
    {
        return $this->postJson('/chatbot/connections/botcake-clear', ['page_name' => $page, 'page_id' => self::ID]); // gaya ng popup: ang kinumpirmang ID
    }

    /** Active na value ng page + provider + key, mula sa table (hindi sa cache). */
    private function activeValue(string $page, string $provider, string $key): ?string
    {
        return $this->settings()->pageQuery($page)->where('provider', $provider)->where('key', $key)->where('active', true)->orderByDesc('id')->first()?->plainValue();
    }

    public function test_S_206_4_clearing_the_duplicate_frees_the_id_for_the_real_page(): void
    {
        Http::fake(['botcake.io/*' => Http::response(['success' => true, 'data' => [['id' => 7701, 'name' => 'message']], 'blocks' => [], 'settings' => ['is_published' => true]])]);
        $this->assertSame(self::DUP, BotcakeChat::pageMap()[self::ID], 'before: the later row wins');
        $this->postJson('/chatbot/connections/botcake', ['page_name' => self::REAL, 'page_id' => self::ID, 'token' => self::TOKEN])
            ->assertStatus(422)->assertJsonPath('error', 'Botcake ID ' . self::ID . ' is already used by ' . self::DUP . '.');

        // Lumang popup na ibang ID ang kinumpirma: walang binubura.
        $this->postJson('/chatbot/connections/botcake-clear', ['page_name' => self::DUP, 'page_id' => '999'])->assertStatus(422);
        // Walang kinumpirmang ID (script): walang binubura rin.
        $this->postJson('/chatbot/connections/botcake-clear', ['page_name' => self::DUP])->assertStatus(422);
        $this->assertSame(self::ID, $this->activeValue(self::DUP, 'botcake', 'page_id'));

        $this->clear(self::DUP)->assertOk()->assertJson(['status' => 'cleared', 'connections' => ['page' => self::DUP, 'botcake' => ['page_id' => '', 'token' => null, 'connected' => false]]]);

        foreach (['page_id', 'token', 'chat.key'] as $key) {
            $this->assertNull($this->activeValue(self::DUP, 'botcake', $key), self::DUP . ' ' . $key);
            $this->assertSame($this->ceo->id, (int) PageSetting::where('page_name', self::DUP)->where('provider', 'botcake')->where('key', $key)->sole()->replaced_by, $key . ' cleared by the user');
        }
        $this->assertSame(self::ID, $this->activeValue(self::REAL, 'botcake', 'page_id'));
        $this->assertSame(self::TOKEN, $this->activeValue(self::REAL, 'botcake', 'token'));
        $this->assertSame('real-chat-key-1111', $this->activeValue(self::REAL, 'botcake', 'chat.key'));
        $this->assertSame(self::REAL, BotcakeChat::pageMap()[self::ID], 'after: the real row');

        $save = $this->postJson('/chatbot/connections/botcake', ['page_name' => self::REAL, 'page_id' => self::ID, 'token' => self::TOKEN]);
        $this->assertStringNotContainsString('already used by', $save->getContent());
        $save->assertOk()->assertJsonPath('status', 'ok');

        // Ang Changes ng doble: ang Botcake ID na na-clear, ng user.
        $entries = collect($this->getJson('/chatbot/changes?page=' . rawurlencode(self::DUP))->assertOk()->json('entries'));
        $this->assertContains(['Botcake ID', $this->ceo->name, '(none)'], $entries->map(fn ($e) => [$e['field'], $e['by'], $e['new']])->all());
    }

    public function test_S_206_5_the_clear_calls_nothing_and_leaves_the_bot_switch_and_the_chat_block_as_they_are(): void
    {
        Http::fake();
        $this->clear(self::DUP)->assertOk();

        Http::assertNothingSent();
        $this->assertSame('1', $this->activeValue(self::DUP, 'chatbot', 'active'));
        $this->assertSame('on 2026-10-31 09:00:04', $this->activeValue(self::DUP, 'botcake', 'chat_block'));
        $this->assertSame(1, PageSetting::where('page_name', self::DUP)->where('key', 'chat_block')->count(), 'no new chat block row');
    }

    public function test_S_206_6_the_full_token_never_reaches_the_answer_or_the_page(): void
    {
        Http::fake();
        $res = $this->clear(self::DUP)->assertOk();
        $before = $this->getJson('/chatbot/connections?page_name=' . rawurlencode(self::REAL))->assertOk();
        $html = $this->get('/chatbot/pages')->assertOk()->getContent();

        foreach ([$res->getContent(), $before->getContent(), $html] as $body) {
            foreach ([self::TOKEN, self::DUP_TOKEN, 'real-chat-key-1111', 'dup-chat-key-2222'] as $secret) $this->assertStringNotContainsString($secret, $body);
        }
        $this->assertSame('••••' . substr(self::TOKEN, -4), $before->json('botcake.token'));
    }

    /** Ang helper ng popup (botcake-clear, mula sa rendered HTML) sa Node. */
    private function popup(string $js): mixed
    {
        $html = $this->get('/chatbot/pages')->assertOk()->getContent();
        $this->assertSame(1, preg_match('~// botcake-clear:start(.*?)// botcake-clear:end~s', $html, $m), 'the helper is on the page');
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) $this->markTestSkipped('Node is not installed.');
        $base = tempnam(sys_get_temp_dir(), 'bcc');
        $file = $base . '.mjs';
        file_put_contents($file, $m[1] . "\nconsole.log(JSON.stringify(" . $js . '));');
        $p = new Process([$node, $file]);
        $p->run();
        @unlink($file);
        @unlink($base);
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());

        return json_decode($p->getOutput(), true);
    }

    public function test_S_206_4_the_confirm_names_the_page_and_the_id_and_says_when_the_bot_is_on(): void
    {
        $html = $this->get('/chatbot/pages')->assertOk()->getContent();
        $this->assertSame(1, preg_match('~<form class="conn-card[^"]*" data-provider="botcake".*?</form>~s', $html, $card));
        $this->assertStringContainsString('class="conn-clear cb-btn cb-btn-sm">Clear</button>', $card[0], 'same look as the Forbeast Clear');

        [$on, $off] = $this->popup('[botcakeClearQuestion(' . json_encode(self::DUP) . ', ' . json_encode(self::ID) . ', true), botcakeClearQuestion(' . json_encode(self::DUP) . ', ' . json_encode(self::ID) . ', false)]');
        $this->assertSame('Remove Botcake page ID ' . self::ID . ' from ' . self::DUP . '? This page stops receiving Botcake chats here. Botcake itself is not changed.', $off);
        $this->assertSame($off . "\nThe bot of this page is On: it stays On, with no Botcake chats coming in here.", $on);
    }

    public function test_S_206_4_the_confirm_names_the_gateways_that_stop_too(): void
    {
        Http::fake();
        $s = $this->settings();
        $gateways = fn () => $this->getJson('/chatbot/connections?page_name=' . rawurlencode(self::DUP))->assertOk()->json('botcake.gateways');
        $this->assertSame([], $gateways(), 'no gateway token');
        $s->set(self::DUP, 'forbeast', 'token', 'fbp-dup-token-test-1x2y', true);
        $this->assertSame(['Forbeast'], $gateways());
        $s->set(self::DUP, 'incepxionbot', 'token', 'ixb-dup-token-test-3z4w', true);
        $this->assertSame(['Forbeast', 'Incepxionbot'], $gateways());

        $q = fn (array $gw) => 'botcakeClearQuestion(' . json_encode(self::DUP) . ', ' . json_encode(self::ID) . ', false, ' . json_encode($gw) . ')';
        [$none, $forbeast, $both] = $this->popup('[' . $q([]) . ', ' . $q(['Forbeast']) . ', ' . $q(['Forbeast', 'Incepxionbot']) . ']');
        $base = 'Remove Botcake page ID ' . self::ID . ' from ' . self::DUP . '? This page stops receiving Botcake chats here. Botcake itself is not changed.';
        $this->assertSame($base, $none);
        $this->assertSame($base . "\nForbeast also stops for this page: it finds its chats by this Botcake page ID.", $forbeast);
        $this->assertSame($base . "\nForbeast and Incepxionbot also stop for this page: they find its chats by this Botcake page ID.", $both);
    }

    public function test_S_206_7_no_botcake_id_no_clear(): void
    {
        $this->assertSame([true, false, false], $this->popup('[botcakeClearShown({page_id: ' . json_encode(self::ID) . '}), botcakeClearShown({page_id: ""}), botcakeClearShown({})]'));

        Http::fake();
        $this->clear(self::DUP)->assertOk();
        // Wala nang Botcake ID ang page: wala na rin sa listahan, at ang pangalawang Clear ay walang binabago.
        $this->clear(self::DUP)->assertStatus(422);
        $this->assertSame(3, PageSetting::where('page_name', self::DUP)->where('provider', 'botcake')->whereIn('key', ['page_id', 'token', 'chat.key'])->count());
    }

    public function test_S_206_4_the_clear_has_the_access_of_the_forbeast_clear(): void
    {
        Http::fake();
        foreach ([$this->user('Data Encoder', 'enc@example.test'), $this->user('Finance', 'fin@example.test')] as $user) {
            $this->actingAs($user);
            $this->clear(self::DUP)->assertForbidden();
        }
        $this->assertSame(self::ID, $this->activeValue(self::DUP, 'botcake', 'page_id'));
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->clear(self::DUP)->assertOk();
    }
}
