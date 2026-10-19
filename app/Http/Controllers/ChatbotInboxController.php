<?php

namespace App\Http\Controllers;

use App\Models\BotcakeChat;
use App\Models\ChatbotReply;
use App\Services\BotcakeClient;
use App\Services\Chatbot\BotcakeOutbox;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowEngine;
use App\Services\Chatbot\Inbox;
use App\Services\PageSettings;
use App\Support\NavAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * /chatbot/inbox: ang inbox na parang Pancake. Basa: access gaya ng /chatblast (NavAccess; 403 sa iba). Manual na
 * sagot at pag-pause ng bot: ang CEO at ang mga role sa chatbot.reply_roles (app_settings), sa loob lang ng 24 oras ng
 * Messenger. Ang pagpili ng mga role ay CEO lang. Walang token o key na ibinabalik.
 */
class ChatbotInboxController extends Controller
{
    public const ROLES_SETTING = 'chatbot.reply_roles';
    public const MAX_TEXT = 2000;
    public const OUTSIDE_WINDOW = "Outside Messenger's 24-hour window: the customer must message first.";

    public function __construct(private Inbox $inbox, private PageSettings $settings)
    {
    }

    private function role(): string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';
        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    private function checkAccess(): void
    {
        if (!NavAccess::allows($this->role(), '/chatblast')) abort(403);
    }

    /** @return array<int, string> ang mga role na puwedeng sumagot (laging kasama ang CEO) */
    public static function replyRoles(): array
    {
        $json = json_decode((string) DB::table('app_settings')->where('key', self::ROLES_SETTING)->value('value'), true);
        $roles = is_array($json) ? array_values(array_filter($json, 'is_string')) : [];

        return array_values(array_unique(array_merge(['CEO'], $roles)));
    }

    private function canSend(): bool
    {
        return in_array($this->role(), self::replyRoles(), true);
    }

    private function str(Request $request, string $key, int $max = 255): string
    {
        $v = $request->input($key);
        return is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
    }

    /** Ang page (pangalan) ng isang Botcake ID na nasa listahan, o null. */
    private function page(string $pageId): ?string
    {
        return ctype_digit($pageId) ? (BotcakeChat::pageMap()[$pageId] ?? null) : null;
    }

    /** @return array{0: string, 1: string, 2: string} [page_id, page, psid] o 422 */
    private function target(Request $request, bool $needPsid = true): array
    {
        $pageId = $this->str($request, 'page_id', 30);
        $psid = $this->str($request, 'psid', 30);
        $page = $this->page($pageId);
        abort_if($page === null || ($needPsid && !ctype_digit($psid)), response()->json(['error' => 'Pick a page and a customer.'], 422));

        return [$pageId, $page, $psid];
    }

    public function index()
    {
        $this->checkAccess();
        $pages = array_flip(BotcakeChat::pageMap());
        ksort($pages, SORT_NATURAL | SORT_FLAG_CASE);
        $roles = $this->role() === 'CEO' ? DB::table('employee_profiles')->whereNotNull('role')->distinct()->orderBy('role')->pluck('role')
            ->map(fn ($r) => preg_replace('/\s+/u', ' ', trim((string) $r)))->filter()->unique()->values()->all() : [];

        return view('chatbot.inbox', [
            'pages' => $pages, 'canSend' => $this->canSend(), 'isCeo' => $this->role() === 'CEO',
            'replyRoles' => self::replyRoles(), 'allRoles' => $roles, 'repliesOn' => BotcakeOutbox::enabled(),
        ]);
    }

    public function conversations(Request $request)
    {
        $this->checkAccess();
        [$pageId] = $this->target($request, false);
        $window = array_key_exists($this->str($request, 'window', 5), Inbox::WINDOWS) ? $this->str($request, 'window', 5) : '7d';
        $tab = in_array($this->str($request, 'tab', 10), ['bot', 'human'], true) ? $this->str($request, 'tab', 10) : '';

        return response()->json(['rows' => $this->inbox->conversations($pageId, $window, $this->str($request, 'q', 100), $tab)]);
    }

    public function thread(Request $request)
    {
        $this->checkAccess();
        [$pageId, , $psid] = $this->target($request);
        $cursor = function (string $key) use ($request): ?array {
            $v = $request->query($key);
            if (!is_array($v)) return null;
            return ['in' => (int) ($v['in'] ?? 0), 'out' => (int) ($v['out'] ?? 0), 'ev' => (int) ($v['ev'] ?? 0),
                'at' => is_string($v['at'] ?? null) && strtotime($v['at']) ? $v['at'] : null];
        };

        return response()->json($this->inbox->thread($pageId, $psid, $cursor('before'), $cursor('after'), $request->boolean('events')));
    }

