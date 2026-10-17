<?php

namespace App\Services;

use App\Jobs\SendChatblastMessage;
use App\Models\AppSetting;
use App\Models\BotcakeMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ang pagpapadala ng /chatblast, isang mensahe kada job sa queue na "chatblast" (hindi sa default, na hawak ng Botcake
 * sync nang ilang oras). Ang sunod-sunod na napatunayan: (1) isulat ang custom field (field.message) ng customer,
 * (2) basahin ulit at dapat eksaktong pareho ang text, kung hindi ay field_mismatch at walang padala, (3) i-send ang
 * flow (flow.shipping). Ang bawat hakbang ay itinatala sa row (request na walang token, HTTP, sagot).
 * Ang naipadala ay hindi na mapapadala ulit: queue() ay tumatanggap lang ng hindi pa naipapadala, at may isang totoong
 * row lang kada waybill at uri (dedupe_key). Kapag hindi tiyak kung natanggap ng Botcake ang send_flow (5xx, walang
 * sagot, o naputol ang job pagkatawag nito), "unknown" ang row: hindi ito kinukuha ng anumang Send o Retry.
 */
class ChatblastSender
{
    /** Ang mga status na maaaring ipadala (o ipadala ulit). */
    public const SENDABLE = ['ready', 'failed', 'field_mismatch', 'skipped'];
    public const QUEUE = 'chatblast';
    private const PAUSED = 'chatblast.paused';
    private const BODY_MAX = 1500;

    public function __construct(private PageSettings $settings, private BotcakeCustomerSync $sync, private Chatblast $chatblast)
    {
    }

