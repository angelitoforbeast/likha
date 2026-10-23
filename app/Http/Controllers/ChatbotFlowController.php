<?php

namespace App\Http\Controllers;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Models\PageSetting;
use App\Models\User;
use App\Services\Chatbot\BotcakeOutbox;
use App\Services\Chatbot\ChatbotTags;
use App\Services\Chatbot\ChatbotVars;
use App\Services\Chatbot\FlowGraph;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use App\Support\NavAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * /chatbot (Flow): ang listahan ng flow at ang editor (canvas). Access gaya ng /chatblast at ng mga cell ng page list
 * (NavAccess; 403 sa iba). Ang graph ay sinusuri ng FlowGraph::clean bago i-save (iisang patakaran sa engine).
 */
class ChatbotFlowController extends Controller
{
    /** Ang mga variable ng takbo (para sa listahan sa mga form ng node). */
    public const RUN_VARS = ['last_input', 'full_name', 'first_name', 'psid', 'page_id', 'page_name', 'current_date', 'last_interaction', 'replies_via'];

    /** Ang tooltip ng bawat variable ng takbo ("Built in: …"). */
    private const RUN_VAR_INFO = [
        'last_input' => 'what the customer just typed', 'full_name' => 'the customer\'s name on Facebook', 'first_name' => 'the first word of the customer\'s name',
        'psid' => 'the customer\'s ID on this page', 'page_id' => 'the Facebook ID of the page', 'page_name' => 'the name of the page',
        'current_date' => 'today\'s date in Manila (YYYY-MM-DD)', 'last_interaction' => 'when the customer\'s last chat came in',
        'replies_via' => 'which bot carries this chat: Botcake or Forbeast',
    ];

    public const KEEP_VERSIONS = 50; // snapshot kada flow
    public const LIST_VERSIONS = 20; // sa History

    public function __construct(private PageSettings $settings, private ChatbotFields $fields)
    {
    }

