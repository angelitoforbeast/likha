<?php

namespace App\Services;

use App\Models\ChatblastCampaign;
use Illuminate\Support\Facades\Cache;

/**
 * Ang "Flow at template" ng isang page para sa /chatblast/campaigns: ang pangalan ng flow.shipping (GET flows: pangalan
 * lang, walang laman ng flow), ang approved template na gamit nito (GET message_templates: ayon sa page setting na
 * botcake.template.shipping, o sa pangalan ng flow), ang pangalan ng field.message (GET custom_fields), at ang buong
 * makikita ng customer: ang BODY ng template na ang {{1}} ay isang halimbawang mensahe. Naka-cache nang 10 minuto kada
 * page; walang token sa anumang ibinabalik.
 */
class ChatblastFlowInfo
{
    public const TTL = 600;
    private const ERROR_TTL = 60; // ang may error ay mas maikli: baka naayos na
    private const CALL_TIMEOUT = 10;

    /** Halimbawang order para sa preview (gaya ng sa sheet). */
    public const SAMPLE = ['name' => 'Juan Dela Cruz', 'item' => 'Sample Item x2', 'cod' => '499', 'waybill' => 'JT0000000001',
        'rider' => 'Rider Sample', 'rider_phone' => '09170000000'];

    public function __construct(private PageSettings $settings, private Chatblast $chatblast)
    {
    }

    public static function cacheKey(string $pageKey): string
    {
        return 'chatblast-flow:' . sha1($pageKey);
    }

    public function forPage(string $page, bool $refresh = false): array
    {
        $key = self::cacheKey($this->settings->pageKey($page));
        if (!$refresh && is_array($cached = Cache::get($key))) return $cached;

        $info = $this->fetch($page);
        $failed = $info['flow']['error'] || $info['field']['error'] || $info['template_error'];
        Cache::put($key, $info, $failed ? self::ERROR_TTL : self::TTL);

        return $info;
    }

    private function fetch(string $page): array
    {
        $get = fn (string $k) => trim((string) $this->settings->get($page, 'botcake', $k));
        $token = $get('token');
        $base  = '/pages/' . rawurlencode($get('page_id'));
        $flowId = $get('flow.shipping');
        $fieldId = $get('field.message');
        $client = app(BotcakeClient::class)->shared(sha1($token), self::CALL_TIMEOUT)->once(self::CALL_TIMEOUT);

        $call = function (string $path) use ($client, $token): array {
            $r = $client->call('GET', $path, [], $token);
            $ok = $r['http'] >= 200 && $r['http'] < 300 && is_array($r['body']) && ($r['body']['success'] ?? true) !== false;
            return $ok ? [$r['body'], null] : [null, 'Botcake: HTTP ' . ($r['http'] ?: 'walang sagot')];
        };

        [$flows, $flowError] = $call($base . '/flows');
        $flowName = $flows === null ? null : self::nameOf($flows, $flowId);
        if ($flows !== null && $flowName === null) $flowError = 'Wala ang flow ' . $flowId . ' sa mga flow ng page';

        [$fields, $fieldError] = $call($base . '/custom_fields');
        $fieldName = $fields === null ? null : self::nameOf($fields, $fieldId);
        if ($fields !== null && $fieldName === null) $fieldError = 'Wala ang field ' . $fieldId . ' sa mga custom field ng page';

        [$list, $templateError] = $call($base . '/message_templates');
        $template = $list === null ? null : $this->pickTemplate(self::templates($list), $get('template.shipping'), $flowName);

        $check = [];
        if ($flowName === null || stripos($flowName, 'CHATBLAST') === false) $check[] = 'Ang flow ay walang "CHATBLAST" sa pangalan (o hindi nakita): baka maling flow.';
        if ($template === null) $check[] = 'Hindi matukoy ang template ng flow: itakda ang botcake template.shipping sa /page-settings, o tingnan sa Botcake; ang {{1}} ay hindi matiyak.';
        elseif (!str_contains($template['body'], '{{1}}')) $check[] = 'Ang template ay walang {{1}}: hindi lalabas ang mensahe.';

        $sample = $this->sampleMessage($page);

        return [
            'page' => $page,
            'flow' => ['id' => $flowId, 'name' => $flowName, 'error' => $flowError],
            'field' => ['id' => $fieldId, 'name' => $fieldName, 'error' => $fieldError],
            'template' => $template,
            'template_error' => $templateError,
            'sample' => $sample,
            'preview' => $template ? str_replace('{{1}}', $sample['text'], $template['body']) : null,
            'check' => $check,
            'fetched_at' => now('Asia/Manila')->format('Y-m-d H:i'),
        ];
    }

