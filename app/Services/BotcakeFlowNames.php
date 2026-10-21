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
    /**
     * Mga marker sa value: wala sa listahan ng page, bura na sa Botcake, o hindi nasuri (may dahilan pagkatapos). Sa body
     * ng template: walang template na mapipili (nasuri ang templates ng page, walang APPROVED na tugma).
     */
    public const NOT_FOUND    = '!wala';
    public const REMOVED      = '!bura';
    public const NOT_APPROVED = '!hindi approved';
    public const UNCHECKED    = '!hindi nasuri: ';
    public const NO_TEMPLATE  = '!walang approved';

    /** Ang ipinapakita kapalit ng {{1}}: doon pumapasok ang mensahe ng chatblast sequence. */
    public const SLOT = '(sequence message)';

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
        if ($value === self::NOT_FOUND) return ['line' => 'Not in Botcake', 'tone' => 'red'];
        if ($value === self::REMOVED) return ['line' => 'Deleted in Botcake', 'tone' => 'red'];
        if ($value === self::NOT_APPROVED) return ['line' => 'Template not approved in Botcake', 'tone' => 'red'];
        if ($value === self::NO_TEMPLATE) return ['line' => 'No approved post_order template in Botcake.', 'tone' => 'red'];
        if (str_starts_with($value, self::UNCHECKED)) {
            $reason = substr($value, strlen(self::UNCHECKED));
            return ['line' => 'Check failed: ' . (self::OLD_TEXT[$reason] ?? $reason), 'tone' => 'grey'];
        }
        if (isset(self::OLD_TEXT[$value])) return ['line' => self::OLD_TEXT[$value], 'tone' => ''];

        return ['line' => str_replace('{{1}}', self::SLOT, $value), 'tone' => ''];
    }

    /**
     * Ang Tagalog na dahilan at placeholder na naitala bago naging English ang page: ipinapakita sa English hanggang
     * mapalitan ng susunod na save o Test token. Ang mga marker (!wala, !bura, …) ay data, hindi text, kaya hindi binabago.
     */
    private const OLD_TEXT = [
        'Botcake: hindi listahan ang sagot'    => 'Botcake: not a list',
        'Botcake: walang sagot'                => 'Botcake: no answer',
        'Botcake: tinanggihan (success:false)' => 'Botcake: refused (success:false)',
        'walang botcake.page_id o token'       => 'no botcake.page_id or token',
        'kulang sa oras'                       => 'out of time',
        '(walang body)'                        => '(no body)',
        '(walang pangalan)'                    => '(no name)',
    ];

    /** Ang key na sinusuri pagkatapos i-save: botcake.flow.<x> (isang bahagi lang) o botcake.template.shipping. */
    public static function watches(string $full): bool
    {
        return $full === 'botcake.template.shipping' || (bool) preg_match('/^botcake\.flow\.[a-z0-9_]+$/', $full);
    }

    /**
     * Pagkatapos i-save ang flow ID (isang flows call; kapag shipping flow, isang templates call din para sa body ng
     * template) o ang template (isang templates call). Ang pagkabigo ng Botcake
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
            // Bagong shipping flow at blangko pa ang template: ang "walang template" ay makikita na ngayon.
            if ($full === 'botcake.flow.shipping' && !$this->settings->get($page, 'botcake', 'template.shipping')) {
                $templateError = $this->refreshTemplate($page, $deadline, $written, false);
                $error ??= $templateError;
            }
        }

        return ['written' => $written, 'error' => $error];
    }

    /**
     * $saved = ang flow key na kase-save lang (hal. flow.shipping). Ibinabalik ang error.
     */
    private function refreshFlows(string $page, float $deadline, array &$written, ?string $saved): ?string
    {
        // Ang mga ID bago ang tawag: ang napalitan habang naghihintay ay hindi binibigyan ng pangalan ng luma (tingnan sa ibaba).
        $ids = $this->settings->pageQuery($page)->where('provider', 'botcake')->where('active', true)
            ->where('key', 'like', 'flow.%')->orderBy('id')->get()
            ->filter(fn ($s) => preg_match('/^flow\.[a-z0-9_]+$/', $s->key))
            ->mapWithKeys(fn ($s) => [$s->key => trim((string) $s->value)])->all();

        [$flows, $error] = $this->readFlows($page, $deadline);
        if ($flows !== null) $this->detectFlow($page, $flows, $written);
        if (!$ids) return $error;

        foreach ($ids as $key => $id) {
            if ($error !== null) {
                $value = $key === $saved ? self::UNCHECKED . $error : null;
            } elseif (isset($flows[$id])) {
                $value = $flows[$id]['removed'] ? self::REMOVED : $flows[$id]['name'];
            } else {
                $value = self::NOT_FOUND;
            }
            // Habang hinihintay ang Botcake, maaaring napalitan na ang ID ng ibang save: ang mas bagong request ang magsusulat.
            if ($value === null || trim((string) $this->settings->get($page, 'botcake', $key)) !== $id) continue;
            $written['botcake.' . $key . '.name'] = $this->write($page, $key . '.name', $value);
        }

        return $error;
    }

    /**
     * Ang listahan ng dropdown ng Chatblast flow (botcake.flow.shipping.options): lahat ng hindi burang flow; ang may
     * pangalang tugma sa Setup box (ChatblastSetupCheck) ang recommended. Display lang: ang ID ay isinusulat ng
     * ChatblastSetupCheck, hindi rito.
     * @param array<string, array{name:string, removed:bool}> $flows
     */
    private function detectFlow(string $page, array $flows, array &$written): void
    {
        $items = [];
        foreach ($flows as $id => $f) if (!$f['removed']) $items[] = ['id' => (string) $id, 'name' => $f['name']];
        $name = app(ChatblastSetupCheck::class)->names()['flow'];
        $written['botcake.flow.shipping.options'] = $this->write($page, 'flow.shipping.options', json_encode(ChatblastSetupCheck::options($items, $name), JSON_UNESCAPED_UNICODE));
    }

    /**
     * Ang template ng shipping flow (ang pangalan ay mula sa tao o sa ChatblastSetupCheck): ang body nito sa
     * botcake.template.shipping.text, o NOT_APPROVED kapag hindi ito APPROVED na template ng page.
     * $saved = kase-save lang ang template: ang pagkabigo ay itinatala sa text. Walang template: ang pagkabigo o ang
     * "walang mapipili" (NO_TEMPLATE) ay itinatala rin, para alam ng page kung nasuri talaga.
     */
    private function refreshTemplate(string $page, float $deadline, array &$written, bool $saved): ?string
    {
        $name = trim((string) $this->settings->get($page, 'botcake', 'template.shipping'));
        [$templates, $error] = $this->readTemplates($page, $deadline);

        // Habang hinihintay ang Botcake, maaaring napalitan na ang template ng ibang save: ang mas bagong request ang magsusulat.
        if (trim((string) $this->settings->get($page, 'botcake', 'template.shipping')) !== $name) return $error;

        if ($error !== null) {
            if ($saved || $name === '') $written['botcake.template.shipping.text'] = $this->write($page, 'template.shipping.text', self::UNCHECKED . $error);
            return $error;
        }

        if ($name === '') {
            $written['botcake.template.shipping.text'] = $this->write($page, 'template.shipping.text', self::NO_TEMPLATE);
            return null;
        }

        $t = $this->approved($templates, $name);
        $written['botcake.template.shipping.text'] = $this->write($page, 'template.shipping.text',
            $t === null ? self::NOT_APPROVED : ($t['body'] !== '' ? $t['body'] : '(no body)'));

        return null;
    }

    private function approved(array $templates, string $name): ?array
    {
        foreach ($templates as $t) {
            if ($t['approved'] && ChatblastSetupCheck::norm($t['name']) === ChatblastSetupCheck::norm($name)) return $t;
        }
        return null;
    }

    /**
     * GET flows: isang tawag, buong listahan (hindi pinapansin ng API ang ?page; ang data.flows ay lahat ng flow,
     * flat). Kapag error o kakaibang sagot: walang listahan, kaya walang minamarkahang "wala".
     * @return array{0: ?array<string, array{name:string, removed:bool}>, 1: ?string} flows ayon sa id, error
     */
    public function readFlows(string $page, float $deadline): array
    {
        [$body, $error] = $this->call($page, '/flows', [], $deadline);
        if ($error !== null) return [null, $error];

        $list = $body['data']['flows'] ?? null;
        if (!is_array($list) || !array_is_list($list)) return [null, 'Botcake: not a list'];

        $flows = [];
        foreach ($list as $f) {
            if (!is_array($f) || !isset($f['id']) || !is_scalar($f['id'])) continue;
            $name = is_string($f['name'] ?? null) ? trim($f['name']) : '';
            $flows[(string) $f['id']] = ['name' => mb_substr($name !== '' ? $name : '(no name)', 0, 255), 'removed' => !empty($f['is_removed'])];
        }

        return [$flows, null];
    }

    /**
     * GET message_templates: name, APPROVED o hindi, at ang BODY text.
     * @return array{0: ?array<int, array{name:string, approved:bool, body:string}>, 1: ?string}
     */
    public function readTemplates(string $page, float $deadline): array
    {
        [$body, $error] = $this->call($page, '/message_templates', [], $deadline);
        if ($error !== null || $body === null) return [null, $error];

        $list = $body['data'] ?? null;
        if (!is_array($list) || !array_is_list($list)) return [null, 'Botcake: not a list'];

        $out = [];
        foreach ($list as $t) {
            if (!is_array($t) || !is_string($t['name'] ?? null) || trim($t['name']) === '') continue;
            $text = '';
            foreach ((array) ($t['components'] ?? []) as $c) {
                if (!is_array($c) || strtoupper((string) ($c['type'] ?? '')) !== 'BODY') continue;
                $text = is_string($c['text'] ?? null) ? $c['text'] : '';
            }
            $out[] = ['name' => trim($t['name']), 'approved' => strtoupper((string) ($t['status'] ?? '')) === 'APPROVED',
                'body' => mb_substr(trim($text), 0, 2000)];
        }

        return [$out, null];
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
        if ($pageId === '' || $token === '') return [null, 'no botcake.page_id or token'];

        // Kasama sa oras ang posibleng paghihintay ng rate limit (hanggang MIN_INTERVAL) bago ang request.
        $left = $deadline - $this->clock->now() - BotcakeClient::MIN_INTERVAL;
        if ($left < self::MIN_SECONDS) return [null, 'out of time'];
        $timeout = (int) min(self::CALL_TIMEOUT, floor($left));

        $r = $this->client->shared(sha1($token), $timeout)->once($timeout)
            ->call('GET', '/pages/' . rawurlencode($pageId) . $path, $query, $token);

        if ($r['http'] === 0) return [null, 'Botcake: no answer'];
        if ($r['http'] < 200 || $r['http'] >= 300) return [null, 'Botcake: HTTP ' . $r['http']];
        if (!is_array($r['body'])) return [null, 'Botcake: not a list'];
        if (($r['body']['success'] ?? true) === false) return [null, 'Botcake: refused (success:false)'];

        return [$r['body'], null];
    }

    /**
     * Ang pangalan ng bawat flow ID ng page (botcake.flow.<x>.name) mula sa nabasang listahan: ang pangalan, bura na, o
     * wala sa listahan. Para sa ChatblastSetupCheck (iisang flows call kada check).
     * @param array<string, array{name:string, removed:bool}> $flows
     * @return array<string, PageSetting>
     */
    public function writeFlowNames(string $page, array $flows): array
    {
        $written = [];
        $ids = $this->settings->pageQuery($page)->where('provider', 'botcake')->where('active', true)
            ->where('key', 'like', 'flow.%')->orderBy('id')->get()
            ->filter(fn ($s) => preg_match('/^flow\.[a-z0-9_]+$/', $s->key))
            ->mapWithKeys(fn ($s) => [$s->key => trim((string) $s->value)])->all();
        foreach ($ids as $key => $id) {
            $value = isset($flows[$id]) ? ($flows[$id]['removed'] ? self::REMOVED : $flows[$id]['name']) : self::NOT_FOUND;
            $written['botcake.' . $key . '.name'] = $this->write($page, $key . '.name', $value);
        }

        return $written;
    }

    private function write(string $page, string $key, string $value): PageSetting
    {
        return $this->settings->set($page, 'botcake', $key, $value, false, null, true)['setting'];
    }
}
