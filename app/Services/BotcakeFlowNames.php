<?php

namespace App\Services;

use App\Models\PageSetting;
use App\Support\BotcakeClock;

/**
 * Ang pangalan ng bawat flow ID at ang template ng shipping flow, para sa /page-settings. Itinatala bilang system keys
 * sa page_settings: botcake.flow.<x>.name (ang pangalan, o marker) at botcake.template.shipping.text (ang body).
 * Kinukuha pagkatapos i-save ang flow ID o template, at sa Test token kapag ok ang token; hindi kailanman sa Sync.
 * Isang subok lang kada tawag (walang retry), maikling timeout, at may hangganang oras; ang pangalan at body lang ang
 * itinatabi, hindi ang buong sagot. Ang token ay nasa header lang.
 */
class BotcakeFlowNames
{
    /** Mga marker sa value: wala sa listahan ng page, bura na sa Botcake, o hindi nasuri (may dahilan pagkatapos). */
    public const NOT_FOUND    = '!wala';
    public const REMOVED      = '!bura';
    public const NOT_APPROVED = '!hindi approved';
    public const UNCHECKED    = '!hindi nasuri: ';

    /** Ang ipinapakita kapalit ng {{1}}: doon pumapasok ang mensahe ng chatblast sequence. */
    public const SLOT = '(mensahe ng sequence)';

    public const MAX_FLOW_PAGES = 10;
    private const CALL_TIMEOUT = 8;
    private const MIN_SECONDS = 2; // mas kaunti pa rito ang natitira: hindi na tatawag
    private const SAVE_BUDGET = 20;

    public function __construct(private PageSettings $settings, private BotcakeClient $client, private BotcakeClock $clock)
    {
    }

    /**
     * Ang linyang ipinapakita sa ilalim ng cell para sa isang naitalang pangalan o body.
     * @return array{line:string, tone:string} tone = red, grey o blangko
     */
    public static function line(string $value): array
    {
        if ($value === self::NOT_FOUND) return ['line' => 'Wala sa Botcake', 'tone' => 'red'];
        if ($value === self::REMOVED) return ['line' => 'Bura na sa Botcake', 'tone' => 'red'];
        if ($value === self::NOT_APPROVED) return ['line' => 'Hindi APPROVED na template ng page', 'tone' => 'red'];
        if (str_starts_with($value, self::UNCHECKED)) return ['line' => substr($value, 1), 'tone' => 'grey'];

        return ['line' => str_replace('{{1}}', self::SLOT, $value), 'tone' => ''];
    }

    /** Ang key na sinusuri pagkatapos i-save: botcake.flow.<x> (isang bahagi lang) o botcake.template.shipping. */
    public static function watches(string $full): bool
    {
        return $full === 'botcake.template.shipping' || (bool) preg_match('/^botcake\.flow\.[a-z0-9_]+$/', $full);
    }

    /**
     * Pagkatapos i-save ang flow ID (isang flows read) o ang template (isang templates call). Ang pagkabigo ng Botcake
     * ay hindi humaharang sa save: itinatala bilang "hindi nasuri: <dahilan>" sa key na na-save, para hindi maiwan
     * ang pangalan ng lumang ID.
     * @return array{written: array<string, ?PageSetting>, error: ?string}
     */
    public function afterSave(string $page, string $full): array
    {
        $deadline = $this->clock->now() + self::SAVE_BUDGET;
        $written = [];

        if ($full === 'botcake.template.shipping') {
            $error = $this->refreshTemplate($page, $deadline, $written, true);
        } else {
            $error = $this->refreshFlows($page, $deadline, $written, substr($full, strlen('botcake.')));
        }

        return ['written' => $written, 'error' => $error];
    }

    /**
     * Pagkatapos ng Test token na ok: ang flows muna (para sa pangalan ng shipping flow), saka ang templates. Hanggang
     * $deadline lang (oras ng BotcakeClock); ang pagkabigo ay hindi isinusulat, naiiwan ang dating mga linya.
     * @return array{written: array<string, ?PageSetting>, error: ?string}
     */
    public function afterTokenOk(string $page, float $deadline): array
    {
        $written = [];
        $error = $this->refreshFlows($page, $deadline, $written, null);

        // Walang shipping flow at walang template: walang ipapakita, kaya walang templates call.
        $error2 = null;
        if ($this->settings->get($page, 'botcake', 'flow.shipping') || $this->settings->get($page, 'botcake', 'template.shipping')) {
            $error2 = $this->refreshTemplate($page, $deadline, $written, false);
        }

        return ['written' => $written, 'error' => $error ?? $error2];
    }

