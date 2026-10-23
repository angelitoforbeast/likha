<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\BotcakeChat;
use App\Services\Chatbot\BotHealth;
use App\Support\NavAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * /chatbot/health: ang kalusugan ng bot (bilis ng sagot, pumalyang padala, AI calls). Access gaya ng /chatbot/pages;
 * ang pagbago ng thresholds ay CEO lang. Ang JSON (/api/chatbot/health) ay may sariling key, gaya ng provider-events.
 */
class ChatbotHealthController extends Controller
{
    public function __construct(private BotHealth $health)
    {
    }

    private function role(): string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';

        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    public function index(Request $request)
    {
        if (!NavAccess::allows($this->role(), '/chatblast')) abort(403);

        $out = $this->health->output($request->query());
        $pages = array_flip(BotcakeChat::pageMap());
        ksort($pages, SORT_NATURAL | SORT_FLAG_CASE);

        return view('chatbot.health', $out + ['pages' => $pages, 'isCeo' => $this->role() === 'CEO', 'metaLights' => BotHealth::LIGHTS]);
    }

    /** I-save ang thresholds (buong hugis, kasama ang default ng hindi pinalitan). */
    public function saveThresholds(Request $request)
    {
        if ($this->role() !== 'CEO') abort(403);

        $keys = array_keys(BotHealth::LIGHTS);
        $v = Validator::make($request->all(), [
            't' => ['required', 'array'],
            't.*' => ['array'],
            't.*.amber' => ['required', 'numeric', 'min:0', 'max:1000000'],
            't.*.red' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);
        $v->after(function ($v) use ($request, $keys) {
            foreach ((array) $request->input('t', []) as $key => $t) {
                if (!in_array($key, $keys, true)) {
                    $v->errors()->add('t', 'Unknown rule: ' . e((string) $key));
                    continue;
                }
                $red = $t['red'] ?? null;
                if ($red === null || $red === '' || !is_numeric($t['amber'] ?? null) || !is_numeric($red)) continue;
                $high = BotHealth::LIGHTS[$key][3] === 'high';
                if ($high ? $t['amber'] > $red : $t['amber'] < $red) $v->errors()->add("t.$key", 'Amber must come before red.');
            }
        });
        $v->validate();

        $new = BotHealth::thresholds();
        foreach ($request->input('t') as $key => $t) {
            $new[$key] = ['amber' => $t['amber'] + 0, 'red' => ($t['red'] ?? '') === '' ? null : $t['red'] + 0];
        }
        AppSetting::set(BotHealth::SETTING, json_encode($new));

        return redirect()->route('chatbot.health', Arr::only($request->query(), ['page_id', 'from', 'to']))->with('status', 'Thresholds saved.');
    }

    /** Ang parehong numero para sa analyst: walang listahan ng customer. */
    public function json(Request $request): JsonResponse
    {
        $expected = (string) config('services.chatbot.events_key');
        $key = (string) $request->header('X-CHATBOT-EVENTS-KEY');
        abort_unless($expected !== '' && $key !== '' && hash_equals($expected, $key), 401, 'Unauthorized');

        $q = $request->query();
        if (isset($q['page'])) $q['page_id'] = $q['page'];

        return response()->json(Arr::except($this->health->output($q), ['slow', 'failures']));
    }
}
