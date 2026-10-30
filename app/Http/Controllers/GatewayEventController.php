<?php

namespace App\Http\Controllers;

use App\Jobs\HandleGatewayEvent;
use App\Models\BotcakeChat;
use App\Services\Chatbot\Gateways;
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
 * Ang batch ({"kind": "batch", "events": [...]}, o ang array ng mga event; X-Gateway-Batch: 1 para sa mas malaking body):
 * mga send_status at echo (is_echo true) lang ng iisang page, pinirmahan gamit ang secret ng page na iyon. Bawat event ay
 * sariling job, gaya ng iisang event; ang 200 ay kapag nasa queue na lahat. Ang halo ng page, ang ibang event at ang
 * walang laman ay 422 (walang tinatanggap).
 *
 * Dalawang gateway: ang /api/gateway/events ay ang forbeast (gaya ng dati), ang /api/gateway/events/incepxionbot ay ang
 * incepxionbot. Bawat isa ay sinusuri ng sariling secret lang (ng page sa gateway na iyon, o ang global nito), kaya ang
 * pinirmahan ng secret ng kabilang gateway ay 401. Ang gateway ay kasama sa job; ang parehong mensahe mula sa dalawa ay
 * isang beses lang pinoproseso (HandleGatewayEvent).
 */
class GatewayEventController extends Controller
{
    /** Pinakamalaking body na binabasa (bytes); ang lampas ay 413. */
    private const MAX_BODY = 65536;
    /** Ang batch ng send_status: mas malaki ang body (hanggang 50 event mula sa Forbeast), may hangganan ang bilang. */
    private const MAX_BATCH_BODY = 524288;
    private const MAX_BATCH_EVENTS = 200;

    public function receive(Request $request, ?string $gateway = null): JsonResponse
    {
        // Ang hindi naka-set up na pangalawang gateway (walang URL) ay walang tinatanggap.
        $gw = Gateways::valid($gateway) ? $gateway : Gateways::FORBEAST;
        if ($gw !== Gateways::FORBEAST && !Gateways::setUp($gw)) return response()->json(['ok' => false], 503);
        $max = $request->header('X-Gateway-Batch') === '1' ? self::MAX_BATCH_BODY : self::MAX_BODY;
        if ((int) $request->server('CONTENT_LENGTH') > $max || strlen($raw = (string) $request->getContent()) > $max) {
            return response()->json(['ok' => false], 413);
        }

        $event = json_decode($raw, true);
        // Ang array ng mga event (walang "kind") ay batch din.
        if (is_array($event) && $event !== [] && array_is_list($event)) $event = ['kind' => 'batch', 'events' => $event];
        $batch = null;
        if (is_array($event) && ($event['kind'] ?? null) === 'batch') {
            $batch = $event['events'] ?? null;
            if (!is_array($batch) || $batch === [] || !array_is_list($batch) || count($batch) > self::MAX_BATCH_EVENTS || in_array(false, array_map('is_array', $batch), true)) {
                return response()->json(['ok' => false], 422);
            }
            // Ang pirma ay sa secret ng page ng mga event (iisang page kada batch, sinusuri pagkatapos ng pirma).
            $event = $batch[0];
        } elseif (strlen($raw) > self::MAX_BODY) {
            return response()->json(['ok' => false], 413); // ang mas malaking hangganan ay para sa batch lang
        }
        $global = Gateways::config($gw)['webhook_secret'];
        $secret = $global;
        if (is_array($event)) {
            $page = BotcakeChat::pageMap()[(string) (is_scalar($event['page_id'] ?? null) ? $event['page_id'] : '')] ?? null;
            if ($page === null) {
                HandleGatewayEvent::count('unknown_page');
                return response()->json(['ok' => false], 401);
            }
            $own = (string) app(PageSettings::class)->get($page, $gw, 'webhook_secret');
            if ($own !== '') $secret = $own;
        }
        if ($secret === '') {
            Log::warning('gateway events: refused, the webhook secret is empty', ['gateway' => $gw]);
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
                if ((($e['type'] ?? null) !== 'send_status' && ($e['is_echo'] ?? null) !== true) || !is_scalar($e['page_id'] ?? null) || (string) $e['page_id'] !== $page) {
                    return response()->json(['ok' => false], 422);
                }
            }
            foreach ($batch as $e) HandleGatewayEvent::dispatch(HandleGatewayEvent::fields($e, $gw));

            return response()->json(['ok' => true, 'accepted' => count($batch)]);
        }

        // Ang pagbukas ng ad (referral) ay iniimbak bago ang 200: ang gateway ay nagpapadala ng mensahe ng parehong customer
        // pagkatapos lang ng 2xx na ito, kaya ang row ng ad ay nauuna sa takbo ng unang mensahe (ang ad ng sagot, AdGroups).
        // Ang queue ay sabay-sabay ang mga worker, kaya roon ay maaaring mauna ang mensahe. Magaan ito: walang takbo.
        if (($event['type'] ?? null) === 'referral' && ($event['is_echo'] ?? false) !== true) {
            try {
                // Tuwiran (hindi dispatchSync): walang dinadaanang queue, kaya laging bago ang 200.
                app()->call([new HandleGatewayEvent(HandleGatewayEvent::fields($event, $gw)), 'handle']);
            } catch (\Throwable $e) {
                report($e);
                return response()->json(['ok' => false], 500); // inuulit ng gateway; ang mensahe ay naghihintay sa likod nito
            }

            return response()->json(['ok' => true]);
        }

        HandleGatewayEvent::dispatch(HandleGatewayEvent::fields($event, $gw));

        return response()->json(['ok' => true]);
    }
}
