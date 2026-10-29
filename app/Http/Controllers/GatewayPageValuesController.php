<?php

namespace App\Http\Controllers;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\FlowFields;
use App\Services\Chatbot\Gateways;
use App\Services\Chatbot\PageValues;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * /api/gateway/pages/{meta_page_id}/values: ang Forbeast ang bumabasa (GET) at nagpapalit (PUT) ng mga botfield ng page
 * (PageValues). Pirma: X-Gateway-Signature = sha256=<HMAC-SHA256 ng "<X-Gateway-Timestamp>.<raw body>"> (GET: walang
 * laman ang body), gamit ang sariling webhook secret lang ng page (forbeast.webhook_secret, o incepxionbot.webhook_secret
 * kapag naka-set up ang gateway na iyon; walang global dito), at ang
 * timestamp ay hanggang 300 s ang layo. Hindi kilalang page = 404 unknown_page; anumang mali sa pirma = 401 bad_signature;
 * 60 request kada minuto kada page. Ang PUT ay may X-Forbeast-User (ang actor sa history). Lahat ng galing sa request ay
 * untrusted: text lang, hindi sinusunod.
 *
 * Ang template code ng flow, sa parehong pirma at hangganan: GET /api/gateway/flow-codes/{code}?page={id} (ang flow at
 * ang mga field na kailangan nito, kasama ang value ng page), GET at POST /api/gateway/pages/{id}/flow (ang flow ng page,
 * at ang pagtali ng page sa flow ng code). Ang page ng ibang site ay 404; ang hindi kilala o binawing code ay 404
 * unknown_code (pagkatapos lang ng pirma, kaya hindi nahuhulaan ang code nang walang secret ng page).
 */
class GatewayPageValuesController extends Controller
{
    private const MAX_SKEW = 300;
    private const MAX_ACTOR = 200;
    private const PER_MINUTE = 60;
    /** Pinakamalaking body (bytes): lahat ng field na puno hanggang ChatbotFields::MAX_BYTES ay kasya. */
    private const MAX_BODY = 4194304;
    /** Ang body ng POST flow ({"code": "LK-XXXX-XX"}): maliit lang. */
    private const MAX_FLOW_BODY = 2048;

    public function __construct(private PageSettings $settings, private PageValues $values, private FlowFields $flowFields, private ChatbotFields $fields)
    {
    }

    public function show(Request $request, string $metaPageId): JsonResponse
    {
        [$page, $refused] = $this->guard($request, $metaPageId, '');
        if ($refused) return $refused;

        return response()->json($this->body($metaPageId, $page));
    }

    public function update(Request $request, string $metaPageId): JsonResponse
    {
        if ((int) $request->server('CONTENT_LENGTH') > self::MAX_BODY || strlen($raw = (string) $request->getContent()) > self::MAX_BODY) {
            return self::error(413, 'too_large', 'The request body is too large.');
        }
        [$page, $refused] = $this->guard($request, $metaPageId, $raw);
        if ($refused) return $refused;

        $actor = trim((string) $request->header('X-Forbeast-User'));
        if ($actor === '' || mb_strlen($actor) > self::MAX_ACTOR) {
            return self::invalid(['X-Forbeast-User' => 'Required, at most ' . self::MAX_ACTOR . ' characters.']);
        }
        $data = json_decode($raw, true);
        $values = is_array($data) ? ($data['values'] ?? null) : null;
        if (!is_array($values) || ($values !== [] && array_is_list($values))) return self::invalid(['values' => 'Send {"values": {"<key>": "<text or null>"}}.']);

        // Isang basa ng mga column kada request (ChatbotFields::columns() ay binabasa ang settings ng lahat ng page).
        $all = $this->fields->columns();
        $columns = $this->values->columns($all);
        // Napapalitan din ang ibang botfield na kailangan ng flow ng page (hal. isang Prompt na binabasa nito).
        $rows = $this->flowFields->rows($page);
        $flow = $this->flowFields->pageFlow($page);
        $labels = array_column($all, 'label', 'key');
        $needed = $flow ? array_intersect_key($labels, $this->flowFields->needs($flow, $page, $all, $rows)) : [];
        $editable = $this->values->editable($columns, $needed);
        foreach (array_keys($values) as $key) {
            if (!isset($editable[(string) $key])) {
                return response()->json(['error' => 'field_not_editable', 'key' => (string) $key, 'message' => '"' . $key . '" cannot be changed here.',
                    'errors' => [(string) $key => 'Not editable.']], 422);
            }
        }
        $errors = [];
        foreach ($values as $key => $value) {
            if ($value !== null && !is_string($value)) $errors[$key] = 'The value must be text or null.';
            elseif ($error = ChatbotFields::valueError($key, trim((string) $value), ['https'])) $errors[$key] = $error;
        }
        if ($errors) return self::invalid($errors);

        $this->values->save($page, $values, $actor);

        return response()->json($this->body($metaPageId, $page, $all));
    }