    /** Ang pangalan ng node na may ganitong id saanman sa sagot (ang flows ay nasa loob ng mga folder). */
    private static function nameOf(mixed $node, string $id): ?string
    {
        if (!is_array($node) || $id === '') return null;
        foreach (['id', 'flow_id', 'custom_field_id'] as $k) {
            if (isset($node[$k]) && is_scalar($node[$k]) && (string) $node[$k] === $id) {
                foreach (['name', 'title', 'label'] as $n) {
                    if (isset($node[$n]) && is_string($node[$n]) && trim($node[$n]) !== '') return trim($node[$n]);
                }
            }
        }
        foreach ($node as $child) {
            if (is_array($child) && ($name = self::nameOf($child, $id)) !== null) return $name;
        }
        return null;
    }

    /**
     * Ang mga template saanman sa sagot: may name at (components o body).
     * @return array<int, array{name:string, status:string, category:string, body:string}>
     */
    private static function templates(mixed $node): array
    {
        if (!is_array($node)) return [];
        if (isset($node['name']) && is_string($node['name']) && (isset($node['components']) || isset($node['body']))) {
            $body = is_string($node['body'] ?? null) ? $node['body'] : '';
            foreach ((array) ($node['components'] ?? []) as $c) {
                if (is_array($c) && strtoupper((string) ($c['type'] ?? '')) === 'BODY' && is_string($c['text'] ?? null)) $body = $c['text'];
            }
            return [['name' => $node['name'], 'status' => (string) ($node['status'] ?? ''), 'category' => (string) ($node['category'] ?? ''), 'body' => $body]];
        }
        $out = [];
        foreach ($node as $child) array_push($out, ...self::templates($child));
        return $out;
    }

    /**
     * Ang template ng flow: ang nasa page setting (botcake.template.shipping) kapag mayroon; kung wala, ang iisang
     * approved template na kapareho o kasama sa pangalan ng flow (hindi pinapansin ang laki ng titik, espasyo at _).
     */
    private function pickTemplate(array $templates, string $setting, ?string $flowName): ?array
    {
        $norm = fn (string $s) => preg_replace('/[^a-z0-9]+/', '', strtolower($s));
        if ($setting !== '') {
            foreach ($templates as $t) {
                if ($norm($t['name']) === $norm($setting)) return $t + ['source' => 'setting'];
            }
            return null;
        }
        if ($flowName === null) return null;
        $flow = $norm($flowName);
        $hits = array_values(array_filter($templates, function ($t) use ($norm, $flow) {
            $n = $norm($t['name']);
            return strtoupper($t['status']) === 'APPROVED' && $n !== '' && (str_contains($flow, $n) || str_contains($n, $flow));
        }));
        return count($hits) === 1 ? $hits[0] + ['source' => 'pangalan'] : null;
    }

    /** Ang Day 1 · A ng unang naka-on na campaign, gamit ang halimbawang order (at ang page na ito). */
    private function sampleMessage(string $page): array
    {
        $c = ChatblastCampaign::where('active', true)->orderBy('id')->first(['id', 'name', 'days']);
        $text = $c && $this->chatblast->invalid($c->days) === null ? $this->chatblast->render($c->days[0][0], self::SAMPLE + ['page' => $page]) : 'Hello po, ito ang mensahe.';

        return ['campaign' => $c?->name, 'text' => $text];
    }
}
