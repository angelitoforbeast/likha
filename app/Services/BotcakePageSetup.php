<?php

namespace App\Services;

use App\Models\PageSetting;
use App\Support\BotcakeClock;
use Throwable;

/**
 * Ang kusang setup ng page sa /chatblast/page-settings: ang token test (isang custom_fields call, walang retry), at
 * kapag ok, ang mga custom field (mula sa parehong sagot), ang mga flow at ang template. Pinupunan lang ang blangko
 * at malinaw; ang marami ay itinatabi para sa dropdown. Tinatawag pagkatapos i-save ang Botcake ID o token, at ng
 * Test (row at Test lahat). Ang pagkabigo ng Botcake ay ibinabalik bilang error, hindi itinatapon.
 */
class BotcakePageSetup
{
    public const TEST_BUDGET = 25; // segundo ng isang check, kasama ang token test at ang paghihintay ng rate limit
    private const TOKEN_TIMEOUT = 20;

    public function __construct(
        private PageSettings $settings,
        private BotcakeClient $client,
        private BotcakeCustomerSync $sync,
        private BotcakeFlowNames $names,
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
            $reason = $e instanceof BotcakeException ? $e->reason : null;
            return ['status' => 'error', 'written' => [], 'error' => $e instanceof BotcakeException ? $e->getMessage() : 'Botcake: no answer', 'reason' => $reason];
        }

        $status = $res['ok'] ? 'ok' : 'invalid';
        $this->sync->tokenStatus($page, $status, true);
        $written = ['botcake.token_status' => $this->settings->pageQuery($page)->where('provider', 'botcake')->where('key', 'token_status')
            ->where('active', true)->orderByDesc('id')->first()];
        if ($status !== 'ok') return ['status' => $status, 'written' => $written, 'error' => null, 'reason' => null];

        if ($res['fields'] !== null) $this->detectField($page, $res['fields'], $written);

        try {
            $after = $this->names->afterTokenOk($page, $deadline);
        } catch (Throwable $e) {
            report($e);
            return ['status' => 'ok', 'written' => $written, 'error' => 'Botcake: no answer', 'reason' => null];
        }

        return ['status' => 'ok', 'written' => array_merge($written, $after['written']), 'error' => $after['error'], 'reason' => null];
    }

    /**
     * Ang mga kandidato ng message field: ang pangalang, kapag maliliit na titik at walang espasyo o gitling, ay
     * "gptinput" o "message"; itinatabi sa botcake.field.message.options. Kapag blangko ang field at iisa ang kandidato,
     * iyon ang pinupunan. Hindi kailanman pinapalitan ang naitala na.
     * @param array<int, array{id: string, name: string}> $fields
     */
    private function detectField(string $page, array $fields, array &$written): void
    {
        $candidates = array_values(array_filter($fields, fn ($f) => in_array(self::norm($f['name']), ['gptinput', 'message'], true)));
        $written['botcake.field.message.options'] = $this->settings->set($page, 'botcake', 'field.message.options',
            json_encode($candidates, JSON_UNESCAPED_UNICODE), false, null, true)['setting'];

        if (trim((string) $this->settings->get($page, 'botcake', 'field.message')) !== '') return;
        if (count($candidates) === 1 && ctype_digit($candidates[0]['id'])) {
            $written['botcake.field.message'] = $this->settings->set($page, 'botcake', 'field.message', $candidates[0]['id'])['setting'];
        }
    }

    private static function norm(string $name): string
    {
        return str_replace([' ', '-'], '', mb_strtolower(trim($name), 'UTF-8'));
    }
}