    /** $saved = ang flow key na kase-save lang (hal. flow.shipping), o null sa Test token. Ibinabalik ang error. */
    private function refreshFlows(string $page, float $deadline, array &$written, ?string $saved): ?string
    {
        $ids = $this->settings->pageQuery($page)->where('provider', 'botcake')->where('active', true)
            ->where('key', 'like', 'flow.%')->orderBy('id')->get()
            ->filter(fn ($s) => preg_match('/^flow\.[a-z0-9_]+$/', $s->key))
            ->mapWithKeys(fn ($s) => [$s->key => trim((string) $s->value)])->all();
        if (!$ids) return null;

        [$flows, $complete, $error] = $this->readFlows($page, $deadline);

        foreach ($ids as $key => $id) {
            if ($error !== null) {
                $value = $key === $saved ? self::UNCHECKED . $error : null;
            } elseif (isset($flows[$id])) {
                $value = $flows[$id]['removed'] ? self::REMOVED : $flows[$id]['name'];
            } elseif ($complete) {
                $value = self::NOT_FOUND;
            } else {
                $value = $key === $saved ? self::UNCHECKED . 'hindi natapos basahin ang listahan ng flows' : null;
            }
            if ($value !== null) $written['botcake.' . $key . '.name'] = $this->write($page, $key . '.name', $value);
        }

        return $error;
    }

    /**
     * Ang template ng shipping flow: kapag blangko ang botcake.template.shipping, pipili ang system (pick()); saka ang
     * body nito sa botcake.template.shipping.text, o NOT_APPROVED kapag hindi ito APPROVED na template ng page.
     * $saved = kase-save lang ang template: ang pagkabigo ay itinatala sa text.
     */
    private function refreshTemplate(string $page, float $deadline, array &$written, bool $saved): ?string
    {
        [$templates, $error] = $this->readTemplates($page, $deadline);
        $name = trim((string) $this->settings->get($page, 'botcake', 'template.shipping'));

        if ($error !== null) {
            if ($saved && $name !== '') $written['botcake.template.shipping.text'] = $this->write($page, 'template.shipping.text', self::UNCHECKED . $error);
            return $error;
        }

        if ($name === '') {
            $name = (string) $this->pick($templates, (string) $this->settings->get($page, 'botcake', 'flow.shipping.name'));
            if ($name !== '') {
                $written['botcake.template.shipping'] = $this->settings->set($page, 'botcake', 'template.shipping', $name)['setting'];
            }
        }

        if ($name === '') {
            $old = $this->settings->pageQuery($page)->where('provider', 'botcake')->where('key', 'template.shipping.text')->where('active', true)->get();
            foreach ($old as $s) $this->settings->deactivate($s);
            if ($old->isNotEmpty()) $written['botcake.template.shipping.text'] = null;
            return null;
        }

        $t = $this->approved($templates, $name);
        $written['botcake.template.shipping.text'] = $this->write($page, 'template.shipping.text',
            $t === null ? self::NOT_APPROVED : ($t['body'] !== '' ? $t['body'] : '(walang body)'));

        return null;
    }

    /**
     * Ang auto-pick: (1) ang pangalan sa panaklong sa dulo ng pangalan ng shipping flow, kapag APPROVED na template;
     * (2) kung wala, ang iisang APPROVED template na may {{1}} sa BODY at "Custom Field" sa example; (3) kung wala, null.
     */
    private function pick(array $templates, string $flowName): ?string
    {
        if (!str_starts_with($flowName, '!') && preg_match('/\(([^()]+)\)\s*$/u', $flowName, $m)) {
            if ($t = $this->approved($templates, $m[1])) return $t['name'];
        }

        $hits = array_values(array_filter($templates, fn ($t) => $t['approved'] && str_contains($t['body'], '{{1}}') && $t['custom_field']));

        return count($hits) === 1 ? $hits[0]['name'] : null;
    }

    private function approved(array $templates, string $name): ?array
    {
        $key = mb_strtolower(trim($name), 'UTF-8');
        foreach ($templates as $t) {
            if ($t['approved'] && mb_strtolower(trim($t['name']), 'UTF-8') === $key) return $t;
        }
        return null;
    }

