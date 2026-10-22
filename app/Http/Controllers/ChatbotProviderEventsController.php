<?php

namespace App\Http\Controllers;

use App\Models\PageSetting;
use App\Services\Chatbot\ForbeastWatch;
use App\Services\ChatbotFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only feed ng kusang lipat ng provider (ForbeastWatch) para sa assistant ng owner: ang mga "switched" at
 * "cannot_switch" event pagkatapos ng ?since=<id>, luma muna, hanggang LIMIT. Sariling key (X-CHATBOT-EVENTS-KEY,
 * services.chatbot.events_key); walang key o mali = 401. Walang secret at walang text ng customer.
 */
class ChatbotProviderEventsController extends Controller
{
    private const LIMIT = 100;

    public function index(Request $request): JsonResponse
    {
        $expected = (string) config('services.chatbot.events_key');
        $key = (string) $request->header('X-CHATBOT-EVENTS-KEY');
        abort_unless($expected !== '' && $key !== '' && hash_equals($expected, $key), 401, 'Unauthorized');

        $since = $request->query('since');
        $since = is_string($since) && ctype_digit($since) ? (int) $since : 0;

        $events = PageSetting::where('provider', ChatbotFields::PROVIDER)->where('key', ForbeastWatch::KEY)->where('id', '>', $since)
            ->orderBy('id')->limit(self::LIMIT)->get()->map(function (PageSetting $s) {
                $e = json_decode((string) $s->plainValue(), true);
                $e = is_array($e) ? $e : [];

                return [
                    'id' => $s->id, 'at' => $s->created_at?->timezone('Asia/Manila')->toIso8601String(), 'page' => $s->page_name,
                    'result' => $e['result'] ?? null, 'from' => $e['from'] ?? null, 'to' => $e['to'] ?? null,
                    'condition' => $e['condition'] ?? null, 'error' => isset($e['error']) ? mb_substr((string) $e['error'], 0, 200) : null,
                ];
            })->all();

        return response()->json(['events' => $events]);
    }
}
