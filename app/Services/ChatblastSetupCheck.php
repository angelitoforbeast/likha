<?php

namespace App\Services;

use App\Models\BotcakeMessage;
use App\Support\BotcakeClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ang setup ng chatblast ayon sa iisang pangalan para sa lahat ng page (ang Setup box ng /chatblast/page-settings, nasa
 * page na "_all"): ang flow, ang custom field at ang template. Kada page:
 * - flow at field: kapag eksaktong isa ang tugma (trim, hindi case-sensitive, iisang espasyo; hindi ang burang flow),
 *   isinusulat ang ID bilang system (pinapalitan pati ang inilagay ng tao; history ang luma). Wala o dalawa pataas = X,
 *   naiiwan ang value pero hindi Ready ang page.
 * - template: APPROVED na may eksaktong pangalan at may {{1}} sa BODY; isinusulat ang pangalan. Kung hindi, X.
 * - ang resulta ay nasa botcake.setup.check (JSON); ang hindi naabot na Botcake ay "Check failed", walang binabago.
 * - bagong flow ID: ang statistics nito ang baseline ng delivered check (botcake.setup.delivery).
 * Mga tawag kada page: custom_fields (kapag wala pa), flows, message_templates, at statistics kapag kailangan; isang subok
 * bawat isa, sa shared throttle ng token. Walang token sa log o sa naitala.
 */
class ChatblastSetupCheck
{
    public const ALL_PAGE = '_all';
    public const NAME_KEYS = ['flow' => 'setup.flow_name', 'field' => 'setup.field_name', 'template' => 'setup.template_name'];
    public const DEFAULTS = ['flow' => 'UPDATE SHIPPING STATUS 2026', 'field' => 'message', 'template' => 'post_order'];
    public const CHECK_KEY = 'setup.check';
    public const DELIVERY_KEY = 'setup.delivery';
    public const DELIVERY_WAIT_MINUTES = 60; // ang padala ay dapat mas luma rito bago basahin ang delivered
    public const BUDGET = 25;                // segundo ng isang page (gaya ng Test token)
    private const CALL_TIMEOUT = 8;

    public function __construct(private PageSettings $settings, private BotcakeClient $client, private BotcakeFlowNames $names, private BotcakeClock $clock)
    {
    }