    private function checkAccess(): void
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';
        if (!NavAccess::allows(preg_replace('/\s+/u', ' ', trim((string) $raw)), '/chatblast')) abort(403);
    }

    public function index()
    {
        $this->checkAccess();
        $usedBy = PageSetting::where('provider', ChatbotFields::PROVIDER)->where('key', 'flow_id')->where('active', true)->get(['page_name', 'value', 'is_secret'])
            ->groupBy(fn ($s) => (string) $s->plainValue())->map(fn ($g) => $g->pluck('page_name')->sort()->values()->all());

        return view('chatbot.flows', [
            'flows' => ChatbotFlow::orderBy('name')->get(['id', 'name', 'updated_at']),
            'usedBy' => $usedBy,
            'repliesOn' => BotcakeOutbox::enabled(),
        ]);
    }

    public function store(Request $request)
    {
        $this->checkAccess();
        $name = $this->name($request);
        if ($error = $this->nameError($name)) return response()->json(['error' => $error], 422);

        $flow = ChatbotFlow::create(['name' => $name, 'graph' => FlowGraph::blank(), 'updated_by' => Auth::id()]);

        return response()->json(['id' => $flow->id, 'name' => $flow->name]);
    }

    /** Bagong pangalan; ang mga page na gumagamit ng lumang pangalan ay sumusunod. */
    public function rename(Request $request, int $id)
    {
        $this->checkAccess();
        $flow = ChatbotFlow::findOrFail($id);
        $name = $this->name($request);
        if ($name === $flow->name) return response()->json(['id' => $flow->id, 'name' => $flow->name]);
        if ($error = $this->nameError($name, $flow->id)) return response()->json(['error' => $error], 422);

        DB::transaction(function () use ($flow, $name) {
            foreach ($this->pagesUsing($flow->id) as $page) $this->settings->set($page, ChatbotFields::PROVIDER, 'flow', $name, false, Auth::id());
            $flow->update(['name' => $name, 'updated_by' => Auth::id()]);
        });

        return response()->json(['id' => $flow->id, 'name' => $name]);
    }

    public function duplicate(int $id)
    {
        $this->checkAccess();
        $flow = ChatbotFlow::findOrFail($id);
        $base = mb_substr($flow->name, 0, 90) . ' (copy';
        $name = $base . ')';
        for ($i = 2; ChatbotFlow::where('name', $name)->exists(); $i++) $name = $base . ' ' . $i . ')';

        $copy = ChatbotFlow::create(['name' => $name, 'graph' => $flow->graph, 'draft_graph' => $flow->draft_graph, 'updated_by' => Auth::id()]);

        return response()->json(['id' => $copy->id, 'name' => $copy->name]);
    }

    /** Hindi binubura ang flow na gamit pa ng page (titigil ang bot ng page nang walang paalam). */
    public function destroy(int $id)
    {
        $this->checkAccess();
        $flow = ChatbotFlow::findOrFail($id);
        $pages = $this->pagesUsing($flow->id);
        if ($pages) {
            return response()->json(['error' => implode(', ', $pages) . ' ' . (count($pages) === 1 ? 'uses' : 'use') . ' this flow. Pick another flow for ' . (count($pages) === 1 ? 'it' : 'them') . ' first.'], 422);
        }
        DB::transaction(function () use ($flow) {
            DB::table('chatbot_flow_versions')->where('flow_id', $flow->id)->delete();
            $flow->delete();
        });

        return response()->json(['ok' => true]);
    }

    public function edit(int $id)
    {
        $this->checkAccess();
        $flow = ChatbotFlow::findOrFail($id);

        $encode = app(\App\Services\OrderEncode\Settings::class);

        return view('chatbot.editor', ['flow' => $flow, 'repliesOn' => BotcakeOutbox::enabled(), 'encodeModels' => ['extract' => $encode->modelExtract(), 'checker' => $encode->modelChecker()]]);
    }

    /**
     * Ang draft (o ang published kapag walang draft), ang kalagayan, ang mga page na gumagamit, ang mga variable na
     * magagamit, at ang mga patakaran (para sa mga form ng node).
     */
    public function graph(int $id)
    {
        $this->checkAccess();
        $flow = ChatbotFlow::findOrFail($id);

        return response()->json($this->state($flow) + [
            'id' => $flow->id, 'name' => $flow->name, 'graph' => $flow->draft() ?? FlowGraph::blank(),
            'variables' => $this->variables($groups = $this->variableGroups($flow)), 'variable_groups' => $groups, 'rules' => FlowGraph::RULES, 'pages' => $this->pagesUsing($flow->id),
            // Para sa step na Sequence (wala pang table = walang listahan).
            'sequences' => \Illuminate\Support\Facades\Schema::hasTable('chatbot_sequences')
                ? \App\Models\ChatbotSequence::orderBy('name')->limit(500)->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all() : [],
        ]);
    }

    /**
     * I-save ang draft (hindi ang live). Kailangan ang version na binuksan ng editor: kapag may ibang nag-save mula noon,
     * 409 (maliban kung overwrite). Ang draft na kapareho ng published ay walang draft.
     */
    public function saveGraph(Request $request, int $id)
    {
        $this->checkAccess();
        $flow = ChatbotFlow::findOrFail($id);
        try {
            $graph = FlowGraph::clean($request->input('graph'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        if (!$this->write($flow, $request, ['draft_graph' => $this->draftValue($flow, $graph), 'updated_by' => Auth::id()], $request->boolean('overwrite'))) {
            return $this->conflict();
        }

        return response()->json($this->state($flow->fresh()) + ['saved_at' => now('Asia/Manila')->format('H:i'), 'graph' => $graph]);
    }

    /** Ang draft ay nagiging live (graph), kasama ang snapshot, sa iisang transaction. */
    public function publish(Request $request, int $id)
    {
        $this->checkAccess();
        $note = mb_substr(trim(preg_replace('/\s+/u', ' ', is_scalar($request->input('note')) ? (string) $request->input('note') : '')), 0, 200);

        return DB::transaction(function () use ($request, $id, $note) {
            $flow = ChatbotFlow::lockForUpdate()->findOrFail($id);
            if ($this->version($request) !== $flow->draft_version) return $this->conflict();
            if ($flow->published_at !== null && $flow->draft_graph === null) return response()->json(['error' => 'There are no unpublished changes.'], 422);
            try {
                $graph = FlowGraph::clean($flow->draft());
                if ($problem = FlowGraph::publishProblems($graph)) throw new InvalidArgumentException($problem);
            } catch (InvalidArgumentException $e) {
                return response()->json(['error' => 'This flow cannot be published: ' . $e->getMessage()], 422);
            }
            if (!$this->write($flow, $request, ['graph' => json_encode($graph), 'draft_graph' => null, 'published_at' => now(), 'published_by' => Auth::id()])) return $this->conflict();
            DB::table('chatbot_flow_versions')->insert(['flow_id' => $flow->id, 'graph' => json_encode($graph), 'note' => $note !== '' ? $note : null, 'published_by' => Auth::id(), 'created_at' => now()]);
            $oldest = DB::table('chatbot_flow_versions')->where('flow_id', $flow->id)->orderByDesc('id')->offset(self::KEEP_VERSIONS)->limit(1)->value('id');
            if ($oldest) DB::table('chatbot_flow_versions')->where('flow_id', $flow->id)->where('id', '<=', $oldest)->delete();
            // Results: ang naka-cache na version ng graph na ito (hal. ibinalik na lumang graph) ay ang bagong Publish na.
            DB::afterCommit(fn () => ChatbotTags::forgetFlowVersion($flow->id, $graph));

            return response()->json($this->state($flow->fresh()) + ['graph' => $graph]);
        });
    }

    /** Burahin ang draft: balik sa published. Ang hindi pa naipa-publish ay walang mababalikan (mawawala lang ang gawa). */
    public function discard(Request $request, int $id)
    {
        $this->checkAccess();
        $flow = ChatbotFlow::findOrFail($id);
        if ($flow->published_at === null) return response()->json(['error' => 'This flow was never published, so there is no published version to go back to.'], 422);
        if (!$this->write($flow, $request, ['draft_graph' => null, 'updated_by' => Auth::id()])) return $this->conflict();
        $flow = $flow->fresh();

        return response()->json($this->state($flow) + ['graph' => $flow->draft() ?? FlowGraph::blank()]);
    }

    /** Ang huling 20 Publish, pinakabago muna. */
    public function versions(int $id)
    {
        $this->checkAccess();
        $flow = ChatbotFlow::findOrFail($id);
        $rows = DB::table('chatbot_flow_versions')->where('flow_id', $flow->id)->orderByDesc('id')->limit(self::LIST_VERSIONS)->get(['id', 'note', 'published_by', 'created_at']);
        $names = User::whereIn('id', $rows->pluck('published_by')->filter()->unique()->values())->pluck('name', 'id');

        return response()->json(['versions' => $rows->map(fn ($v) => [
            'id' => $v->id, 'note' => $v->note, 'by' => $names[$v->published_by] ?? null,
            'at' => $v->created_at ? Carbon::parse($v->created_at)->timezone('Asia/Manila')->format('Y-m-d H:i') : null,
        ])->values()]);
    }

    /** Ibalik ang isang Publish sa draft (hindi pa live). */
    public function restore(Request $request, int $id, int $version)
    {
        $this->checkAccess();
        $flow = ChatbotFlow::findOrFail($id);
        $row = DB::table('chatbot_flow_versions')->where('flow_id', $flow->id)->where('id', $version)->first();
        if (!$row) abort(404);
        try {
            $graph = FlowGraph::clean(json_decode($row->graph, true));
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => 'This version cannot be restored: ' . $e->getMessage()], 422);
        }
        if (!$this->write($flow, $request, ['draft_graph' => $this->draftValue($flow, $graph), 'updated_by' => Auth::id()])) return $this->conflict();

        return response()->json($this->state($flow->fresh()) + ['graph' => $graph]);
    }

    /**
     * Ang isusulat sa draft_graph: null kapag kapareho ng published (walang hindi pa naipa-publish). Mahigpit (===): sa
     * ==, ang "1" at "01" ay magkapareho at mawawala ang pagbabago. Pareho ang ayos ng key dahil galing sa clean ang dalawa.
     */
    private function draftValue(ChatbotFlow $flow, array $graph): ?string
    {
        return $flow->published_at !== null && $graph === $flow->graph ? null : json_encode($graph);
    }

    /**
     * Isang sulat sa flow na nagtataas ng draft_version, kapag ang version ng request ay ang nasa DB pa (iisang UPDATE,
     * kaya dalawang sabay na sulat ay hindi parehong papasa). $overwrite: walang check (pinili ng tao matapos ang 409).
     */
    private function write(ChatbotFlow $flow, Request $request, array $values, bool $overwrite = false): bool
    {
        $q = ChatbotFlow::whereKey($flow->id);
        if (!$overwrite) {
            $version = $this->version($request);
            if ($version === null) return false;
            $q->where('draft_version', $version);
        }

        return $q->update($values + ['draft_version' => DB::raw('draft_version + 1'), 'updated_at' => now()]) === 1;
    }

    private function version(Request $request): ?int
    {
        $v = $request->input('version');

        return is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v : null;
    }

    private function conflict()
    {
        return response()->json(['error' => 'This draft changed since you opened it. Reload, or overwrite?', 'conflict' => true], 409);
    }

    /** @return array{status: string, version: int, published_at: ?string} */
    private function state(ChatbotFlow $flow): array
    {
        return ['status' => $flow->status(), 'version' => (int) $flow->draft_version, 'published_at' => $flow->published_at?->timezone('Asia/Manila')->format('Y-m-d H:i')];
    }

    /**
     * Ang mga variable para sa listahan sa form: ang sa takbo, ang mga botfield (label ng column), at ang mga customer
     * field na ginagamit ng mga node ng flow na ito o naka-save sa mga customer ng mga page nito.
     * @return array<int, string>
     */
    private function variables(array $groups): array
    {
        return array_values(array_unique(array_column($groups, 'name')));
    }

    /**
     * Ang listahan, hinati: system (takbo), botfield (kada page), customer (kada customer). Ang tip ay kung saan galing;
     * ang pangalang nasa dalawang grupo (pareho sa ChatbotVars::norm) ay may "also" at ang patakaran ng merge:
     * botfield, saka customer field, saka ang takbo (ang huli ang nananaig).
     * @return array<int, array{name: string, group: string, tip: string, also: array<int, string>}>
     */
    private function variableGroups(ChatbotFlow $flow): array
    {
        $writers = []; // norm => pangalan at mga node na nagsusulat
        foreach ((array) ($flow->draft()['nodes'] ?? []) as $n) {
            $c = (array) ($n['config'] ?? []);
            $label = ['send' => 'Send message', 'ai' => 'AI', 'set' => 'Set field'][$n['type'] ?? ''] ?? null;
            $fields = [[$c['save_to'] ?? '', true]];
            foreach ((array) ($c['ops'] ?? []) as $op) $fields[] = [$op['field'] ?? '', in_array($op['op'] ?? 'set', ['set', 'append'], true)];
            foreach ($fields as [$f, $writes]) {
                if (!is_string($f) || ($key = ChatbotVars::norm($f)) === '' || str_starts_with($key, '__')) continue;
                $writers[$key] ??= ['name' => mb_strtoupper($key), 'by' => []];
                if ($writes && $label && !in_array($by = "{$n['id']} ({$label})", $writers[$key]['by'], true)) $writers[$key]['by'][] = $by;
            }
        }
        foreach ($this->savedCustomerKeys($flow) as $key) $writers[$key] ??= ['name' => mb_strtoupper($key), 'by' => []];

        $rows = [];
        foreach (self::RUN_VARS as $v) $rows[] = ['name' => $v, 'group' => 'system', 'tip' => 'Built in: ' . self::RUN_VAR_INFO[$v]];
        foreach (array_column($this->fields->columns(), 'label') as $v) $rows[] = ['name' => $v, 'group' => 'botfield', 'tip' => 'Botfield: set per page on /chatbot/pages'];
        foreach ($writers as $w) $rows[] = ['name' => $w['name'], 'group' => 'customer', 'tip' => 'Customer field: ' . ($w['by']
            ? 'set by ' . implode(', ', $w['by']) : 'saved on customers of this flow\'s pages; no step of this flow sets it')];

        $in = [];
        foreach ($rows as $r) $in[ChatbotVars::norm($r['name'])][$r['group']] = true;
        $rule = [
            'system' => ['customer' => 'Also a customer field; the built-in value always wins', 'botfield' => 'Also a botfield; the built-in value always wins'],
            'botfield' => ['system' => 'Also a system variable; the built-in value always wins, so this botfield can\'t be read', 'customer' => 'Also a customer field; the customer field wins when the customer has it saved (even blank)'],
            'customer' => ['system' => 'Also a system variable; the built-in value always wins, so this field can\'t be read', 'botfield' => 'Also a botfield; the customer field wins when the customer has it saved (even blank)'],
        ];
        foreach ($rows as &$r) {
            $r['also'] = array_values(array_diff(array_keys($in[ChatbotVars::norm($r['name'])]), [$r['group']]));
            foreach ($r['also'] as $g) $r['tip'] .= '. ' . $rule[$r['group']][$g];
        }

        return $rows;
    }

    /** @return array<int, string> ang mga customer field (naka-norm, walang "__") na naka-save sa mga page ng flow na ito */
    private function savedCustomerKeys(ChatbotFlow $flow): array
    {
        if (!Schema::hasTable('chatbot_customer_values')) return [];
        $pages = array_map(fn ($p) => $this->settings->pageKey($p), $this->pagesUsing($flow->id));
        $ids = array_keys(array_filter(BotcakeChat::pageMap(), fn ($name, $id) => ctype_digit((string) $id) && in_array($this->settings->pageKey($name), $pages, true), ARRAY_FILTER_USE_BOTH));
        if (!$ids) return [];

        return DB::table('chatbot_customer_values')->whereIn('page_id', array_map('intval', $ids))
            ->distinct()->orderBy('key')->limit(320)->pluck('key')->filter(fn ($k) => !str_starts_with((string) $k, '__'))->map(fn ($k) => (string) $k)->values()->all();
    }

    /** @return array<int, string> ang mga page na nakatali sa flow na ito (ayon sa id, gaya ng takbo) */
    private function pagesUsing(int $id): array
    {
        return app(\App\Services\Chatbot\ChatbotPage::class)->pagesUsing($id);
    }

    private function name(Request $request): string
    {
        $v = $request->input('name');

        return is_scalar($v) ? mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $v)), 0, 100) : '';
    }

    private function nameError(string $name, ?int $except = null): ?string
    {
        if ($name === '') return 'The flow needs a name.';
        $taken = ChatbotFlow::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->when($except, fn ($q) => $q->where('id', '!=', $except))->exists();

        return $taken ? 'Another flow already has this name.' : null;
    }
}
