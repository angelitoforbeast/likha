<?php

namespace App\Http\Controllers;

use App\Models\ChatbotFlow;
use App\Models\PageSetting;
use App\Services\Chatbot\BotcakeOutbox;
use App\Services\Chatbot\FlowGraph;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use App\Support\NavAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * /chatbot (Flow): ang listahan ng flow at ang editor (canvas). Access gaya ng /chatblast at ng mga cell ng page list
 * (NavAccess; 403 sa iba). Ang graph ay sinusuri ng FlowGraph::clean bago i-save (iisang patakaran sa engine).
 */
class ChatbotFlowController extends Controller
{
    /** Ang mga variable ng takbo (para sa listahan sa mga form ng node). */
    public const RUN_VARS = ['last_input', 'full_name', 'first_name', 'psid', 'page_id', 'page_name', 'current_date', 'last_interaction'];

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

        $copy = ChatbotFlow::create(['name' => $name, 'graph' => $flow->graph, 'updated_by' => Auth::id()]);

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
        $flow->delete();

        return response()->json(['ok' => true]);
    }

    public function edit(int $id)
    {
        $this->checkAccess();
        $flow = ChatbotFlow::findOrFail($id);

        return view('chatbot.editor', ['flow' => $flow, 'repliesOn' => BotcakeOutbox::enabled()]);
    }

    /** Ang graph, ang mga variable na magagamit, at ang mga patakaran (para sa mga form ng node). */
    public function graph(int $id)
    {
        $this->checkAccess();
        $flow = ChatbotFlow::findOrFail($id);

        return response()->json([
            'id' => $flow->id, 'name' => $flow->name, 'graph' => is_array($flow->graph) ? $flow->graph : FlowGraph::blank(),
            'variables' => $this->variables($flow), 'rules' => FlowGraph::RULES,
        ]);
    }

    public function saveGraph(Request $request, int $id)
    {
        $this->checkAccess();
        $flow = ChatbotFlow::findOrFail($id);
        try {
            $graph = FlowGraph::clean($request->input('graph'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $flow->update(['graph' => $graph, 'updated_by' => Auth::id()]);

        return response()->json(['saved_at' => now('Asia/Manila')->format('H:i:s'), 'graph' => $graph]);
    }

    /**
     * Ang mga variable para sa listahan sa form: ang sa takbo, ang mga botfield (label ng column), at ang mga customer
     * field na ginagamit ng mga node ng flow na ito.
     * @return array<int, string>
     */
    private function variables(ChatbotFlow $flow): array
    {
        $customer = [];
        foreach ((array) ($flow->graph['nodes'] ?? []) as $n) {
            $c = (array) ($n['config'] ?? []);
            foreach ([$c['save_to'] ?? '', ...array_column((array) ($c['ops'] ?? []), 'field')] as $f) {
                if (is_string($f) && $f !== '') $customer[] = mb_strtoupper($f);
            }
        }

        return array_values(array_unique(array_merge(self::RUN_VARS, array_column($this->fields->columns(), 'label'), $customer)));
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
