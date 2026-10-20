<?php

namespace App\Http\Controllers;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Models\ChatbotReply;
use App\Services\Chatbot\BotcakeOutbox;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotVars;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowEngine;
use App\Services\Chatbot\FlowRun;
use App\Services\Chatbot\TestOutbox;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\ChatbotSheet;
use App\Services\Chatbot\TestHistory;
use App\Services\PageSettings;
use App\Support\NavAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Ang mga tab ng /chatbot: Replies (ang talaan ng padala), Test (ang flow para sa test customer, walang Botcake o
 * sheet), at ang customer fields (CEO lang ang nagbabago). Access gaya ng /chatblast (403 sa iba).
 */
class ChatbotTabsController extends Controller
{
    public const PER_PAGE = 100;

    public function __construct(private PageSettings $settings)
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

    private function str(Request $request, string $key, int $max = 255): string
    {
        $v = $request->input($key);
        return is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
    }

    /** Ang mga page na may Botcake ID (pangalan => ID), ayon sa pangalan. */
    private function pages(): array
    {
        $pages = array_flip(BotcakeChat::pageMap());
        ksort($pages, SORT_NATURAL | SORT_FLAG_CASE);

        return $pages;
    }

    // ---- Replies ----

    public function replies()
    {
        $this->checkAccess();

        return view('chatbot.replies', ['pages' => $this->pages(), 'repliesOn' => BotcakeOutbox::enabled(), 'today' => now('Asia/Manila')->toDateString()]);
    }