    /** Ilagay sa queue ang mga row na maaaring ipadala. @return int bilang na naka-queue */
    public function queue(array $ids, ?int $userId): int
    {
        $queued = 0;
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $ok = BotcakeMessage::whereKey($id)->whereIn('status', self::SENDABLE)->whereNotNull('psid')
                ->update(['status' => 'queued', 'queued_at' => now(), 'sent_by' => $userId, 'reason' => null]);
            if ($ok) {
                SendChatblastMessage::dispatch($id);
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * "Send test": ang text ng row ay ipinapadala sa test PSID ng page (botcake.test_psid), bilang bagong row na test.
     * Hindi nito hinaharangan ang totoong padala (walang dedupe_key). Null kapag walang test PSID ang page.
     */
    public function sendTest(int $id, ?int $userId): ?BotcakeMessage
    {
        $row  = BotcakeMessage::findOrFail($id);
        $psid = trim((string) $this->settings->get($row->page_name, 'botcake', 'test_psid'));
        if ($psid === '') return null;

        $test = BotcakeMessage::create([
            'page_name' => $row->page_name, 'macro_output_id' => $row->macro_output_id, 'waybill' => $row->waybill,
            'kind' => $row->kind, 'fb_name' => $row->fb_name, 'psid' => $psid, 'vars' => $row->vars, 'text' => $row->text,
            'jnt_status' => $row->jnt_status, 'status' => 'queued', 'is_test' => true, 'test_of' => $row->id,
            'queued_at' => now(), 'created_by' => $userId, 'sent_by' => $userId,
        ]);
        SendChatblastMessage::dispatch($test->id);

        return $test->fresh();
    }

    public function paused(): bool
    {
        return AppSetting::get(self::PAUSED) === '1';
    }

    /** Pause: ang mga naka-queue ay naghihintay; ang job na tumatakbo na ay tinatapos ang mensahe nito. */
    public function pause(): void
    {
        AppSetting::set(self::PAUSED, '1');
    }

    /** Resume: isang job ulit para sa bawat naka-queue (ang dobleng job ay walang ginagawa: kinukuha muna ang row). */
    public function resume(): int
    {
        AppSetting::set(self::PAUSED, '0');

        $n = 0;
        BotcakeMessage::where('status', 'queued')->select('id')->chunkById(500, function ($rows) use (&$n) {
            foreach ($rows as $row) {
                SendChatblastMessage::dispatch($row->id);
                $n++;
            }
        });

        return $n;
    }

    /** Ang trabaho ng isang job: ipadala ang isang mensahe. */
    public function process(int $id): void
    {
        if ($this->paused()) return; // naiiwang queued; ang Resume ang magpapadala ulit

        // Kunin ang row: isang job lang ang nagpapadala nito, kahit naulit ang job.
        $claimed = BotcakeMessage::whereKey($id)->where('status', 'queued')
            ->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1')]);
        if (!$claimed) return;

        $m = BotcakeMessage::findOrFail($id);
        try {
            $this->deliver($m);
        } catch (Throwable $e) {
            report($e);
            $steps = (array) ($m->fresh()->steps ?? []);
            [$status, $why] = $this->flowCalled($steps) ? ['unknown', self::UNSURE] : ['failed', 'Error'];
            $this->finish($m, $status, $why . ': ' . mb_substr($e->getMessage(), 0, 200), null, $steps);
        }
    }

    private const UNSURE = 'Hindi tiyak kung naipadala (tinawag na ang send_flow)';

    /** Pinatay ang job (hal. timeout) habang nagpapadala. Ang mga hakbang ay naitala na habang nangyayari. */
    public function killed(int $id): void
    {
        $m = BotcakeMessage::whereKey($id)->where('status', 'sending')->first();
        if (!$m) return;

        $unsure = $this->flowCalled((array) ($m->steps ?? []));
        BotcakeMessage::whereKey($id)->where('status', 'sending')->update($unsure
            ? ['status' => 'unknown', 'reason' => self::UNSURE . '; pinatay ang job. Tingnan sa Botcake bago magpadala ulit']
            : ['status' => 'failed', 'reason' => 'Pinatay ang job bago tinawag ang send_flow (hal. timeout); ligtas ipadala ulit']);
    }

    private function flowCalled(array $steps): bool
    {
        return in_array('flow', array_column($steps, 'step'), true);
    }

    private function deliver(BotcakeMessage $m): void
    {
        $cfg = [];
        foreach (Chatblast::REQUIRED as $key) $cfg[$key] = trim((string) $this->settings->get($m->page_name, 'botcake', $key));
        $missing = array_keys(array_filter($cfg, fn ($v) => $v === ''));
        if ($missing) {
            $this->finish($m, 'no_config', 'walang ' . implode(', ', $missing), null, []);
            return;
        }

        // Ang waybill ay dapat nasa status pa rin ng uri (hal. hindi na "In Transit" kapag Delivered na), isa lang kada
        // araw ang mensahe ng uri sa waybill, at dapat mayroon pa ang araw nito.
        if (!$m->is_test && isset(Chatblast::KINDS[$m->kind])) {
            $why = $this->chatblast->staleFor($m->kind, $m->waybill) ?? $this->chatblast->blockedToday($m);
            if ($why !== null) {
                $this->finish($m, 'skipped', $why, null, []);
                return;
            }
        }

        $cap = max(1, (int) config('services.botcake.chatblast_daily_cap', 3000));
        if (!$m->is_test) {
            // Kasama ang unknown: maaaring naipadala na.
            $start = Carbon::now('Asia/Manila')->startOfDay();
            $today = BotcakeMessage::where('page_name', $m->page_name)->where('is_test', false)
                ->where(fn ($w) => $w->where(fn ($s) => $s->where('status', 'sent')->where('sent_at', '>=', $start))
                    ->orWhere(fn ($u) => $u->where('status', 'unknown')->where('updated_at', '>=', $start)))
                ->count();
            if ($today >= $cap) {
                $this->finish($m, 'skipped', 'Naabot ang daily cap ng page (' . $cap . ' ngayong araw); ipadala ulit bukas', null, []);
                return;
            }
        }

        // Invalid na ang token (hal. 401 sa naunang mensahe): hindi na tinatawagan ang Botcake hangga't hindi naaayos.
        if (str_starts_with((string) $this->settings->get($m->page_name, 'botcake', 'token_status'), 'invalid')) {
            $this->finish($m, 'failed', 'Token invalid: ayusin sa /page-settings (Test token) bago magpadala', null, []);
            return;
        }

        $client = app(BotcakeClient::class)->shared(sha1($cfg['token']));
        $base   = '/pages/' . rawurlencode($cfg['page_id']);
        $field  = $base . '/customer/' . rawurlencode((string) $m->psid) . '/customer_fields';
        $steps  = [];

        // Bawat hakbang ay itinatala bago at pagkatapos tawagin, para may bakas kahit pinatay ang job sa gitna.
        $step = function (string $name, string $method, string $path, array $data, bool $onlyRateLimit = false) use ($client, $cfg, $m, &$steps) {
            $steps[] = ['step' => $name, 'method' => $method, 'path' => $path, 'request' => $data, 'http' => null, 'attempts' => 0, 'response' => '', 'at' => now()->toIso8601String()];
            $this->saveSteps($m, $steps);
            $r = $client->call($method, $path, $data, $cfg['token'], $onlyRateLimit);
            $steps[count($steps) - 1] = array_merge(end($steps), ['http' => $r['http'], 'attempts' => $r['attempts'], 'response' => $this->trim($r['body'])]);
            $this->saveSteps($m, $steps);
            return $r;
        };
        $authFail = fn (array $r) => in_array($r['http'], [401, 403], true);
        $ok = fn (array $r) => $r['http'] >= 200 && $r['http'] < 300 && is_array($r['body']) && ($r['body']['success'] ?? null) === true;

        // 1. Isulat ang custom field.
        $w = $step('write', 'POST', $field, ['data' => [['id' => $cfg['field.message'], 'value' => (string) $m->text]]]);
        if ($authFail($w)) { $this->tokenInvalid($m, $w['http'], $steps); return; }
        if (!$ok($w)) { $this->finish($m, 'failed', 'Hindi naisulat ang custom field (HTTP ' . $w['http'] . ')', $w['http'], $steps); return; }

        // 2. Basahin ulit: dapat eksaktong ang text ang laman, kung hindi ay walang padala.
        $r = $step('read', 'GET', $field, []);
        if ($authFail($r)) { $this->tokenInvalid($m, $r['http'], $steps); return; }
        $value = $this->fieldValue($r['body'], $cfg['field.message']);
        if (!$ok($r) || $value !== (string) $m->text) {
            $why = !$ok($r) ? 'Hindi nabasa ang custom field (HTTP ' . $r['http'] . ')' : ($value === null ? 'Wala ang field ' . $cfg['field.message'] . ' sa nabasa' : 'Iba ang laman ng field sa nabasa');
            $this->finish($m, 'field_mismatch', $why . '; hindi ipinadala', $r['http'], $steps);
            return;
        }

        // 3. I-send ang flow. Hindi ligtas ulitin: ang 429 lang ang inuulit; ang 5xx o walang sagot ay "unknown".
        $f = $step('flow', 'POST', $base . '/flows/send_flow', ['psid' => (string) $m->psid, 'flow_id' => (int) $cfg['flow.shipping']], true);
        if ($authFail($f)) { $this->tokenInvalid($m, $f['http'], $steps); return; }
        // Hindi tiyak: walang sagot, 408, 5xx, o 2xx na walang malinaw na success. Ang success:false lang ang tiyak na "hindi".
        $refused = is_array($f['body']) && array_key_exists('success', $f['body']) && $f['body']['success'] === false;
        if ($f['http'] === 0 || $f['http'] === 408 || $f['http'] >= 500 || ($f['http'] >= 200 && $f['http'] < 300 && !$ok($f) && !$refused)) {
            $this->finish($m, 'unknown', self::UNSURE . ' (HTTP ' . $f['http'] . '); tingnan sa Botcake bago magpadala ulit', $f['http'], $steps);
            return;
        }
        if (!$ok($f)) { $this->finish($m, 'failed', 'Hindi na-send ang flow (HTTP ' . $f['http'] . ')', $f['http'], $steps); return; }

        $this->finish($m, 'sent', null, $f['http'], $steps);
    }

    /** Ang laman ng field sa sagot ng GET customer_fields (listahan sa data, o listahan mismo), o null kapag wala. */
    private function fieldValue(mixed $body, string $fieldId): ?string
    {
        $rows = is_array($body) ? ($body['data'] ?? (array_is_list($body) ? $body : [])) : [];
        foreach ((array) $rows as $row) {
            if (!is_array($row)) continue;
            $id = (string) ($row['id'] ?? $row['custom_field_id'] ?? '');
            if ($id === $fieldId) return isset($row['value']) ? (string) $row['value'] : null;
        }

        return null;
    }

    /** 401/403: invalid ang token (gaya ng sync), at ang iba pang naka-queue ng page ay hindi na itinutuloy. */
    private function tokenInvalid(BotcakeMessage $m, int $http, array $steps): void
    {
        $this->sync->tokenStatus($m->page_name, 'invalid');
        $this->finish($m, 'failed', 'Token invalid (HTTP ' . $http . '); ayusin ang token sa /page-settings', $http, $steps);
        BotcakeMessage::where('page_name', $m->page_name)->where('status', 'queued')->where('id', '<>', $m->id)
            ->update(['status' => 'ready', 'reason' => 'Huminto: token invalid']);
    }

    private function saveSteps(BotcakeMessage $m, array $steps): void
    {
        BotcakeMessage::whereKey($m->id)->update(['steps' => json_encode($steps, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE)]);
    }

    private function finish(BotcakeMessage $m, string $status, ?string $reason, ?int $http, array $steps): void
    {
        $m->fill([
            'status' => $status, 'reason' => $reason, 'last_http' => $http, 'steps' => $steps,
            'sent_at' => $status === 'sent' ? now() : $m->sent_at,
        ])->save();
    }

    /** Ang sagot, pinaikli para sa talaan. */
    private function trim(mixed $body): string
    {
        $text = is_string($body) ? $body : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        return mb_strlen($text) > self::BODY_MAX ? mb_substr($text, 0, self::BODY_MAX) . '…' : $text;
    }
}