    /**
     * Lahat ng page ng GET flows (?page=1..), hanggang sa blangkong page, sa page na walang bagong ID (hindi pinapansin
     * ng API ang page), sa MAX_FLOW_PAGES, o sa pagkaubos ng oras. $complete = nabasa hanggang dulo.
     * @return array{0: array<string, array{name:string, removed:bool}>, 1: bool, 2: ?string} flows ayon sa id, complete, error
     */
    private function readFlows(string $page, float $deadline): array
    {
        $flows = [];
        for ($n = 1; $n <= self::MAX_FLOW_PAGES; $n++) {
            [$body, $error] = $this->call($page, '/flows', ['page' => $n], $deadline);
            if ($error !== null) {
                // Ang unang page lang ang error; ang naubos na oras sa susunod na page ay "hindi natapos".
                return $n === 1 || $error !== 'kulang sa oras' ? [[], false, $error] : [$flows, false, null];
            }
            $list = $body['data']['flows'] ?? null;
            if (!is_array($list) || !array_is_list($list)) return [[], false, 'Botcake: hindi listahan ang sagot'];

            $new = 0;
            foreach ($list as $f) {
                if (!is_array($f) || !isset($f['id']) || !is_scalar($f['id'])) continue;
                $id = (string) $f['id'];
                if (isset($flows[$id])) continue;
                $name = is_string($f['name'] ?? null) ? trim($f['name']) : '';
                $flows[$id] = ['name' => mb_substr($name !== '' ? $name : '(walang pangalan)', 0, 255), 'removed' => !empty($f['is_removed'])];
                $new++;
            }
            if ($new === 0) return [$flows, true, null];
        }

        return [$flows, false, null];
    }

    /**
     * GET message_templates: name, APPROVED o hindi, ang BODY text at kung may "Custom Field" sa example nito.
     * @return array{0: ?array<int, array{name:string, approved:bool, body:string, custom_field:bool}>, 1: ?string}
     */
    private function readTemplates(string $page, float $deadline): array
    {
        [$body, $error] = $this->call($page, '/message_templates', [], $deadline);
        if ($error !== null || $body === null) return [null, $error];

        $list = $body['data'] ?? null;
        if (!is_array($list) || !array_is_list($list)) return [null, 'Botcake: hindi listahan ang sagot'];

        $out = [];
        foreach ($list as $t) {
            if (!is_array($t) || !is_string($t['name'] ?? null)) continue;
            $text = '';
            $custom = false;
            foreach ((array) ($t['components'] ?? []) as $c) {
                if (!is_array($c) || strtoupper((string) ($c['type'] ?? '')) !== 'BODY') continue;
                $text = is_string($c['text'] ?? null) ? $c['text'] : '';
                $custom = self::hasCustomField($c['example'] ?? null);
            }
            $out[] = ['name' => $t['name'], 'approved' => strtoupper((string) ($t['status'] ?? '')) === 'APPROVED',
                'body' => mb_substr(trim($text), 0, 2000), 'custom_field' => $custom];
        }

        return [$out, null];
    }

    /** May "Custom Field" saanman sa example (string o nested na listahan, gaya ng body_text: [["Custom Field"]]). */
    private static function hasCustomField(mixed $example): bool
    {
        if (is_string($example)) return strcasecmp(trim($example), 'Custom Field') === 0;
        if (!is_array($example)) return false;
        foreach ($example as $v) {
            if (self::hasCustomField($v)) return true;
        }
        return false;
    }

    /**
     * Isang GET na walang retry, timeout na hanggang CALL_TIMEOUT at hindi lalampas sa $deadline. Walang page_id o
     * token = [null, null] (walang tinawag). Ang dahilan ng error ay maikli at amin, hindi ang sagot ng Botcake.
     * @return array{0: ?array, 1: ?string} body, error
     */
    private function call(string $page, string $path, array $query, float $deadline): array
    {
        $pageId = trim((string) $this->settings->get($page, 'botcake', 'page_id'));
        $token  = trim((string) $this->settings->get($page, 'botcake', 'token'));
        if ($pageId === '' || $token === '') return [null, 'walang botcake.page_id o token'];

        $left = $deadline - $this->clock->now();
        if ($left < self::MIN_SECONDS) return [null, 'kulang sa oras'];
        $timeout = (int) min(self::CALL_TIMEOUT, floor($left));

        $r = $this->client->shared(sha1($token), $timeout)->once($timeout)
            ->call('GET', '/pages/' . rawurlencode($pageId) . $path, $query, $token);

        if ($r['http'] === 0) return [null, 'Botcake: walang sagot'];
        if ($r['http'] < 200 || $r['http'] >= 300) return [null, 'Botcake: HTTP ' . $r['http']];
        if (!is_array($r['body'])) return [null, 'Botcake: hindi listahan ang sagot'];
        if (($r['body']['success'] ?? true) === false) return [null, 'Botcake: tinanggihan (success:false)'];

        return [$r['body'], null];
    }

    private function write(string $page, string $key, string $value): PageSetting
    {
        return $this->settings->set($page, 'botcake', $key, $value, false, null, true)['setting'];
    }
}
