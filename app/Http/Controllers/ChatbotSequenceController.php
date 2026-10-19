<?php

namespace App\Http\Controllers;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Models\ChatbotSequence;
use App\Models\ChatbotSequenceStep;
use App\Models\ChatbotSequenceSubscription;
use App\Services\Chatbot\BotcakeOutbox;
use App\Support\NavAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * /chatbot/sequences: ang listahan, ang editor (isang JSON na save: ang settings at ang lahat ng step, sunod-sunod) at
 * ang mga subscriber ng isang sequence. Access gaya ng /chatblast (NavAccess; 403 sa iba).
 */
class ChatbotSequenceController extends Controller
{
    public const PER_PAGE = 100;

    private function checkAccess(): void
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';
        if (!NavAccess::allows(preg_replace('/\s+/u', ' ', trim((string) $raw)), '/chatblast')) abort(403);
    }

    public function index()
    {
        $this->checkAccess();
        $sequences = ChatbotSequence::withCount('steps')->orderBy('name')->get();
        $counts = DB::table('chatbot_sequence_subscriptions')->whereIn('sequence_id', $sequences->pluck('id'))->whereIn('status', ['active', 'completed'])
            ->selectRaw('sequence_id, status, COUNT(*) AS n')->groupBy('sequence_id', 'status')->get()
            ->groupBy('sequence_id')->map(fn ($rows) => $rows->pluck('n', 'status'));

        return view('chatbot.sequences', ['sequences' => $sequences, 'counts' => $counts, 'repliesOn' => BotcakeOutbox::enabled()]);
    }

    public function store(Request $request)
    {
        $this->checkAccess();
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate(['name' => ['required', 'string', 'max:120', Rule::unique('chatbot_sequences', 'name')]]);
        $seq = ChatbotSequence::create(['name' => $data['name']]);

        return redirect()->route('chatbot.sequences.edit', $seq->id);
    }

    public function edit(int $id)
    {
        $this->checkAccess();
        $seq = ChatbotSequence::with('steps')->findOrFail($id);
        $flows = ChatbotFlow::orderBy('name')->get(['id', 'name', 'published_at'])
            ->map(fn ($f) => ['id' => $f->id, 'name' => $f->name, 'published' => $f->published_at !== null])->all();

        return view('chatbot.sequence-edit', ['sequence' => $seq, 'flows' => $flows, 'repliesOn' => BotcakeOutbox::enabled(),
            'steps' => $seq->steps->map(fn ($s) => ['delay_amount' => $s->delay_amount, 'delay_unit' => $s->delay_unit, 'flow_id' => $s->flow_id, 'active' => $s->active])->all()]);
    }

    /** Ang buong sequence sa isang save: ang mga step ay pinapalitan at binibilang ulit (1..n) ayon sa ayos na ipinadala. */
    public function update(Request $request, int $id)
    {
        $this->checkAccess();
        $seq = ChatbotSequence::findOrFail($id);
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('chatbot_sequences', 'name')->ignore($seq->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'active' => ['required', 'boolean'],
            'exit_on_reply' => ['required', 'boolean'],
            'steps' => ['present', 'array', 'max:' . ChatbotSequence::MAX_STEPS],
            'steps.*' => ['array'],
            'steps.*.delay_amount' => ['required', 'integer', 'min:0', 'max:999'],
            'steps.*.delay_unit' => ['required', Rule::in(ChatbotSequenceStep::UNITS)],
            'steps.*.flow_id' => ['required', 'integer', Rule::exists('chatbot_flows', 'id')],
            'steps.*.active' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($seq, $data) {
            $seq->update(['name' => $data['name'], 'description' => $data['description'] ?? null, 'active' => $data['active'], 'exit_on_reply' => $data['exit_on_reply']]);
            $seq->steps()->delete();
            foreach (array_values($data['steps']) as $i => $s) {
                $seq->steps()->create(['position' => $i + 1, 'delay_amount' => (int) $s['delay_amount'], 'delay_unit' => $s['delay_unit'],
                    'flow_id' => (int) $s['flow_id'], 'active' => (bool) $s['active']]);
            }
        });

        return response()->json(['ok' => true, 'saved_at' => now()->format('H:i:s')]);
    }

    /** Ang mga subscriber: ayon sa sequence (at status, page), pinakabago muna, 100 kada basa. */
    public function subscribers(Request $request, int $id)
    {
        $this->checkAccess();
        $seq = ChatbotSequence::findOrFail($id);
        $status = in_array($request->query('status'), ChatbotSequenceSubscription::STATUSES, true) ? $request->query('status') : '';
        $pageId = ctype_digit((string) $request->query('page_id')) ? (string) $request->query('page_id') : '';
        $before = ctype_digit((string) $request->query('before')) ? (int) $request->query('before') : 0;

        $rows = ChatbotSequenceSubscription::where('sequence_id', $seq->id)->when($status, fn ($q) => $q->where('status', $status))
            ->when($pageId !== '', fn ($q) => $q->where('page_id', (int) $pageId))->when($before, fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('id')->limit(self::PER_PAGE + 1)->get();
        $more = $rows->count() > self::PER_PAGE;
        $rows = $rows->take(self::PER_PAGE);
        // Ang pangalan ng customer: mula sa huling chat (ayon sa page at PSID ng mga nasa pahina lang).
        $names = $rows->isEmpty() ? collect() : BotcakeChat::whereIn('page_id', $rows->pluck('page_id')->unique()->map(fn ($p) => (string) $p)->values())
            ->whereIn('psid', $rows->pluck('psid')->unique()->map(fn ($p) => (string) $p)->values())->whereNotNull('name')
            ->selectRaw('page_id, psid, MAX(name) AS name')->groupBy('page_id', 'psid')->get()->mapWithKeys(fn ($c) => [$c->page_id . ':' . $c->psid => $c->name]);
        $steps = $seq->steps()->count();

        return view('chatbot.sequence-subscribers', [
            'sequence' => $seq, 'rows' => $rows, 'names' => $names, 'pages' => BotcakeChat::pageMap(), 'stepCount' => $steps,
            'status' => $status, 'pageId' => $pageId, 'next' => $more ? $rows->last()->id : null, 'repliesOn' => BotcakeOutbox::enabled(),
        ]);
    }
}
