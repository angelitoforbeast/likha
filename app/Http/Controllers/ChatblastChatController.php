<?php

namespace App\Http\Controllers;

use App\Models\BotcakeChat;
use App\Models\PageSetting;
use App\Support\NavAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * /chatblast/chats: ang mga mensahe ng customer na ipinasa ng Botcake (/api/botcake/chat), pagtingin lang, at ang
 * Setup box na may mga value na ilalagay sa JSON API block ng Botcake. Access gaya ng /chatblast (NavAccess; 404 sa
 * iba). Ang buong key ay nasa page para sa CEO lang.
 */
class ChatblastChatController extends Controller
{
    public const PER_PAGE = 100;
    public const CUSTOMER_MAX = 500; // pinakahuling mensahe ng isang customer na ipinapakita

    private function getNormalizedRole(): string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';
        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    private function checkAccess(): void
    {
        if (!NavAccess::allows($this->getNormalizedRole(), '/chatblast')) abort(404);
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
                ->paginate(self::PER_PAGE, ['id', 'page_id', 'page_name', 'psid', 'name', 'message', 'received_at'])
                ->withQueryString();
            // Ang mga page: may Botcake ID sa page settings (mapipili na bago pa dumating ang unang chat, para sa Setup
            // box), at ang mga page_id na nasa chats na.
            $byId = [];
            foreach (PageSetting::where('provider', 'botcake')->where('key', 'page_id')->where('active', true)->orderBy('id')->get() as $s) {
                $id = trim((string) $s->plainValue());
                if ($id !== '') $byId[$id] = (object) ['page_id' => $id, 'page_name' => $s->page_name];
            }
            BotcakeChat::query()->whereNotNull('page_id')->selectRaw('page_id, MAX(page_name) AS page_name')->groupBy('page_id')->get()
                ->each(function ($p) use (&$byId) { $byId[(string) $p->page_id] ??= (object) ['page_id' => (string) $p->page_id, 'page_name' => $p->page_name]; });
            $pages = collect($byId)->sortBy(fn ($p) => mb_strtolower($p->page_name ?? $p->page_id))->values();
        }

        return view('chatblast.chats', [
            'ready' => $ready, 'chats' => $chats, 'pages' => $pages, 'filters' => compact('page', 'date', 'q'),
            'setup' => $this->setup($page),
        ]);
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
            ->get(['id', 'page_id', 'page_name', 'psid', 'name', 'message', 'received_at'])
            ->reverse()->values();

        return view('chatblast.chats-customer', ['chats' => $chats, 'psid' => $psid, 'pageId' => $pageId, 'max' => self::CUSTOMER_MAX]);
    }

    /**
     * Ang mga value para sa JSON API block ng Botcake: POST na may JSON body (inirerekomenda) o GET na may Params. Ang
     * page_id ay ang napiling page sa filter, kung mayroon. Ang buong key ay para sa CEO lang (copy button).
     */
    private function setup(string $pageId): array
    {
        $key = (string) config('services.botcake.chat_key');
        // Mga system variable ng Botcake; walang variable para sa page, kaya fixed text ang page_id kada page.
        $values = [
            'page_id' => $pageId !== '' ? $pageId : "<this page's Botcake ID>",
            'psid'    => '{{user.id}}',
            'name'    => '{{user.full_name}}',
            'message' => '{{last_text_input}}',
        ];

        return [
            'url'    => rtrim((string) config('app.url'), '/') . '/api/botcake/chat',
            'masked' => $key === '' ? null : '••••' . (mb_strlen($key) > 8 ? mb_substr($key, -4) : ''),
            'key'    => $key !== '' && $this->getNormalizedRole() === 'CEO' ? $key : null,
            'body'   => json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'params' => $values,
        ];
    }
}
