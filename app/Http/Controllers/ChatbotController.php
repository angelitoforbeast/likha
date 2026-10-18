<?php

namespace App\Http\Controllers;

use App\Models\BotcakeChat;
use App\Models\PageSetting;
use App\Services\BotcakeChatBlock;
use App\Services\BotcakePageSetup;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use App\Support\NavAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * /chatbot: ang listahan ng page ng sariling chatbot. Isang row kada page na may Botcake ID at token; ang ACTIVE (On/Off),
 * FLOW at mga botfield ay nasa page_settings (provider chatbot). Access gaya ng /chatblast (NavAccess; 403 sa iba); ang
 * On/Off at ang pagdagdag ng page ay CEO lang, dahil binabago nito ang live na Botcake page. Walang token o key na
 * ibinabalik.
 */
class ChatbotController extends Controller
{
    public function __construct(private PageSettings $settings, private ChatbotFields $fields)
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

    private function checkCeo(): void
    {
        $this->checkAccess();
        if ($this->role() !== 'CEO') abort(403);
    }

    public function index()
    {
        $this->checkAccess();
        $listed = array_map(fn ($p) => $this->settings->pageKey($p), $this->pages());
        // Para sa "+ Page": ang mga page sa page settings na wala pa sa listahan.
        $others = PageSetting::where('active', true)->distinct()->orderBy('page_name')->pluck('page_name')
            ->unique(fn ($p) => $this->settings->pageKey($p))->reject(fn ($p) => in_array($this->settings->pageKey($p), $listed, true))->values();

        return view('chatbot.index', ['isCeo' => $this->role() === 'CEO', 'otherPages' => $others]);
    }

    /** Ang mga column at row (isang query ng settings, at ang chats ngayong araw at ang huli kada page). */
    public function data()
    {
        $this->checkAccess();
        $columns = $this->fields->columns();
        $pages = $this->pages();

        $byPage = PageSetting::where('active', true)->whereIn('provider', ['botcake', ChatbotFields::PROVIDER])
            ->whereIn('key', array_merge(['page_id', 'active'], array_column($columns, 'key')))->orderBy('id')->get()
            ->groupBy(fn ($s) => $this->settings->pageKey($s->page_name));
        $stats = $this->chatStats();

        $rows = [];
        foreach ($pages as $page) {
            $cells = [];
            $pageId = null;
            $active = true;
            foreach ($byPage[$this->settings->pageKey($page)] ?? [] as $s) {
                if ($s->provider === 'botcake') {
                    if ($s->key === 'page_id') $pageId = (string) $s->plainValue();
                    continue;
                }
                if ($s->key === 'active') $active = $s->plainValue() !== '0';
                else $cells[$s->key] = $s->plainValue();
            }
            $rows[] = [
                'page' => $page, 'page_id' => $pageId, 'active' => $active, 'cells' => (object) $cells,
                'last_chat' => $stats['last'][$pageId] ?? null, 'today' => (int) ($stats['today'][$pageId] ?? 0),
            ];
        }

        return response()->json(['columns' => $columns, 'rows' => $rows, 'flows' => $this->flowNames()]);
    }

    /** Auto-save ng isang botfield. Blangko = burahin. Last write wins. */
    public function cell(Request $request)
    {
        $this->checkAccess();
        $page = $this->listedPage($this->str($request, 'page_name'));
        $key = strtolower($this->str($request, 'key'));
        $value = trim($this->str($request, 'value', ChatbotFields::MAX_VALUE + 1));
        if ($page === null) return response()->json(['error' => 'This page is not on the chatbot list.'], 422);
        if (in_array($key, ChatbotFields::RESERVED, true)) return response()->json(['error' => 'ACTIVE is changed with the On/Off switch.'], 422);
        if (!$this->fields->isColumn($key)) return response()->json(['error' => 'Unknown column.'], 422);
        if (mb_strlen($value) > ChatbotFields::MAX_VALUE) return response()->json(['error' => 'At most ' . ChatbotFields::MAX_VALUE . ' characters.'], 422);
        if ($value !== '' && $key === 'flow' && !in_array($value, $this->flowNames(), true)) {
            return response()->json(['error' => 'Pick a flow from the list.'], 422);
        }
        if ($value !== '' && ChatbotFields::kind($key) === 'image' && !$this->isImageUrl($value)) {
            return response()->json(['error' => 'Use an image link that starts with http:// or https://.'], 422);
        }

        if ($value === '') {
            $actives = $this->settings->pageQuery($page)->where('provider', ChatbotFields::PROVIDER)->where('key', $key)->where('active', true)->get();
            foreach ($actives as $s) $this->settings->deactivate($s, Auth::id());
            return response()->json(['status' => $actives->isEmpty() ? 'unchanged' : 'cleared', 'value' => null]);
        }

        $res = $this->settings->set($page, ChatbotFields::PROVIDER, $key, $value, false, Auth::id());

        return response()->json(['status' => $res['status'], 'value' => $res['setting']->plainValue()]);
    }

    /** "+ Column": bagong botfield para sa lahat ng page. */
    public function column(Request $request)
    {
        $this->checkAccess();
        $col = $this->fields->add($this->str($request, 'name', 100));
        if ($col === null) return response()->json(['error' => 'Use letters or numbers (ACTIVE is taken); at most ' . ChatbotFields::MAX_ADDED . ' added columns.'], 422);

        return response()->json($col + ['kind' => ChatbotFields::kind($col['key'])]);
    }

