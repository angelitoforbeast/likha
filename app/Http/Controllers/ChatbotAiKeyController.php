<?php

namespace App\Http\Controllers;

use App\Services\Chatbot\ChatbotAiKeys;
use App\Services\Chatbot\ChatbotAiProviders;
use App\Services\Chatbot\ChatbotAiQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang /chatbot/ai-keys: ang pool ng AI key ng kumpanya (spec 210). Ang makakapagpalit lang ng AI settings ng site ang
 * nakakakita at nagbabago (403 sa iba). Walang key na lumalabas: label, provider, status, huling gamit at huling 4 lang.
 */
class ChatbotAiKeyController extends Controller
{
    public function __construct(private ChatbotAiKeys $keys)
    {
    }

    private function checkAccess(): void
    {
        if (!ChatbotAiController::mayChange()) abort(403);
    }

    public function index()
    {
        $this->checkAccess();

        return view('chatbot.ai-keys', ['providers' => array_map(fn ($p) => ['name' => $p, 'label' => ChatbotAiProviders::label($p)], ChatbotAiKeys::providers())]);
    }

    private static function time(mixed $v): ?string
    {
        if ($v === null || $v === '') return null;
        try {
            return (is_int($v) ? Carbon::createFromTimestamp($v) : Carbon::parse((string) $v))->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Ang status sa screen: Cannot decrypt, Disabled, Down: <dahilan>, Retrying, Not tested, OK. */
    public static function status(array $k): string
    {
        $h = $k['health'];
        if ($k['secret'] === '') return 'Cannot decrypt';
        if (!$k['enabled']) return 'Disabled';
        if (($h['state'] ?? 'ok') === 'down') {
            return ChatbotAiKeys::resting($h) ? 'Down: ' . (ChatbotAiKeys::REASONS[$h['reason'] ?? ''] ?? 'failing') : 'Retrying';
        }

        return isset($h['test']) || isset($h['at']) ? 'OK' : 'Not tested';
    }

    /** @return array<string, string> key id => huling gamit (mula sa record ng AI requests) */
    private function lastUsed(array $ids): array
    {
        if (!$ids || !Schema::hasTable(ChatbotAiQueue::TABLE) || !Schema::hasColumn(ChatbotAiQueue::TABLE, 'key_id')) return [];

        return DB::table(ChatbotAiQueue::TABLE)->whereIn('key_id', $ids)->groupBy('key_id')->selectRaw('key_id, MAX(created_at) AS used')->pluck('used', 'key_id')->all();
    }

    public function data(Request $request)
    {
        $this->checkAccess();
        $all = $this->keys->all();
        $used = $this->lastUsed(array_keys($all));
        $keys = array_values(array_map(fn ($k) => [
            'id' => $k['id'], 'provider' => $k['provider'], 'provider_label' => ChatbotAiProviders::label($k['provider']), 'label' => $k['label'], 'last4' => $k['last4'],
            'enabled' => $k['enabled'], 'status' => self::status($k), 'reason' => $k['health']['reason'] ?? null,
            'until' => isset($k['health']['until']) && ($k['health']['state'] ?? '') === 'down' ? self::time((int) $k['health']['until']) : null,
            'test' => $k['health']['test'] ?? null, 'tested_at' => self::time($k['health']['tested_at'] ?? null), 'last_used' => self::time($used[$k['id']] ?? null),
            'added_at' => self::time($k['added_at']),
        ], $all));
        $alert = $this->keys->latest('alert');
        $shown = $alert && Carbon::parse($alert['at'])->greaterThan(now()->subHours(ChatbotAiKeys::ALERT_SHOWN_HOURS));
        $page = is_numeric($request->query('page')) ? (int) $request->query('page') : 1;
        $events = $this->keys->events($page);
        $events['events'] = array_map(fn ($e) => ['at' => self::time($e['at'] ?? null)] + $e, $events['events']);

        return response()->json(['keys' => $keys, 'alert' => $shown ? ['at' => self::time($alert['at']), 'page' => $alert['page'] ?? null, 'error' => $alert['error'] ?? null] : null,
            'events' => $events, 'legacy' => !$this->keys->inPool(ChatbotAiProviders::OPENAI) && ChatbotAiProviders::key(ChatbotAiProviders::OPENAI) !== '',
            'usage' => \App\Services\Chatbot\ChatbotAiUsage::panel()]);
    }

    public function store(Request $request)
    {
        $this->checkAccess();
        $res = $this->keys->add($request->input('provider'), $request->input('label'), $request->input('key'), Auth::id());
        if ($res['error'] !== null) return response()->json(['error' => $res['error']], 422);

        return response()->json(['status' => 'added', 'id' => $res['id']]);
    }

    private function id(string $id): string
    {
        return preg_match('/^[a-f0-9]{8}$/', $id) ? $id : abort(404);
    }

    public function test(string $id)
    {
        $this->checkAccess();
        $res = $this->keys->test($this->id($id), Auth::id());
        if ($res['error'] !== null) return response()->json(['error' => $res['error']], 422);

        return response()->json(['status' => 'tested', 'result' => $res['result']]);
    }

    public function enabled(Request $request, string $id)
    {
        $this->checkAccess();
        if (!is_bool($request->input('enabled')) && !in_array($request->input('enabled'), ['0', '1', 0, 1], true)) return response()->json(['error' => 'Say enabled true or false.'], 422);
        $error = $this->keys->setEnabled($this->id($id), (bool) $request->input('enabled'), Auth::id());

        return $error !== null ? response()->json(['error' => $error], 422) : response()->json(['status' => 'saved']);
    }

    public function label(Request $request, string $id)
    {
        $this->checkAccess();
        $error = $this->keys->rename($this->id($id), $request->input('label'), Auth::id());

        return $error !== null ? response()->json(['error' => $error], 422) : response()->json(['status' => 'saved']);
    }

    public function destroy(string $id)
    {
        $this->checkAccess();
        $error = $this->keys->delete($this->id($id), Auth::id());

        return $error !== null ? response()->json(['error' => $error], 422) : response()->json(['status' => 'deleted']);
    }
}