    public function panel(Request $request)
    {
        $this->checkAccess();
        [$pageId, $page, $psid] = $this->target($request);

        return response()->json($this->inbox->panel($pageId, $page, $psid) + ['can_edit' => $this->role() === 'CEO', 'can_send' => $this->canSend()]);
    }

    /**
     * Manual na sagot: send_content (text at/o larawan, walang message tag) sa loob ng 24 oras mula sa huling mensahe
     * ng customer. Itinatala sa chatbot_replies bilang manual. Opsyonal: i-pause ang bot para sa customer na ito.
     */
    public function send(Request $request)
    {
        $this->checkAccess();
        if (!$this->canSend()) abort(403);
        [$pageId, $page, $psid] = $this->target($request);
        $text = trim((string) (is_scalar($request->input('text')) ? $request->input('text') : ''));
        $image = $this->str($request, 'image_url', 2000);
        if (mb_strlen($text) > self::MAX_TEXT) return response()->json(['error' => 'At most ' . self::MAX_TEXT . ' characters.'], 422);
        if ($image !== '' && !FlowEngine::isUrl($image)) return response()->json(['error' => 'The image link must start with http:// or https://.'], 422);
        if ($text === '' && $image === '') return response()->json(['error' => 'Type a message or attach an image link.'], 422);
        if (!$this->inbox->insideWindow($pageId, $psid)) return response()->json(['error' => self::OUTSIDE_WINDOW], 422);

        $messages = [];
        if ($text !== '') $messages[] = ['type' => 'text', 'text' => $text];
        if ($image !== '') $messages[] = ['type' => 'image', 'url' => $image];

        // Pause muna bago ipadala: ang chat na dumating habang naghihintay sa Botcake ay hindi na sasagutin ng bot.
        if ($request->boolean('pause')) (new CustomerFields((int) $pageId, (int) $psid))->set(CustomerFields::BOT_PAUSED, '1');
        $token = trim((string) $this->settings->get($page, 'botcake', 'token'));
        $status = 'failed';
        $error = 'The page has no Botcake token.';
        $http = null;
        if ($token !== '') {
            $r = app(BotcakeClient::class)->shared(sha1($token), 15)->once(15)->sendContent($pageId, $psid, $messages, $token);
            $body = is_array($r['body']) ? $r['body'] : [];
            $http = $r['http'] ?: null;
            if ($r['http'] >= 200 && $r['http'] < 300 && ($body['success'] ?? null) !== false) {
                [$status, $error] = ['sent', null];
            } else {
                $msg = trim((string) ($body['message'] ?? ''));
                $error = ($r['http'] === 0 ? 'Botcake did not answer' : ($msg !== '' ? 'Botcake said "' . mb_substr($msg, 0, 150) . '"' : 'Botcake refused it')) . ' (HTTP ' . $r['http'] . ')';
            }
        }
        $reply = ChatbotReply::create(['page_id' => (int) $pageId, 'psid' => (int) $psid, 'chat_id' => null, 'flow_id' => null, 'node_id' => null,
            'user_id' => Auth::id(), 'kind' => 'manual', 'content' => $messages, 'status' => $status, 'error' => $error, 'http' => $http, 'sent_at' => now()]);

        return response()->json(['item' => ['key' => 'out-' . $reply->id, 'id' => $reply->id, 'side' => 'out', 'at' => Inbox::iso($reply->sent_at),
            'status' => $status, 'error' => $error, 'messages' => $messages, 'manual' => true, 'by' => Auth::user()?->name ?: 'You']]);
    }

    /** "Bot for this customer": On (walang laman) o Off (__bot_paused = 1). */
    public function pause(Request $request)
    {
        $this->checkAccess();
        if (!$this->canSend()) abort(403);
        [$pageId, , $psid] = $this->target($request);
        $fields = new CustomerFields((int) $pageId, (int) $psid);
        if ($request->boolean('paused')) $fields->set(CustomerFields::BOT_PAUSED, '1'); else $fields->clear(CustomerFields::BOT_PAUSED);

        return response()->json(['bot_paused' => $fields->get(CustomerFields::BOT_PAUSED) === '1']);
    }

    /** Ang mga role na puwedeng sumagot (CEO lang ang nagpapalit; laging kasama ang CEO). */
    public function roles(Request $request)
    {
        $this->checkAccess();
        if ($this->role() !== 'CEO') abort(403);
        $roles = array_values(array_unique(array_filter(array_map(fn ($r) => is_string($r) ? mb_substr(preg_replace('/\s+/u', ' ', trim($r)), 0, 100) : '',
            (array) $request->input('roles', [])), fn ($r) => $r !== '' && $r !== 'CEO')));
        DB::table('app_settings')->updateOrInsert(['key' => self::ROLES_SETTING], ['value' => json_encode(array_slice($roles, 0, 50)), 'updated_at' => now(), 'created_at' => now()]);

        return response()->json(['roles' => self::replyRoles()]);
    }
}
