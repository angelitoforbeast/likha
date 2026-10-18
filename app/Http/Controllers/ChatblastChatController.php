<?php

namespace App\Http\Controllers;

use App\Models\BotcakeChat;
use App\Models\PageSetting;
use App\Services\BotcakeChatBlock;
use App\Services\BotcakeChatGuard;
use App\Services\PageSettings;
use App\Support\NavAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * /chatblast/chats: ang mga mensahe ng customer na ipinasa ng Botcake (/api/botcake/chat), pagtingin lang, at ang
 * Setup box (ang block na isinusulat ng botcake:chat-setup, kung may key ang bawat page, at ang mga tinanggihan).
 * Access gaya ng /chatblast (NavAccess; 404 sa iba). Walang key na ipinapakita kaninuman.
 */
class ChatblastChatController extends Controller
{
    public const PER_PAGE = 100;
    public const CUSTOMER_MAX = 500; // pinakahuling mensahe ng isang customer na ipinapakita

    public function __construct(private PageSettings $settings, private BotcakeChatGuard $guard)
    {
    }

    private function getNormalizedRole(): string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';
        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    private function checkAccess(): void
    {
        if (!NavAccess::allows($this->getNormalizedRole(), '/chatblast')) abort(request()->is('chatbot', 'chatbot/*') ? 403 : 404); // /chatbot: 403, gaya ng ibang tab nito
    }

    /** Ang query param bilang string ('' kapag wala o hindi string). */
    private function str(Request $request, string $key, int $max = 255): string
    {
        $v = $request->query($key);
        return is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
    }

    public function index(Request $request)
    {
        $this->checkAccess();
        $ready = Schema::hasTable('botcake_chats'); // hindi pa na-migrate (mano-mano pagkatapos ng deploy)

        $page = $this->str($request, 'page_id', 64); // hindi 'page': iyon ang pahina ng paginate
        $date = $this->str($request, 'date', 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) $date = '';
        $q = $this->str($request, 'q', 100);

        $chats = null;
        $pages = [];
        if ($ready) {
            $chats = BotcakeChat::query()
                ->when($page !== '', fn ($w) => $w->where('page_id', $page))
                ->when($date !== '', function ($w) use ($date) {
                    $day = Carbon::parse($date, config('app.timezone'))->startOfDay();
                    $w->where('received_at', '>=', $day)->where('received_at', '<', $day->copy()->addDay());
                })
                ->when($q !== '', function ($w) use ($q) {
                    // Ang % at _ ng hanap ay letra, hindi wildcard ('!' ang escape, pareho sa mysql, pgsql at sqlite).
                    $like = '%' . strtr(mb_strtolower($q), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
                    $w->where(fn ($o) => $o->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like])->orWhereRaw("LOWER(message) LIKE ? ESCAPE '!'", [$like]));
                })
                ->orderByDesc('received_at')->orderByDesc('id')
                // Walang COUNT(*) sa buong table: Newer/Older lang.
                ->simplePaginate(self::PER_PAGE, ['id', 'page_id', 'page_name', 'psid', 'name', 'message', 'payload', 'received_at'])
                ->withQueryString();
            // Ang mga page: may Botcake ID sa page settings (mapipili na bago pa dumating ang unang chat, para sa Setup
            // box), at ang ibang page_id na nasa chats na (Unknown page). Parehong naka-cache.
            $byId = [];
            foreach (BotcakeChat::pageMap() as $id => $name) $byId[(string) $id] = (object) ['page_id' => (string) $id, 'page_name' => $name];
            foreach (BotcakeChat::pageIds() as $id) $byId[$id] ??= (object) ['page_id' => $id, 'page_name' => null];
            $pages = collect($byId)->sortBy(fn ($p) => [$p->page_name === null ? 1 : 0, mb_strtolower($p->page_name ?? $p->page_id)])->values();
        }

        return view('chatblast.chats', [
            'ready' => $ready, 'chats' => $chats, 'pages' => $pages, 'filters' => compact('page', 'date', 'q'),
            'setup' => $this->setup(), 'chatsRoute' => $this->chatsRoute(),
        ]);
    }

    /** Ang Chats ay nasa /chatblast at /chatbot: ang mga link ay nananatili sa seksyong binuksan. */
    private function chatsRoute(): string
    {
        return request()->is('chatbot', 'chatbot/*') ? 'chatbot.chats' : 'chatblast.chats';
    }

    /** Ang mga mensahe ng isang customer (page + PSID), pinakaluma muna. */
    public function customer(Request $request)
    {
        $this->checkAccess();
        $pageId = $this->str($request, 'page_id', 64);
        $psid = $this->str($request, 'psid', 64);
        abort_if($psid === '' || !Schema::hasTable('botcake_chats'), 404);

        $chats = BotcakeChat::query()
            ->when($pageId !== '', fn ($w) => $w->where('page_id', $pageId), fn ($w) => $w->whereNull('page_id'))
            ->where('psid', $psid)
            ->orderByDesc('received_at')->orderByDesc('id')->limit(self::CUSTOMER_MAX)
            ->get(['id', 'page_id', 'page_name', 'psid', 'name', 'message', 'payload', 'received_at'])
            ->reverse()->values();

        // Ang customer fields ng chatbot (walang run state na "__"); ang CEO ang nakakapagbago (ChatbotTabsController).
        $fields = ctype_digit($pageId) && ctype_digit($psid) && Schema::hasTable('chatbot_customer_values')
            ? (new \App\Services\Chatbot\CustomerFields((int) $pageId, (int) $psid))->fields() : null;
        if ($fields !== null) ksort($fields);

        return view('chatblast.chats-customer', ['chats' => $chats, 'psid' => $psid, 'pageId' => $pageId, 'max' => self::CUSTOMER_MAX,
            'chatsRoute' => $this->chatsRoute(), 'fields' => $fields, 'canEditFields' => $this->getNormalizedRole() === 'CEO']);
    }

    /**
     * Ang Setup box: ang block na isinusulat ng botcake:chat-setup (walang key), kung may sariling chat.key ang bawat page
     * (oo/hindi, hindi kailanman ang value), at ang mga tinanggihan ngayong araw.
     */
    private function setup(): array
    {
        $withKey = [];
        foreach (PageSetting::where('provider', 'botcake')->where('key', 'chat.key')->where('active', true)->pluck('page_name') as $name) {
            $withKey[$this->settings->pageKey((string) $name)] = true;
        }
        $pages = [];
        foreach (BotcakeChat::pageMap() as $id => $name) {
            $pages[] = ['name' => $name, 'page_id' => (string) $id, 'has_key' => isset($withKey[$this->settings->pageKey($name)])];
        }
        usort($pages, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        $refused = [];
        foreach ($this->guard->counts() as $reason => $n) $refused[BotcakeChatGuard::REASONS[$reason]] = $n;

        return [
            'url'     => rtrim((string) config('app.url'), '/') . '/api/botcake/chat',
            'body'    => json_encode(BotcakeChatBlock::BODY, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'pages'   => $pages,
            'refused' => $refused,
        ];
    }
}