    /** Ang paghahambing ng pangalan: trim, iisang espasyo sa loob, maliliit na titik. */
    public static function norm(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)), 'UTF-8');
    }

    /** @return array{flow: string, field: string, template: string} ang mga pangalan ng Setup box (o ang default), isang query */
    public function names(): array
    {
        return self::namesFrom($this->settings->pageQuery(self::ALL_PAGE)->where('provider', 'botcake')->where('active', true)
            ->whereIn('key', array_values(self::NAME_KEYS))->orderBy('id')->get(['provider', 'key', 'value']));
    }

    /**
     * Ang mga pangalan mula sa mga row na nabasa na (hal. ng grid): ang botcake.setup.* lang ang tinitingnan.
     * @param iterable<\App\Models\PageSetting> $rows
     * @return array{flow: string, field: string, template: string}
     */
    public static function namesFrom(iterable $rows): array
    {
        $out = self::DEFAULTS;
        foreach ($rows as $r) {
            $what = array_search($r->key, self::NAME_KEYS, true);
            if ($r->provider === 'botcake' && $what !== false && trim((string) $r->value) !== '') $out[$what] = trim((string) $r->value);
        }

        return $out;
    }

    /**
     * Suriin ang isang page. $fields = ang custom fields mula sa token test (null = basahin dito). $dryRun = walang
     * isinusulat. Ang sagot ay para sa command at sa job.
     * @return array{status: string, error: ?string, ok: bool, x: array<int, string>, flow: array, field: array, template: array, written: array}
     *   status = checked | error | skipped
     */
    public function run(string $page, ?array $fields = null, ?float $deadline = null, bool $dryRun = false): array
    {
        $deadline ??= $this->clock->now() + self::BUDGET;
        $names = $this->names();
        $base = ['status' => 'checked', 'error' => null, 'ok' => false, 'x' => [], 'flow' => [], 'field' => [], 'template' => [], 'written' => []];
        $pageId = trim((string) $this->settings->get($page, 'botcake', 'page_id'));
        $token = trim((string) $this->settings->get($page, 'botcake', 'token'));
        if ($pageId === '' || $token === '') return ['status' => 'skipped'] + $base;

        try {
            if ($fields === null) {
                $res = $this->client->shared(sha1($token), self::CALL_TIMEOUT)->once(self::CALL_TIMEOUT)->tokenAndFields($pageId, $token);
                if (!$res['ok']) return $this->failed($page, 'token invalid', $dryRun, $base);
                $fields = $res['fields'];
                if ($fields === null) return $this->failed($page, 'Botcake: not a list', $dryRun, $base);
            }
            [$flows, $flowError] = $this->names->readFlows($page, $deadline);
            if ($flowError !== null) return $this->failed($page, $flowError, $dryRun, $base);
            [$templates, $templateError] = $this->names->readTemplates($page, $deadline);
            if ($templateError !== null || $templates === null) return $this->failed($page, $templateError ?? 'Botcake: no answer', $dryRun, $base);
        } catch (Throwable $e) {
            if (!$e instanceof BotcakeException) report($e);
            return $this->failed($page, $e instanceof BotcakeException ? $e->getMessage() : 'Botcake: no answer', $dryRun, $base);
        }

        // Ang flow at ang field: eksaktong isa.
        $flowHits = [];
        foreach ($flows as $id => $f) {
            if (!$f['removed'] && ctype_digit((string) $id) && self::norm($f['name']) === self::norm($names['flow'])) $flowHits[(string) $id] = $f['name'];
        }
        $fieldHits = [];
        foreach ($fields as $f) {
            if (ctype_digit((string) $f['id']) && self::norm($f['name']) === self::norm($names['field'])) $fieldHits[(string) $f['id']] = $f['name'];
        }
        $flow = $this->pick('flow', $flowHits, $names['flow'], trim((string) $this->settings->get($page, 'botcake', 'flow.shipping')));
        $field = $this->pick('field', $fieldHits, $names['field'], trim((string) $this->settings->get($page, 'botcake', 'field.message')));

        // Ang template: APPROVED, eksaktong pangalan, may {{1}}.
        $same = array_values(array_filter($templates, fn ($t) => self::norm($t['name']) === self::norm($names['template'])));
        $approved = array_values(array_filter($same, fn ($t) => $t['approved']));
        $template = ['name' => $names['template'], 'ok' => false, 'reason' => null, 'body' => null];
        if (!$same) $template['reason'] = 'No template named ' . $names['template'] . ' in Botcake';
        elseif (!$approved) $template['reason'] = 'Template ' . $names['template'] . ' is not approved in Botcake';
        elseif (!str_contains($approved[0]['body'], '{{1}}')) $template['reason'] = 'Template ' . $names['template'] . ' has no {{1}}';
        else $template = ['name' => $approved[0]['name'], 'ok' => true, 'reason' => null, 'body' => $approved[0]['body']];

        $x = array_values(array_filter([$flow['reason'], $field['reason'], $template['reason']]));
        $out = ['ok' => !$x, 'x' => $x, 'flow' => $flow, 'field' => $field, 'template' => $template] + $base;
        if ($dryRun) return $out;

        $written = [];
        $sys = fn (string $key, string $value) => $this->settings->set($page, 'botcake', $key, $value, false, null, true);
        if ($flow['ok']) {
            $r = $sys('flow.shipping', $flow['id']);
            $written['botcake.flow.shipping'] = $r['setting'];
            if ($r['status'] !== 'unchanged' || !$this->delivery($page)) $written['botcake.' . self::DELIVERY_KEY] = $this->baseline($page, $flow['id'], $deadline);
        }
        if ($field['ok']) $written['botcake.field.message'] = $sys('field.message', $field['id'])['setting'];
        if ($template['ok']) {
            $written['botcake.template.shipping'] = $sys('template.shipping', $template['name'])['setting'];
            $written['botcake.template.shipping.text'] = $sys('template.shipping.text', $template['body'] !== '' ? $template['body'] : '(no body)')['setting'];
        }
        // Ang listahan para sa dropdown (display lang) at ang pangalan ng bawat flow ID ng page.
        $written['botcake.flow.shipping.options'] = $sys('flow.shipping.options', json_encode(self::options(
            array_map(fn ($id, $f) => ['id' => (string) $id, 'name' => $f['name']], array_keys(array_filter($flows, fn ($f) => !$f['removed'])), array_filter($flows, fn ($f) => !$f['removed'])),
            $names['flow']), JSON_UNESCAPED_UNICODE))['setting'];
        $written['botcake.field.message.options'] = $sys('field.message.options', json_encode(self::options($fields, $names['field']), JSON_UNESCAPED_UNICODE))['setting'];
        $written += $this->names->writeFlowNames($page, $flows);

        $check = ['at' => now()->format('Y-m-d H:i:s'), 'ok' => !$x, 'x' => $x, 'names' => $names,
            'flow' => ['id' => $flow['id'], 'ok' => $flow['ok']], 'field' => ['id' => $field['id'], 'ok' => $field['ok']], 'template' => ['ok' => $template['ok']]];
        $written['botcake.' . self::CHECK_KEY] = $sys(self::CHECK_KEY, json_encode($check, JSON_UNESCAPED_UNICODE))['setting'];

        return ['written' => $written] + $out;
    }

    /** @return array{id: ?string, current: string, ok: bool, reason: ?string, name: ?string} */
    private function pick(string $what, array $hits, string $name, string $current): array
    {
        $label = $what === 'flow' ? 'flow' : 'custom field';
        if (count($hits) === 1) return ['id' => (string) array_key_first($hits), 'current' => $current, 'ok' => true, 'reason' => null, 'name' => reset($hits)];
        $reason = !$hits ? 'No ' . $label . ' named ' . $name . ' in Botcake' : count($hits) . ' ' . $label . 's named ' . $name;

        return ['id' => null, 'current' => $current, 'ok' => false, 'reason' => $reason, 'name' => null];
    }

    /** Ang listahan ng dropdown: lahat, ang may tamang pangalan muna (recommended), saka ayon sa pangalan. */
    public static function options(array $items, string $name): array
    {
        $list = array_map(fn ($i) => ['id' => (string) $i['id'], 'name' => $i['name'], 'candidate' => self::norm($i['name']) === self::norm($name),
            'recommended' => self::norm($i['name']) === self::norm($name)], array_values($items));
        usort($list, fn ($a, $b) => [!$a['candidate'], mb_strtolower($a['name']), $a['id']] <=> [!$b['candidate'], mb_strtolower($b['name']), $b['id']]);

        return $list;
    }

    /** Hindi naabot ang Botcake: walang binabago; itinatala lang ang dahilan kasama ang huling resulta (para sa page). */
    private function failed(string $page, string $reason, bool $dryRun, array $base): array
    {
        $out = ['status' => 'error', 'error' => $reason] + $base;
        if (!$dryRun) $out['written']['botcake.' . self::CHECK_KEY] = $this->recordFailure($page, $reason);

        return $out;
    }

    /**
     * Ang "Check failed: <dahilan>" ng page (pati kapag ang token test mismo ang pumalya, bago ang check): ang huling
     * resulta ay naiiwan, kaya hindi nagbabago ang Ready.
     */
    public function recordFailure(string $page, string $reason)
    {
        $check = $this->check($page) ?? [];
        $check['error'] = mb_substr($reason, 0, 200);
        $check['error_at'] = now()->format('Y-m-d H:i:s');

        return $this->settings->set($page, 'botcake', self::CHECK_KEY, json_encode($check, JSON_UNESCAPED_UNICODE), false, null, true)['setting'];
    }

    /** Ang huling naitalang check ng page (JSON), o null. */
    public function check(string $page): ?array
    {
        $v = json_decode((string) $this->settings->get($page, 'botcake', self::CHECK_KEY), true);

        return is_array($v) ? $v : null;
    }

    public function delivery(string $page): ?array
    {
        $v = json_decode((string) $this->settings->get($page, 'botcake', self::DELIVERY_KEY), true);

        return is_array($v) ? $v : null;
    }

    /** Ang baseline ng bagong flow: ang statistics nito ngayon (null kapag hindi nabasa: babasahin ng susunod na oras). */
    private function baseline(string $page, string $flowId, float $deadline)
    {
        $stats = $this->statistics($page, $flowId, $deadline);
        $value = ['flow_id' => $flowId, 'since' => now()->format('Y-m-d H:i:s'), 'status' => 'pending',
            'base_sent' => $stats['sent'] ?? null, 'base_delivery' => $stats['delivery'] ?? null];

        return $this->settings->set($page, 'botcake', self::DELIVERY_KEY, json_encode($value), false, null, true)['setting'];
    }

    /** @return array{sent: int, delivery: int}|null ang cumulative na statistics ng flow, o null kapag hindi nabasa */
    public function statistics(string $page, string $flowId, ?float $deadline = null): ?array
    {
        $pageId = trim((string) $this->settings->get($page, 'botcake', 'page_id'));
        $token = trim((string) $this->settings->get($page, 'botcake', 'token'));
        if ($pageId === '' || $token === '') return null;
        $left = ($deadline ?? $this->clock->now() + self::BUDGET) - $this->clock->now() - BotcakeClient::MIN_INTERVAL;
        if ($left < 2) return null;
        $timeout = (int) min(self::CALL_TIMEOUT, floor($left));
        try {
            $r = $this->client->shared(sha1($token), $timeout)->once($timeout)
                ->call('GET', '/pages/' . rawurlencode($pageId) . '/flows/' . rawurlencode($flowId) . '/statistics', [], $token);
        } catch (Throwable $e) {
            if (!$e instanceof BotcakeException) report($e);
            return null;
        }
        if ($r['http'] < 200 || $r['http'] >= 300 || !is_array($r['body']) || ($r['body']['success'] ?? true) === false) return null;
        $data = is_array($r['body']['data'] ?? null) ? $r['body']['data'] : $r['body'];
        if (!is_numeric($data['sent'] ?? null) || !is_numeric($data['delivery'] ?? null)) return null;

        return ['sent' => (int) $data['sent'], 'delivery' => (int) $data['delivery']];
    }

    /**
     * Ang delivered check ng isang page (kada oras sa :45): kapag may hindi-test na chatblast na naipadala sa flow na ito
     * nang hindi bababa sa 60 minuto, basahin ulit ang statistics. Mas mataas ang delivery sa baseline = Delivered (tapos);
     * hindi = X "Sent N, none delivered (Botcake)". Walang baseline pa = basahin ito ngayon.
     * @return string 'delivered' | 'not_delivered' | 'baseline' | 'waiting' | 'unreadable' | 'none'
     */
    public function deliveryCheck(string $page): string
    {
        $d = $this->delivery($page);
        if (!$d || !in_array($d['status'] ?? '', ['pending', 'not_delivered'], true)) return 'none';
        $flowId = (string) ($d['flow_id'] ?? '');
        if ($flowId === '' || trim((string) $this->settings->get($page, 'botcake', 'flow.shipping')) !== $flowId) return 'none';

        if (!is_numeric($d['base_delivery'] ?? null)) {
            $stats = $this->statistics($page, $flowId);
            if ($stats === null) return 'unreadable';
            $this->writeDelivery($page, ['base_sent' => $stats['sent'], 'base_delivery' => $stats['delivery'], 'since' => now()->format('Y-m-d H:i:s')] + $d);
            return 'baseline';
        }
        if ($this->sentVia($page, $flowId, (string) ($d['since'] ?? '')) === 0) return 'waiting';

        $stats = $this->statistics($page, $flowId);
        if ($stats === null) return 'unreadable';
        if ($stats['delivery'] > (int) $d['base_delivery']) {
            $this->writeDelivery($page, ['status' => 'delivered', 'checked_at' => now()->format('Y-m-d H:i:s'), 'delivery' => $stats['delivery']] + $d);
            return 'delivered';
        }
        $sent = max(0, $stats['sent'] - (int) ($d['base_sent'] ?? 0)) ?: $this->sentVia($page, $flowId, (string) ($d['since'] ?? ''));
        $this->writeDelivery($page, ['status' => 'not_delivered', 'checked_at' => now()->format('Y-m-d H:i:s'), 'sent' => $sent] + $d);

        return 'not_delivered';
    }

    private function writeDelivery(string $page, array $value): void
    {
        $this->settings->set($page, 'botcake', self::DELIVERY_KEY, json_encode($value), false, null, true);
    }

    /**
     * Ilang hindi-test na chatblast ng page ang naipadala sa flow na ito mula $since at hindi bababa sa 60 minuto na ang
     * nakaraan (ang flow_id ay nasa steps JSON ng bawat padala: ang "flow" na hakbang). Hanggang 50 row lang ang binabasa.
     */
    public function sentVia(string $page, string $flowId, string $since): int
    {
        if (!Schema::hasTable('botcake_messages')) return 0;
        $trim = \Illuminate\Support\Facades\DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        $rows = BotcakeMessage::where('status', 'sent')->where('is_test', false)
            ->whereRaw("LOWER($trim(page_name)) = ?", [$this->settings->pageKey($page)])
            ->when($since !== '', fn ($q) => $q->where('sent_at', '>=', Carbon::parse($since)))
            ->where('sent_at', '<=', now()->subMinutes(self::DELIVERY_WAIT_MINUTES))
            ->orderByDesc('id')->limit(50)->get(['steps']);

        return $rows->filter(function ($m) use ($flowId) {
            foreach ((array) $m->steps as $s) {
                if (($s['step'] ?? null) === 'flow' && (string) ($s['request']['flow_id'] ?? '') === $flowId) return true;
            }
            return false;
        })->count();
    }
}