    /** GET /api/gateway/flow-codes/{code}?page={id}: ang flow ng code at ang mga field nito, kasama ang value ng page. */
    public function flowCode(Request $request, string $code): JsonResponse
    {
        $metaPageId = is_string($id = $request->query('page')) ? $id : '';
        [$page, $refused] = $this->guard($request, $metaPageId, '');
        if ($refused) return $refused;
        $flow = ChatbotFlow::findByTemplateCode($code);
        if ($flow === null) return self::unknownCode();

        return response()->json($this->flowBody($metaPageId, $page, $flow));
    }

    /** GET /api/gateway/pages/{id}/flow: ang flow ng page (null kapag wala) at ang mga field nito. */
    public function pageFlow(Request $request, string $metaPageId): JsonResponse
    {
        [$page, $refused] = $this->guard($request, $metaPageId, '');
        if ($refused) return $refused;

        return response()->json($this->flowBody($metaPageId, $page, $this->flowFields->pageFlow($page)));
    }

    /**
     * POST /api/gateway/pages/{id}/flow {"code": "..."}: itinatali ang page sa flow ng code, gaya ng pagpili ng staff
     * (ChatbotPage::link: ang FLOW at flow_id ng page). Walang value na binubura. Ang history ay may "with code …, by …".
     */
    public function applyFlow(Request $request, string $metaPageId): JsonResponse
    {
        if ((int) $request->server('CONTENT_LENGTH') > self::MAX_FLOW_BODY || strlen($raw = (string) $request->getContent()) > self::MAX_FLOW_BODY) {
            return self::error(413, 'too_large', 'The request body is too large.');
        }
        [$page, $refused] = $this->guard($request, $metaPageId, $raw);
        if ($refused) return $refused;

        $actor = trim((string) $request->header('X-Forbeast-User'));
        if (mb_strlen($actor) > self::MAX_ACTOR) return self::invalid(['X-Forbeast-User' => 'At most ' . self::MAX_ACTOR . ' characters.']);
        $data = json_decode($raw, true);
        $code = is_array($data) ? ($data['code'] ?? null) : null;
        if (!is_string($code) || trim($code) === '') return self::invalid(['code' => 'Send {"code": "LK-XXXX-XX"}.']);

        $before = (string) $this->settings->get($page, ChatbotFields::PROVIDER, 'flow_id');
        // Sa loob ng transaction at naka-lock ang flow: ang code na binawi sa sandaling ito ay hindi na naitatali.
        $flow = DB::transaction(function () use ($code, $page, $actor) {
            $normal = ChatbotFlow::normalizeCode($code);
            $flow = $normal === null ? null : ChatbotFlow::where('template_code', $normal)->lockForUpdate()->first();
            if ($flow === null) return null;
            $source = mb_substr('with code ' . $flow->template_code . ($actor !== '' ? ', by ' . $actor : ''), 0, self::MAX_ACTOR);
            app(ChatbotPage::class)->link($page, $flow, null, $source);

            return $flow;
        });
        if ($flow === null) return self::unknownCode();

        return response()->json($this->flowBody($metaPageId, $page, $flow) + ['changed' => $before !== (string) $flow->id]);
    }

