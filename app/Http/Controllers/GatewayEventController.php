<?php

namespace App\Http\Controllers;

use App\Jobs\HandleGatewayEvent;
use App\Models\BotcakeChat;
use App\Services\Chatbot\ForbeastPageSetup;
use App\Services\PageSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * /api/gateway/events: ang mga event ng Forbeast Gateway (mensahe, postback, referral, echo, delivery, read). Ang pirma
 * (X-Gateway-Signature = sha256=<HMAC-SHA256 ng raw body>) ay sinusuri bago ang trabaho, gamit ang webhook secret ng page
 * ng event (forbeast.webhook_secret, mula sa Test ng Forbeast token); ang page na wala nito ay ang global
 * GATEWAY_WEBHOOK_SECRET. Hindi kilalang page = 401; walang secret = 503 (walang tinatanggap). Ang trabaho ay nasa queue
 * (HandleGatewayEvent): mabilis ang 200, at ang hindi 2xx ay inuulit ng gateway.
 */
class GatewayEventController extends Controller
{
    /** Pinakamalaking body na binabasa (bytes); ang lampas ay 413. */
    private const MAX_BODY = 65536;

    public function receive(Request $request): JsonResponse
    {
        if ((int) $request->server('CONTENT_LENGTH') > self::MAX_BODY || strlen($raw = (string) $request->getContent()) > self::MAX_BODY) {
            return response()->json(['ok' => false], 413);
        }

        $event = json_decode($raw, true);
        $global = (string) config('services.gateway.webhook_secret');
        $secret = $global;
        if (is_array($event)) {
            $page = BotcakeChat::pageMap()[(string) (is_scalar($event['page_id'] ?? null) ? $event['page_id'] : '')] ?? null;
            if ($page === null) {
                HandleGatewayEvent::count('unknown_page');
                return response()->json(['ok' => false], 401);
            }
            $own = (string) app(PageSettings::class)->get($page, ForbeastPageSetup::PROVIDER, 'webhook_secret');
            if ($own !== '') $secret = $own;
        }
        if ($secret === '') {
            Log::warning('gateway events: refused, GATEWAY_WEBHOOK_SECRET is empty');
            return response()->json(['ok' => false], 503);
        }

        $given = (string) $request->header('X-Gateway-Signature');
        if (str_starts_with($given, 'sha256=')) $given = substr($given, 7);
        if ($given === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), strtolower($given))) {
            HandleGatewayEvent::count('bad_signature');
            return response()->json(['ok' => false], 401);
        }

        if (!is_array($event)) return response()->json(['ok' => false], 400);

        HandleGatewayEvent::dispatch(HandleGatewayEvent::fields($event));

        return response()->json(['ok' => true]);
    }
}
