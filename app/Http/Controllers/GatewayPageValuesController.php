<?php

namespace App\Http\Controllers;

use App\Models\BotcakeChat;
use App\Services\Chatbot\Gateways;
use App\Services\Chatbot\PageValues;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * /api/gateway/pages/{meta_page_id}/values: ang Forbeast ang bumabasa (GET) at nagpapalit (PUT) ng mga botfield ng page
 * (PageValues). Pirma: X-Gateway-Signature = sha256=<HMAC-SHA256 ng "<X-Gateway-Timestamp>.<raw body>"> (GET: walang
 * laman ang body), gamit ang sariling webhook secret lang ng page (forbeast.webhook_secret, o incepxionbot.webhook_secret
 * kapag naka-set up ang gateway na iyon; walang global dito), at ang
 * timestamp ay hanggang 300 s ang layo. Hindi kilalang page = 404 unknown_page; anumang mali sa pirma = 401 bad_signature;
 * 60 request kada minuto kada page. Ang PUT ay may X-Forbeast-User (ang actor sa history). Lahat ng galing sa request ay
 * untrusted: text lang, hindi sinusunod.
 */
class GatewayPageValuesController extends Controller
{
    private const MAX_SKEW = 300;
    private const MAX_ACTOR = 200;
    private const PER_MINUTE = 60;
    /** Pinakamalaking body (bytes): lahat ng field na puno hanggang ChatbotFields::MAX_BYTES ay kasya. */
    private const MAX_BODY = 4194304;

    public function __construct(private PageSettings $settings, private PageValues $values)
    {
    }

    public function show(Request $request, string $metaPageId): JsonResponse
    {
        $page = $this->page($metaPageId);
        if ($page === null) return self::error(404, 'unknown_page', 'This page is not on this site.');
        if (!$this->signed($request, $page, '')) return self::error(401, 'bad_signature', 'The signature does not match.');
        if ($limited = $this->limited($metaPageId)) return $limited;

        return response()->json($this->body($metaPageId, $page));
    }

    public function update(Request $request, string $metaPageId): JsonResponse
    {
        if ((int) $request->server('CONTENT_LENGTH') > self::MAX_BODY || strlen($raw = (string) $request->getContent()) > self::MAX_BODY) {
            return self::error(413, 'too_large', 'The request body is too large.');
        }
        $page = $this->page($metaPageId);
        if ($page === null) return self::error(404, 'unknown_page', 'This page is not on this site.');
        if (!$this->signed($request, $page, $raw)) return self::error(401, 'bad_signature', 'The signature does not match.');
        if ($limited = $this->limited($metaPageId)) return $limited;

        $actor = trim((string) $request->header('X-Forbeast-User'));
        if ($actor === '' || mb_strlen($actor) > self::MAX_ACTOR) {
            return self::invalid(['X-Forbeast-User' => 'Required, at most ' . self::MAX_ACTOR . ' characters.']);
        }
        $data = json_decode($raw, true);
        $values = is_array($data) ? ($data['values'] ?? null) : null;
        if (!is_array($values) || ($values !== [] && array_is_list($values))) return self::invalid(['values' => 'Send {"values": {"<key>": "<text or null>"}}.']);

        // Isang basa ng mga column kada request (ChatbotFields::columns() ay binabasa ang settings ng lahat ng page).
        $columns = $this->values->columns();
        $editable = $this->values->editable($columns);
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

        return response()->json($this->body($metaPageId, $page, $columns));
    }

    /** Ang pangalan ng page (gaya ng naitala) ng Botcake / Meta page ID na ito, o null. */
    private function page(string $metaPageId): ?string
    {
        return BotcakeChat::pageMap()[$metaPageId] ?? null;
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

    private function body(string $metaPageId, string $page, ?array $columns = null): array
    {
        return ['page_id' => $metaPageId, 'page_name' => $page, 'fields' => $this->values->read($page, $columns)];
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
