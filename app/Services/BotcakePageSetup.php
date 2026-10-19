<?php

namespace App\Services;

use App\Models\PageSetting;
use App\Support\BotcakeClock;
use Throwable;

/**
 * Ang kusang setup ng page sa /chatblast/page-settings: ang token test (isang custom_fields call, walang retry), at
 * kapag ok, ang setup check ayon sa mga pangalan ng Setup box (ChatblastSetupCheck: ang mga custom field mula sa parehong
 * sagot, saka ang mga flow at ang template). Tinatawag pagkatapos i-save ang Botcake ID o token, ng Test (row at Test
 * lahat), ng Check all at ng gabi-gabing check. Ang pagkabigo ng Botcake ay ibinabalik bilang error, hindi itinatapon.
 */
class BotcakePageSetup
{
    public const TEST_BUDGET = 25; // segundo ng isang check, kasama ang token test at ang paghihintay ng rate limit
    private const TOKEN_TIMEOUT = 20;

    public function __construct(
        private PageSettings $settings,
        private BotcakeClient $client,
        private BotcakeCustomerSync $sync,
        private ChatblastSetupCheck $setup,
        private BotcakeClock $clock,
    ) {
    }

    /**
     * @return array{status: string, written: array<string, ?PageSetting>, error: ?string, reason: ?string}
     *   status = ok | invalid | error | skipped (walang Botcake ID o token)
     */
    public function check(string $page): array
    {
        $deadline = $this->clock->now() + self::TEST_BUDGET;
        $pageId = trim((string) $this->settings->get($page, 'botcake', 'page_id'));
        $token  = trim((string) $this->settings->get($page, 'botcake', 'token'));
        if ($pageId === '' || $token === '') return ['status' => 'skipped', 'written' => [], 'error' => null, 'reason' => null];

        try {
            // Parehong rate key ng flows at templates na susunod: 2 request kada segundo kada page.
            $res = $this->client->shared(sha1($token), self::TOKEN_TIMEOUT)->once(self::TOKEN_TIMEOUT)->tokenAndFields($pageId, $token);
        } catch (Throwable $e) {
            // Ang hindi galing sa Botcake ay bug: itinatala, saka ang pangkalahatang mensahe lang sa page.
            if (!$e instanceof BotcakeException) report($e);
            $reason = $e instanceof BotcakeException ? $e->reason : null;
            $error = $e instanceof BotcakeException ? $e->getMessage() : 'Botcake: no answer';
            $this->setup->recordFailure($page, $error); // ang grey na "Check failed" sa Check column
            return ['status' => 'error', 'written' => [], 'error' => $error, 'reason' => $reason];
        }

        $status = $res['ok'] ? 'ok' : 'invalid';
        $this->sync->tokenStatus($page, $status, true);
        $written = ['botcake.token_status' => $this->settings->pageQuery($page)->where('provider', 'botcake')->where('key', 'token_status')
            ->where('active', true)->orderByDesc('id')->first()];
        if ($status !== 'ok') {
            $written['botcake.' . ChatblastSetupCheck::CHECK_KEY] = $this->setup->recordFailure($page, 'token invalid');
            return ['status' => $status, 'written' => $written, 'error' => null, 'reason' => null];
        }

        try {
            $check = $this->setup->run($page, $res['fields'], $deadline); // null (kakaibang sagot): babasahin ulit ng check
        } catch (Throwable $e) {
            report($e);
            return ['status' => 'ok', 'written' => $written, 'error' => 'Botcake: no answer', 'reason' => null, 'check' => null];
        }

        return ['status' => 'ok', 'written' => array_merge($written, $check['written']), 'error' => $check['error'], 'reason' => null, 'check' => $check];
    }
}