    /**
     * Ang iisang bantay ng bawat request: kilalang page (404), tamang pirma (401), saka ang bilang (429).
     * @return array{0: ?string, 1: ?JsonResponse} [page, sagot kapag tinanggihan]
     */
    private function guard(Request $request, string $metaPageId, string $body): array
    {
        $page = $this->page($metaPageId);
        if ($page === null) return [null, self::error(404, 'unknown_page', 'This page is not on this site.')];
        if (!$this->signed($request, $page, $body)) return [null, self::error(401, 'bad_signature', 'The signature does not match.')];
        if ($limited = $this->limited($metaPageId)) return [null, $limited];

        return [$page, null];
    }

    /** Ang pangalan ng page (gaya ng naitala) ng Botcake / Meta page ID na ito, o null. */
    private function page(string $metaPageId): ?string
    {
        return $metaPageId === '' ? null : (BotcakeChat::pageMap()[$metaPageId] ?? null);
    }

    private function signed(Request $request, string $page, string $body): bool
    {
        $ts = (string) $request->header('X-Gateway-Timestamp');
        $given = strtolower((string) $request->header('X-Gateway-Signature'));
        if (str_starts_with($given, 'sha256=')) $given = substr($given, 7);
        if ($given === '' || !preg_match('/^\d{1,12}$/', $ts) || abs(time() - (int) $ts) > self::MAX_SKEW) return false;
        // Ang secret ng page sa bawat gateway, isa-isa (forbeast muna, saka ang naka-set up na incepxionbot): bawat isa ay
        // buong pirma ng sarili nito, walang pinaghahalo.
        foreach (Gateways::ALL as $gw) {
            if ($gw !== Gateways::FORBEAST && !Gateways::setUp($gw)) continue;
            $secret = (string) $this->settings->get($page, $gw, 'webhook_secret');
            if ($secret !== '' && hash_equals(hash_hmac('sha256', $ts . '.' . $body, $secret), $given)) return true;
        }

        return false;
    }

    /** Pagkatapos ng pirma lang binibilang: ang hindi pinirmahang request ay hindi nakakaubos ng bilang ng page. */
    private function limited(string $metaPageId): ?JsonResponse
    {
        $key = 'gateway-page-values:' . $metaPageId;
        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            return self::error(429, 'rate_limited', 'At most ' . self::PER_MINUTE . ' requests a minute for a page.')->header('Retry-After', (string) RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);

        return null;
    }

    /** Ang values GET/PUT: ang listahan ng kontrata, bawat field ay may "required" ayon sa flow ng page. */
    private function body(string $metaPageId, string $page, ?array $all = null): array
    {
        $all ??= $this->fields->columns();
        $flow = $this->flowFields->pageFlow($page);
        $required = $flow ? $this->flowFields->needs($flow, $page, $all, $this->flowFields->rows($page)) : [];

        return ['page_id' => $metaPageId, 'page_name' => $page, 'fields' => $this->values->read($page, $this->values->columns($all), $required)];
    }

    /**
     * Ang flow (o null) at ang mga field nito sa page. Ang graph ng flow ay hindi ipinapadala (ang mga text, prompt at
     * sheet nito ay sa Likha lang): ang bilang ng step at kailan na-publish ang live na bersyon lang.
     */
    private function flowBody(string $metaPageId, string $page, ?ChatbotFlow $flow): array
    {
        return [
            'page_id' => $metaPageId, 'page_name' => $page, 'code' => $flow?->template_code,
            'flow' => $flow === null ? null : [
                'id' => $flow->id, 'name' => $flow->name,
                'steps' => count(array_filter((array) (($flow->graph ?? [])['nodes'] ?? []), fn ($n) => is_array($n) && ($n['type'] ?? '') !== 'start')),
                'updated_at' => ($flow->published_at ?? $flow->updated_at)?->toIso8601String(),
            ],
            'fields' => $flow === null ? [] : $this->flowFields->forFlow($flow, $page),
        ];
    }

    private static function unknownCode(): JsonResponse
    {
        return self::error(404, 'unknown_code', 'This code is not known or was revoked.');
    }

    private static function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], $status);
    }

    /** @param array<string, string> $errors */
    private static function invalid(array $errors): JsonResponse
    {
        return response()->json(['error' => 'invalid_value', 'message' => 'Some values cannot be saved, so nothing was saved.', 'errors' => $errors], 422);
    }
}
