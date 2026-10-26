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
 *
 * Ang batch ({"kind": "batch", "events": [...]}, X-Gateway-Batch: 1): mga send_status lang ng iisang page, pinirmahan gamit
 * ang secret ng page na iyon. Bawat event ay sariling job, gaya ng iisang event; ang 200 ay kapag nasa queue na lahat. Ang
 * halo ng page, ang hindi send_status at ang walang laman ay 422 (walang tinatanggap).
 */
class GatewayEventController extends Controller
{
    /** Pinakamalaking body na binabasa (bytes); ang lampas ay 413. */
    private const MAX_BODY = 65536;
    /** Ang batch ng send_status: mas malaki ang body (hanggang 50 event mula sa Forbeast), may hangganan ang bilang. */
    private const MAX_BATCH_BODY = 524288;
    private const MAX_BATCH_EVENTS = 200;

    public function receive(Request $request): JsonResponse
    {
        $max = $request->header('X-Gateway-Batch') === '1' ? self::MAX_BATCH_BODY : self::MAX_BODY;
        if ((int) $request->server('CONTENT_LENGTH') > $max || strlen($raw = (string) $request->getContent()) > $max) {
            return response()->json(['ok' => false], 413);
        }

        $event = json_decode($raw, true);
        $batch = null;
        if (is_array($event) && ($event['kind'] ?? null) === 'batch') {
            $batch = $event['events'] ?? null;
            if (!is_array($batch) || $batch === [] || !array_is_list($batch) || count($batch) > self::MAX_BATCH_EVENTS || in_array(false, array_map('is_array', $batch), true)) {
                return response()->json(['ok' => false], 422);
            }
            // Ang pirma ay sa secret ng page ng mga event (iisang page kada batch, sinusuri pagkatapos ng pirma).
            $event = $batch[0];
        }
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

        if ($batch !== null) {
            $page = (string) $event['page_id'];
            foreach ($batch as $e) {
                if (($e['type'] ?? null) !== 'send_status' || !is_scalar($e['page_id'] ?? null) || (string) $e['page_id'] !== $page) {
                    return response()->json(['ok' => false], 422);
                }
            }
            foreach ($batch as $e) HandleGatewayEvent::dispatch(HandleGatewayEvent::fields($e));

            return response()->json(['ok' => true, 'accepted' => count($batch)]);
        }

        HandleGatewayEvent::dispatch(HandleGatewayEvent::fields($event));

        return response()->json(['ok' => true]);
    }
}
