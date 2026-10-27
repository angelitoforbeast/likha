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
        $fb = app(\App\Services\Chatbot\ForbeastSendHealth::class);

        return view('chatbot.health', $out + ['forbeast' => $fb->panel(), 'sendLagAlarm' => $fb->activeAlarm(), 'pages' => $pages, 'isCeo' => $this->role() === 'CEO', 'metaLights' => BotHealth::LIGHTS, 'pageLights' => BotHealth::PAGE_LIGHTS, 'problems' => $this->health->problems(), 'bots' => \App\Services\Chatbot\ChatbotConnections::BOTS,
            'attachments' => \App\Services\Chatbot\ChatAttachments::ignoring() ? \App\Services\Chatbot\ChatAttachments::IGNORE : \App\Services\Chatbot\ChatAttachments::REPLY]);
    }

    /** I-save ang thresholds (buong hugis, kasama ang default ng hindi pinalitan). */
    public function saveThresholds(Request $request)
    {
        if ($this->role() !== 'CEO') abort(403);

        $keys = array_merge(array_keys(BotHealth::LIGHTS), array_keys(BotHealth::PAGE_LIGHTS));
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
                $high = (BotHealth::LIGHTS[$key][3] ?? 'high') === 'high'; // ang mga ilaw kada page ay "mas mataas ay masama"
                if ($high ? $t['amber'] > $red : $t['amber'] < $red) $v->errors()->add("t.$key", 'Amber must come before red.');
            }
        });
        $v->validate();

        $new = BotHealth::thresholds();
        foreach ($request->input('t') as $key => $t) {
            $new[$key] = ['amber' => $t['amber'] + 0, 'red' => ($t['red'] ?? '') === '' ? null : $t['red'] + 0];
        }
        AppSetting::set(BotHealth::SETTING, json_encode($new));
        \Illuminate\Support\Facades\Cache::forget(BotHealth::PROBLEM_CACHE); // ang banner ay sumusunod agad

        return redirect()->route('chatbot.health', Arr::only($request->query(), ['page_id', 'bot', 'from', 'to']))->with('status', 'Thresholds saved.');
    }

    /** Ang mensaheng attachment lang (chatbot_attachments): ignore (ang default) o reply (gaya ng dati). CEO lang. */
    public function saveAttachments(Request $request)
    {
        if ($this->role() !== 'CEO') abort(403);
        $data = $request->validate(['mode' => ['required', 'string', \Illuminate\Validation\Rule::in(\App\Services\Chatbot\ChatAttachments::MODES)]]);
        AppSetting::set(\App\Services\Chatbot\ChatAttachments::SETTING, $data['mode']);

        return redirect()->route('chatbot.health', Arr::only($request->query(), ['page_id', 'bot', 'from', 'to']))->with('status', 'Attachment setting saved.');
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