    /**
     * Ang mga padala ng isang araw (laging may petsa: walang full scan), pinakabago muna, 100 kada pahina (?before=id).
     * Ang hanap ay sa laman at sa error ng araw na iyon lang.
     */
    public function repliesData(Request $request)
    {
        $this->checkAccess();
        $date = $this->str($request, 'date', 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) $date = now('Asia/Manila')->toDateString();
        $day = Carbon::parse($date, 'Asia/Manila')->startOfDay()->timezone(config('app.timezone'));
        $pageId = $this->str($request, 'page_id', 20);
        $status = $this->str($request, 'status', 10);
        $q = $this->str($request, 'q', 100);
        $before = (int) $this->str($request, 'before', 20);

        $rows = ChatbotReply::where('sent_at', '>=', $day)->where('sent_at', '<', $day->copy()->addDay())
            ->when(ctype_digit($pageId), fn ($w) => $w->where('page_id', (int) $pageId))
            ->when(in_array($status, ['sent', 'failed', 'skipped'], true), fn ($w) => $w->where('status', $status))
            ->when($q !== '', function ($w) use ($q) {
                $like = '%' . strtr(mb_strtolower($q), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
                // Ang JSON ay ginagawang text bago hanapin (mysql, pgsql at sqlite).
                $text = match (DB::getDriverName()) { 'pgsql' => 'content::text', 'mysql' => 'CAST(content AS CHAR)', default => 'content' };
                $w->where(fn ($o) => $o->whereRaw("LOWER($text) LIKE ? ESCAPE '!'", [$like])->orWhereRaw("LOWER(error) LIKE ? ESCAPE '!'", [$like]));
            })
            ->when($before > 0, fn ($w) => $w->where('id', '<', $before))
            ->orderByDesc('id')->limit(self::PER_PAGE + 1)->get();
        $more = $rows->count() > self::PER_PAGE;
        $names = BotcakeChat::pageMap();

        return response()->json([
            'date' => $date,
            'rows' => $rows->take(self::PER_PAGE)->map(fn ($r) => [
                'id' => $r->id, 'time' => $r->sent_at?->timezone('Asia/Manila')->format('H:i:s'), 'page' => $names[(string) $r->page_id] ?? ('Page ' . $r->page_id),
                'psid' => (string) $r->psid, 'kind' => $r->kind, 'status' => $r->status, 'error' => $r->error, 'summary' => $this->summary($r),
            ])->values(),
            'next' => $more ? $rows->take(self::PER_PAGE)->last()->id : null,
        ]);
    }

    public function reply(int $id)
    {
        $this->checkAccess();
        $r = ChatbotReply::findOrFail($id);

        return response()->json([
            'id' => $r->id, 'time' => $r->sent_at?->timezone('Asia/Manila')->format('Y-m-d H:i:s'),
            'page' => BotcakeChat::pageMap()[(string) $r->page_id] ?? ('Page ' . $r->page_id), 'page_id' => (string) $r->page_id, 'psid' => (string) $r->psid,
            'chat_id' => $r->chat_id, 'flow' => $r->flow_id ? ChatbotFlow::whereKey($r->flow_id)->value('name') : null, 'node' => $r->node_id,
            'kind' => $r->kind, 'status' => $r->status, 'error' => $r->error, 'http' => $r->http, 'content' => $r->content,
        ]);
    }

    /** @var array<int, string> pangalan ng user kada id, isang basa lang kada request */
    private array $userNames = [];

    private function summary(ChatbotReply $r): string
    {
        $c = (array) $r->content;
        if ($r->kind === 'sheet') return 'Sheet row: ' . implode(' | ', array_map('strval', (array) ($c['row'] ?? [])));

        $text = implode(' · ', array_map(fn ($m) => ($m['type'] ?? '') === 'image' ? '[image] ' . ($m['url'] ?? '') : (string) ($m['text'] ?? ''), $c));
        // Ang manual na sagot mula sa /chatbot/inbox: kita kung sino ang nagpadala.
        if ($r->kind === 'manual') return 'Manual by ' . (($this->userNames[(int) $r->user_id] ??= (string) \App\Models\User::whereKey($r->user_id)->value('name')) ?: 'a user') . ': ' . $text;

        return $text;
    }

    // ---- Test ----

    public function test(Request $request)
    {
        $this->checkAccess();
        $names = ChatbotFlow::pluck('name', 'id');
        $flows = DB::table('page_settings')->where('provider', 'chatbot')->where('key', 'flow_id')->where('active', true)->pluck('value', 'page_name')
            ->map(fn ($id) => $names[(int) $id] ?? null)->filter();

        return view('chatbot.test', [
            'pages' => $this->pages(), 'flows' => ChatbotFlow::orderBy('name')->pluck('name', 'id'), 'pageFlows' => $flows,
            'repliesOn' => BotcakeOutbox::enabled(),
            // Mula sa Preview / Test ng editor: ang flow na iyon at ang draft.
            'pickFlow' => (int) $this->str($request, 'flow', 20), 'pickVersion' => $this->str($request, 'version', 10) === 'published' ? 'published' : 'draft',
        ]);
    }

    /**
     * Isang mensahe ng test customer (PSID 0, hiwalay sa totoong customer): ang flow ng page (o ang napili), sa
     * TestOutbox (walang Botcake, walang sheet). Ang AI step ay totoong tumatawag sa OpenAI. version: ang draft (default)
     * o ang published; ang live na takbo ay laging published.
     */
    public function testSend(Request $request, ChatbotPage $chatbotPage, FlowEngine $engine)
    {
        $this->checkAccess();
        [$page, $pageId] = $this->testPage($request);
        if ($page === null) return response()->json(['error' => 'Pick a page that has a Botcake ID.'], 422);
        $flowId = (int) $this->str($request, 'flow_id', 20);
        $flow = $flowId ? ChatbotFlow::find($flowId) : $this->pageFlow($page);
        $version = $this->str($request, 'version', 10) === 'published' ? 'published' : 'draft';
        $graph = $flow ? ($version === 'published' ? $flow->graph : $flow->draft()) : null;
        if (!is_array($graph)) return response()->json(['error' => 'This page has no flow. Pick one.'], 422);

        $fields = new CustomerFields((int) $pageId, CustomerFields::TEST_PSID);
        $message = $this->str($request, 'message', 2000);
        $run = new FlowRun($flow->id, $graph, $page, $pageId, $chatbotPage->botfields($page), $fields, null,
            $message, 'Test Customer', now('Asia/Manila')->format('Y-m-d H:i'), true);
        $engine->run($run, $this->testOutbox($request));
        TestHistory::record($fields, $message, $run);

        return response()->json($this->testResult($flow, $version, $run, $fields));
    }

    /**
     * Ang skip timer ng test customer (ang Test page ang nagtatanong habang may hinihintay): kapag lampas na sa oras, o
     * "Skip now", nagpapatuloy ang takbo sa port na skipped, sa parehong engine at TestOutbox (walang Botcake, walang
     * chatbot_waits). ran = false kapag wala pa o walang hinihintay.
     */
    public function testSkip(Request $request, ChatbotPage $chatbotPage, FlowEngine $engine)
    {
        $this->checkAccess();
        [$page, $pageId] = $this->testPage($request);
        if ($page === null) return response()->json(['error' => 'Pick a page that has a Botcake ID.'], 422);
        $fields = new CustomerFields((int) $pageId, CustomerFields::TEST_PSID);
        $due = $fields->get(CustomerFields::TEST_SKIP);
        $wait = (string) $fields->get(CustomerFields::WAIT);
        if ($due === null || $wait === '') return response()->json(['ran' => false, 'skip_in' => null]);
        if (!$request->boolean('now') && Carbon::parse($due)->isFuture()) return response()->json(['ran' => false, 'skip_in' => $this->skipIn($fields)]);

        [$flowId, $nodeId] = array_pad(explode(':', $wait, 2), 2, '');
        $flow = ChatbotFlow::find((int) $flowId);
        $version = $this->str($request, 'version', 10) === 'published' ? 'published' : 'draft';
        $graph = $flow ? ($version === 'published' ? $flow->graph : $flow->draft()) : null;
        $node = collect(is_array($graph) ? $graph['nodes'] ?? [] : [])->firstWhere('id', $nodeId);
        $fields->clear(CustomerFields::TEST_SKIP);
        if (!$node) return response()->json(['ran' => false, 'skip_in' => null, 'error' => 'The waiting step is not in this flow any more.']);

        $run = new FlowRun($flow->id, $graph, $page, $pageId, $chatbotPage->botfields($page), $fields, null,
            '', 'Test Customer', now('Asia/Manila')->format('Y-m-d H:i'), true);
        $run->skippedAfter = FlowGraph::skipLabel((int) FlowGraph::skipMinutes((array) ($node['config'] ?? [])));
        $engine->run($run, $this->testOutbox($request));
        TestHistory::record($fields, null, $run);

        return response()->json(['ran' => true] + $this->testResult($flow, $version, $run, $fields));
    }

    /** Ang outbox ng Test: ang sheet ay isinusulat lang kapag naka-on ang "Write sheet rows for real". */
    private function testOutbox(Request $request): TestOutbox
    {
        return new TestOutbox($request->boolean('write_sheet') ? app(ChatbotSheet::class) : null);
    }

    /** Ilang segundo pa bago lumaktaw ang hinihintay ng test customer, o null. */
    private function skipIn(CustomerFields $fields): ?int
    {
        $due = $fields->get(CustomerFields::TEST_SKIP);
        if ($due === null || $fields->get(CustomerFields::WAIT) === null) return null;

        return max(0, (int) ceil(now()->diffInSeconds(Carbon::parse($due), false)));
    }

    private function testResult(ChatbotFlow $flow, string $version, FlowRun $run, CustomerFields $fields): array
    {
        $now = $fields->fields();
        ksort($now);

        return [
            'flow' => $flow->name, 'version' => $version, 'path' => $run->path, 'sends' => $run->sends,
            // Ang linya sa Test chat: ang naisulat (TestOutbox) o "not written" kapag ipinapakita lang.
            'sheet_rows' => array_map(fn ($r) => $r + ['note' => $r['status'] === 'test' ? 'Sheet row (not written): ' . $r['tab'] : null], $run->sheetRows),
            'changes' => $fields->changes, 'fields' => (object) $now, 'stopped' => $run->stopped,
            'waiting' => $fields->get(CustomerFields::WAIT) !== null, 'skip_in' => $this->skipIn($fields),
        ];
    }

    public function testReset(Request $request)
    {
        $this->checkAccess();
        [$page, $pageId] = $this->testPage($request);
        if ($page === null) return response()->json(['error' => 'Pick a page that has a Botcake ID.'], 422);
        DB::table('chatbot_customer_values')->where('page_id', (int) $pageId)->where('psid', CustomerFields::TEST_PSID)->delete();

        return response()->json(['ok' => true]);
    }

    /** @return array{0: ?string, 1: ?string} [pangalan ng page, Botcake ID] */
    private function testPage(Request $request): array
    {
        $key = $this->settings->pageKey($this->str($request, 'page_name'));
        foreach ($this->pages() as $name => $id) {
            if ($this->settings->pageKey((string) $name) === $key && ctype_digit((string) $id)) return [(string) $name, (string) $id];
        }

        return [null, null];
    }

    private function pageFlow(string $page): ?ChatbotFlow
    {
        // Ang flow na nakatali sa page (dropdown), kahit naka-Off ang bot: ang Test ay para subukan bago i-On.
        $id = trim((string) $this->settings->get($page, 'chatbot', 'flow_id'));

        return ctype_digit($id) ? ChatbotFlow::find((int) $id) : null;
    }

    // ---- Customer fields ----

    /** Palitan o burahin ang isang customer field (CEO). Ang run state ("__") ay hindi binabago dito. */
    public function customerFields(Request $request)
    {
        $this->checkAccess();
        if ($this->role() !== 'CEO') abort(403);
        $pageId = $this->str($request, 'page_id', 20);
        $psid = $this->str($request, 'psid', 20);
        $raw = $this->str($request, 'key', 100);
        $key = ChatbotVars::norm($raw);
        if (!ctype_digit($pageId) || !ctype_digit($psid)) return response()->json(['error' => 'Unknown customer.'], 422);
        if ($key === '' || str_starts_with($raw, '__')) return response()->json(['error' => 'Use a field name with letters or numbers.'], 422);

        $fields = new CustomerFields((int) $pageId, (int) $psid);
        if ($request->boolean('clear')) $fields->clear($key);
        else $fields->set($key, $this->str($request, 'value', 16000));

        return response()->json(['ok' => true, 'key' => $key]);
    }
}
