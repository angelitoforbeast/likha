<?php

namespace Tests\Feature\PageSettings;

use App\Models\PageSetting;
use App\Services\PageSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Botcake\BotcakeApiTestCase;

/**
 * Spec 206, /chatblast/page-settings: ang blangkong cell ng mahalagang key (ID, token o key na nagkakabit ng page) ay
 * hindi nase-save hangga't hindi kinukumpirma, at ang server ay tumatanggi sa blangkong walang clear: true.
 */
class ClearConfirmTest extends BotcakeApiTestCase
{
    private const TOKEN = 'tok-clear-confirm-test-k9m2';
    private $ceo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->ceo = $this->user());
        $s = app(PageSettings::class);
        $s->set('Alaga Natural Collective', 'botcake', 'page_id', '1085660977960440');
        $s->set('Alaga Natural Collective', 'botcake', 'token', self::TOKEN);
        $s->set('Alaga Natural Collective', 'botcake', 'flow.on_delivery', '5501');
    }

    private function cell(array $input)
    {
        return $this->postJson('/chatblast/page-settings/cell', $input + ['page_name' => 'Alaga Natural Collective', 'provider' => 'botcake']);
    }

    private function active(string $key): ?string
    {
        return app(PageSettings::class)->get('Alaga Natural Collective', 'botcake', $key);
    }

    /** Ang rendered page, ang row ng page at ang mga column ng grid nito. */
    private function page(): array
    {
        $res = $this->get('/chatblast/page-settings')->assertOk();
        $grid = $res->viewData('grid');

        return [$res->getContent(), collect($grid['rows'])->firstWhere('name', 'Alaga Natural Collective'), collect($grid['columns'])->keyBy('key')->all()];
    }

    /** Pinapatakbo sa Node ang helper ng page (clear-ask, mula sa rendered HTML) at ang $js; ang na-print na JSON. */
    private function node(string $html, string $js): mixed
    {
        $this->assertSame(1, preg_match('~// clear-ask:start(.*?)// clear-ask:end~s', $html, $m), 'the helper is on the page');
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) $this->markTestSkipped('Node is not installed.');
        $base = tempnam(sys_get_temp_dir(), 'clr');
        $file = $base . '.mjs';
        file_put_contents($file, $m[1] . "\nconsole.log(JSON.stringify(" . $js . '));');
        $p = new Process([$node, $file]);
        $p->run();
        @unlink($file);
        @unlink($base);
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());

        return json_decode($p->getOutput(), true);
    }

    /**
     * cellBody ng page para sa bawat [key, value, leaving, sagot sa confirm], gamit ang row, column at cell ng grid.
     * @return array<int, array{body: ?array, asked: array}>
     */
    private function ask(array $calls): array
    {
        [$html, $row, $columns] = $this->page();

        return $this->node($html, '(() => { const row = ' . json_encode($row) . ', columns = ' . json_encode($columns) . '; return '
            . json_encode($calls) . '.map(([key, value, leaving, yes]) => { const asked = [];'
            . ' const body = cellBody(row, columns[key], row.cells[key], value, !!columns[key].secret, leaving, q => { asked.push(q); return yes; });'
            . ' return {body, asked}; }); })()');
    }

    public function test_S_206_1_emptying_the_botcake_id_and_leaving_asks_first_and_cancel_sends_nothing(): void
    {
        [$typing, $cancel, $other] = $this->ask([
            ['botcake.page_id', '', false, true],          // ang pause habang nagta-type: walang save, walang tanong
            ['botcake.page_id', '', true, false],          // pag-alis sa cell, Cancel
            ['botcake.flow.on_delivery', '', true, false], // hindi mahalaga: auto-save gaya ng dati, walang tanong
        ]);

        $this->assertNull($typing['body']);
        $this->assertSame([], $typing['asked']);

        $this->assertNull($cancel['body'], 'Cancel: nothing is sent');
        $this->assertSame(["Clear botcake.page_id of Alaga Natural Collective?\nCurrent value: 1085660977960440\n"
            . 'Botcake chats of this page stop reaching the bot here, and Chatblast and the Botcake sync stop for it.'], $cancel['asked']);

        $this->assertSame([], $other['asked']);
        $this->assertSame(['page_name' => 'Alaga Natural Collective', 'provider' => 'botcake', 'key' => 'flow.on_delivery', 'value' => '', 'is_secret' => false], $other['body']);

        $this->assertSame('1085660977960440', $this->active('page_id'));
    }

    public function test_S_206_2_confirming_clears_the_id_with_the_user_and_the_history_shows_who_and_when(): void
    {
        [$yes] = $this->ask([['botcake.page_id', '', true, true]]);
        $this->assertTrue($yes['body']['clear']);

        $this->travelTo('2026-10-31 10:15:00');
        $this->cell($yes['body'])->assertOk()->assertJson(['status' => 'cleared', 'cell' => null]);

        $old = PageSetting::where('key', 'page_id')->sole();
        $this->assertFalse((bool) $old->active);
        $this->assertSame($this->ceo->id, (int) $old->replaced_by);
        $this->assertNull($this->active('page_id'));

        $this->getJson('/chatblast/page-settings/history?page_name=Alaga%20Natural%20Collective&provider=botcake&key=page_id')->assertOk()
            ->assertJson(['history' => [['value' => '1085660977960440', 'replaced_at' => '2026-10-31 10:15', 'replaced_by' => $this->ceo->name]]]);
    }

    public static function importantBlanks(): array
    {
        return [
            'botcake.page_id' => ['botcake', 'page_id', '1085660977960440'],
            'another provider page_id' => ['sms', 'page_id', '4455'],
            'a free token that is not secret' => ['sms', 'token', 'plain-token-1'],
        ];
    }

    #[DataProvider('importantBlanks')]
    public function test_S_206_3_a_blank_on_an_important_key_without_the_clear_flag_is_refused(string $provider, string $key, string $value): void
    {
        $s = app(PageSettings::class);
        if ($provider !== 'botcake') $s->set('Alaga Natural Collective', $provider, $key, $value);

        $res = $this->cell(['provider' => $provider, 'key' => $key, 'value' => ''])->assertStatus(422);
        $this->assertStringContainsString('Not cleared', $res->json('error'));
        $this->assertSame($value, $s->get('Alaga Natural Collective', $provider, $key), 'the value stays');

        $this->cell(['provider' => $provider, 'key' => $key, 'value' => '', 'clear' => true])->assertOk()->assertJsonPath('status', 'cleared');
        $this->assertNull($s->get('Alaga Natural Collective', $provider, $key));
    }

    public function test_S_206_3_a_blank_on_any_other_key_still_clears_without_the_flag_and_a_secret_blank_changes_nothing(): void
    {
        $this->cell(['key' => 'flow.on_delivery', 'value' => ''])->assertOk()->assertJsonPath('status', 'cleared');
        $this->assertNull($this->active('flow.on_delivery'));

        $this->cell(['key' => 'token', 'value' => ''])->assertOk()->assertJsonPath('status', 'unchanged');
        $this->assertSame(self::TOKEN, $this->active('token'));
    }

    public function test_S_206_3_the_important_keys_are_one_rule(): void
    {
        foreach (['botcake.page_id', 'forbeast.page_id', 'botcake.token', 'incepxionbot.token', 'botcake.chat.key', 'chatbot.flow_id'] as $full) {
            $this->assertNotNull(PageSettings::clearWarning($full), $full);
        }
        $this->assertNotNull(PageSettings::clearWarning('sms.api_key', true), 'any secret');
        foreach (['botcake.flow.on_delivery', 'botcake.test_psid', 'chatbot.reply', 'sms.sender'] as $full) {
            $this->assertNull(PageSettings::clearWarning($full), $full);
        }
    }

    public function test_S_206_6_the_question_shows_only_the_last_4_of_a_secret_and_the_page_never_has_the_token(): void
    {
        [$html, $row, $columns] = $this->page();
        $this->assertStringNotContainsString(self::TOKEN, $html);

        [$secret] = $this->ask([['botcake.token', '', true, true]]);
        $this->assertNull($secret['body'], 'a blank secret is "no change": nothing is sent or asked');
        $this->assertSame([], $secret['asked']);

        $q = $this->node($html, 'clearQuestion(' . json_encode($row) . ', ' . json_encode($columns['botcake.token']) . ', ' . json_encode($row['cells']['botcake.token']) . ')');
        $this->assertStringContainsString('Current value: ••••k9m2', $q);
        $this->assertStringNotContainsString(self::TOKEN, $q);
    }
}