    /** ACTIVE On (isulat ang block at i-publish) o Off (i-unpublish lang). Ang error ng Botcake ay walang binabago dito. */
    public function active(Request $request)
    {
        $this->checkCeo();
        $page = $this->listedPage($this->str($request, 'page_name'));
        if ($page === null) return response()->json(['error' => 'This page is not on the chatbot list.'], 422);

        $chatBlock = app(BotcakeChatBlock::class);
        $r = $request->boolean('on') ? $chatBlock->turnOn($page) : $chatBlock->turnOff($page);

        return response()->json([
            'active' => $this->settings->get($page, ChatbotFields::PROVIDER, 'active') !== '0',
            'error' => $r['ok'] ? null : $r['error'],
        ]);
    }

    /**
     * "+ Page": bago (pangalan, Botcake ID at token) o mula sa page na nasa page settings na (ang kulang lang ang
     * kailangan). Isinusulat gaya ng page settings (PageSettings::set; ang token ay secret), saka ang Test token nito.
     */
    public function addPage(Request $request)
    {
        $this->checkCeo();
        $page = $this->settings->normPage($this->str($request, 'page_name'));
        $pageId = $this->str($request, 'page_id', 100);
        $token = $this->str($request, 'token', 1000);
        if ($page === '') return response()->json(['error' => 'The page name is required.'], 422);
        if ($this->listedPage($page) !== null) return response()->json(['error' => 'This page is already on the list.'], 422);

        $pageId = $pageId !== '' ? $pageId : trim((string) $this->settings->get($page, 'botcake', 'page_id'));
        $hasToken = $token !== '' || trim((string) $this->settings->get($page, 'botcake', 'token')) !== '';
        if ($pageId === '' || !$hasToken) return response()->json(['error' => 'A Botcake ID and a token are needed.'], 422);
        if ($errors = $this->settings->validate($page, 'botcake', 'page_id', $pageId)) return response()->json(['error' => reset($errors)], 422);
        // Isang Botcake ID, isang page: ang pangalawang page na may parehong ID ay aagaw sa mga chat ng una (mawawala).
        $owner = PageSetting::where('provider', 'botcake')->where('key', 'page_id')->where('active', true)->where('value', $pageId)
            ->pluck('page_name')->first(fn ($p) => $this->settings->pageKey($p) !== $this->settings->pageKey($page));
        if ($owner !== null) return response()->json(['error' => "Botcake ID {$pageId} is already used by {$owner}."], 422);

        $this->settings->set($page, 'botcake', 'page_id', $pageId, false, Auth::id());
        if ($token !== '') $this->settings->set($page, 'botcake', 'token', $token, true, Auth::id());
        BotcakeChat::forgetPageMap();

        try {
            $check = app(BotcakePageSetup::class)->check($page);
        } catch (Throwable $e) {
            report($e);
            $check = ['status' => 'error', 'error' => 'Botcake: no answer'];
        }

        return response()->json(['page' => $this->listedPage($page), 'token_test' => $check['status'], 'error' => $check['error'] ?? null]);
    }

    /** @return array<int, string> ang mga page na may Botcake ID at token, ayon sa pangalan */
    private function pages(): array
    {
        $pages = $this->settings->pagesWith('botcake', ['page_id', 'token']);
        usort($pages, 'strcasecmp');

        return $pages;
    }

    /** Ang pangalan (gaya ng naitala) kapag nasa listahan ang page, kung hindi null. */
    private function listedPage(string $page): ?string
    {
        $key = $this->settings->pageKey($page);
        foreach ($this->pages() as $p) if ($this->settings->pageKey($p) === $key) return $p;

        return null;
    }

    /**
     * Ang chats ngayong araw at ang huling chat kada Botcake page ID (naka-cache 1 minuto). Ang huli: isang MAX kada page
     * sa index na (page_id, received_at), hindi isang GROUP BY sa buong table.
     * @return array{today: array<string, int>, last: array<string, string>}
     */
    private function chatStats(): array
    {
        if (!Schema::hasTable('botcake_chats')) return ['today' => [], 'last' => []];

        return Cache::remember('chatbot:chat-stats', 60, function () {
            $today = BotcakeChat::where('received_at', '>=', now('Asia/Manila')->startOfDay()->timezone(config('app.timezone')))
                ->whereNotNull('page_id')->selectRaw('page_id, COUNT(*) AS n')->groupBy('page_id')->pluck('n', 'page_id')->map(fn ($n) => (int) $n)->all();
            $last = [];
            foreach (array_keys(BotcakeChat::pageMap()) as $id) {
                $at = BotcakeChat::where('page_id', (string) $id)->max('received_at');
                if ($at) $last[(string) $id] = \Carbon\Carbon::parse($at)->timezone('Asia/Manila')->format('Y-m-d H:i');
            }
            return ['today' => $today, 'last' => $last];
        });
    }

    /** @return array<int, string> ang mga flow (para sa FLOW na dropdown) */
    private function flowNames(): array
    {
        return Schema::hasTable('chatbot_flows') ? \App\Models\ChatbotFlow::orderBy('name')->pluck('name')->all() : [];
    }

    private function isImageUrl(string $value): bool
    {
        return (bool) filter_var($value, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function str(Request $request, string $key, int $max = 255): string
    {
        $v = $request->input($key);
        return is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
    }
}
