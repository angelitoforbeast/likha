<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use App\Models\PlaybookProblem;
use App\Models\PlaybookChecklistItem;
use App\Models\PlaybookAttachment;
use App\Models\PlaybookRecurrence;

/**
 * PlaybookController — "Problem & Solution" knowledge base.
 * Problem (experience) → root cause → solution → fix checklist. Reusable kapag
 * nangyari ulit (may recurrence tracking + screenshot attachments + search).
 */
class PlaybookController extends Controller
{
    public const CATEGORIES = [
        'Ads / CPP', 'RTS / Delivery', 'Item / Supply', 'Page / Account',
        'Pancake / Tools', 'J&T / Shipping', 'Encoder / Data', 'Other',
    ];
    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];
    public const STATUSES   = ['open', 'resolved', 'recurring'];

    // ───────────────────────── access control ─────────────────────────

    private function role(): string
    {
        $raw  = Auth::user()?->employeeProfile?->role ?? '';
        $norm = preg_replace('/\s+/u', ' ', trim((string) $raw));
        if (preg_match('/^ceo$/iu', $norm)) return 'CEO';
        if (preg_match('/^marketing\s*[-–—]\s*oic$/iu', $norm)) return 'Marketing - OIC';
        if (preg_match('/^marketing$/iu', $norm)) return 'Marketing';
        return $norm;
    }
    /** View + create + edit — CEO + Marketing-OIC + Marketing. */
    private function checkAccess(): void
    {
        if (!in_array($this->role(), ['CEO', 'Marketing - OIC', 'Marketing'], true)) abort(404);
    }
    /** Delete — CEO + Marketing-OIC only. */
    private function checkDeleteAccess(): void
    {
        if (!in_array($this->role(), ['CEO', 'Marketing - OIC'], true)) abort(403);
    }

    // ───────────────────────── list ─────────────────────────

    public function index(Request $request)
    {
        $this->checkAccess();

        $q        = trim((string) $request->query('q', ''));
        $category = trim((string) $request->query('category', ''));
        $status   = trim((string) $request->query('status', ''));
        $severity = trim((string) $request->query('severity', ''));

        $query = PlaybookProblem::query()->withCount('checklist')->with('attachments');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('title', 'like', "%{$q}%")
                  ->orWhere('description', 'like', "%{$q}%")
                  ->orWhere('solution', 'like', "%{$q}%")
                  ->orWhere('root_cause', 'like', "%{$q}%");
            });
        }
        if ($category !== '') $query->where('category', $category);
        if ($status !== '')   $query->where('status', $status);
        if ($severity !== '') $query->where('severity', $severity);

        // Recurring/most-seen + recent muna.
        $problems = $query->orderByDesc('times_seen')->orderByDesc('updated_at')->paginate(20)->withQueryString();

        return view('playbook.index', [
            'problems'   => $problems,
            'categories' => self::CATEGORIES,
            'severities' => self::SEVERITIES,
            'statuses'   => self::STATUSES,
            'f'          => compact('q', 'category', 'status', 'severity'),
            'canWrite'   => true,
        ]);
    }

    public function show(Request $request, int $problem)
    {
        $this->checkAccess();
        $p = PlaybookProblem::query()
            ->with(['checklist', 'attachments', 'recurrences'])
            ->findOrFail($problem);

        $attachments = $p->attachments->map(fn ($a) => [
            'id' => $a->id, 'url' => Storage::disk('public')->url($a->path),
        ])->all();

        return view('playbook.show', [
            'p'           => $p,
            'attachments' => $attachments,
            'canWrite'    => true,
            'canDelete'   => in_array($this->role(), ['CEO', 'Marketing - OIC'], true),
        ]);
    }

    public function create(Request $request)
    {
        $this->checkAccess();
        return view('playbook.create', [
            'categories' => self::CATEGORIES,
            'severities' => self::SEVERITIES,
            'statuses'   => self::STATUSES,
        ]);
    }

    public function store(Request $request)
    {
        $this->checkAccess();
        $data = $this->validateProblem($request);

        $p = PlaybookProblem::create([
            'title'       => trim($data['title']),
            'category'    => $data['category'] ?? null,
            'severity'    => $data['severity'],
            'status'      => $data['status'],
            'description' => $data['description'] ?? null,
            'root_cause'  => $data['root_cause'] ?? null,
            'solution'    => $data['solution'] ?? null,
            'prevention'  => $data['prevention'] ?? null,
            'times_seen'  => 1,
            'created_by'  => Auth::id(),
        ]);

        // Checklist rows: checklist_items[idx][label].
        foreach ((array) $request->input('checklist_items', []) as $i => $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') continue;
            PlaybookChecklistItem::create([
                'playbook_problem_id' => $p->id, 'label' => $label, 'sort_order' => $i,
            ]);
        }

        $this->storeAttachments($request, $p->id);

        return redirect()->route('playbook.show', $p->id)->with('success', 'Problem naidagdag sa playbook.');
    }

    public function edit(Request $request, int $problem)
    {
        $this->checkAccess();
        $p = PlaybookProblem::query()->with('checklist', 'attachments')->findOrFail($problem);
        $attachments = $p->attachments->map(fn ($a) => [
            'id' => $a->id, 'url' => Storage::disk('public')->url($a->path),
        ])->all();
        return view('playbook.edit', [
            'p'           => $p,
            'attachments' => $attachments,
            'categories'  => self::CATEGORIES,
            'severities'  => self::SEVERITIES,
            'statuses'    => self::STATUSES,
        ]);
    }

    public function update(Request $request, int $problem)
    {
        $this->checkAccess();
        $p = PlaybookProblem::query()->with('checklist')->findOrFail($problem);
        $data = $this->validateProblem($request);

        $wasResolved = $p->status === 'resolved';
        $p->update([
            'title'       => trim($data['title']),
            'category'    => $data['category'] ?? null,
            'severity'    => $data['severity'],
            'status'      => $data['status'],
            'description' => $data['description'] ?? null,
            'root_cause'  => $data['root_cause'] ?? null,
            'solution'    => $data['solution'] ?? null,
            'prevention'  => $data['prevention'] ?? null,
            'resolved_by' => ($data['status'] === 'resolved' && !$wasResolved) ? Auth::id() : $p->resolved_by,
            'resolved_at' => ($data['status'] === 'resolved' && !$wasResolved) ? now() : $p->resolved_at,
        ]);

        // Checklist sync — preserve is_done for existing (match by id), create new, delete missing.
        $existing  = $p->checklist->keyBy('id');
        $keptIds   = [];
        foreach ((array) $request->input('checklist_items', []) as $i => $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') continue;
            $id = (isset($row['id']) && $row['id'] !== '' && $row['id'] !== null) ? (int) $row['id'] : null;
            if ($id && $existing->has($id)) {
                $existing->get($id)->update(['label' => $label, 'sort_order' => $i]);
                $keptIds[] = $id;
            } else {
                $new = PlaybookChecklistItem::create([
                    'playbook_problem_id' => $p->id, 'label' => $label, 'sort_order' => $i,
                ]);
                $keptIds[] = $new->id;
            }
        }
        PlaybookChecklistItem::query()->where('playbook_problem_id', $p->id)
            ->whereNotIn('id', $keptIds ?: [0])->delete();

        // Remove selected attachments.
        foreach ((array) $request->input('remove_attachment_ids', []) as $rid) {
            $a = PlaybookAttachment::query()->where('playbook_problem_id', $p->id)->where('id', (int) $rid)->first();
            if ($a) { try { Storage::disk('public')->delete($a->path); } catch (\Throwable $e) {} $a->delete(); }
        }
        $this->storeAttachments($request, $p->id);

        return redirect()->route('playbook.show', $p->id)->with('success', 'Problem na-update.');
    }

    public function destroy(Request $request, int $problem)
    {
        $this->checkDeleteAccess();
        $p = PlaybookProblem::query()->with('attachments')->findOrFail($problem);
        foreach ($p->attachments as $a) {
            try { Storage::disk('public')->delete($a->path); } catch (\Throwable $e) {}
        }
        $p->delete(); // cascade checklist/attachments/recurrences rows
        return redirect()->route('playbook.index')->with('success', 'Problem tinanggal.');
    }

    /** Update checklist progress (is_done) from the show page. */
    public function updateChecklist(Request $request, int $problem)
    {
        $this->checkAccess();
        $p = PlaybookProblem::query()->findOrFail($problem);
        $done = array_map('intval', (array) $request->input('done_ids', []));
        PlaybookChecklistItem::query()->where('playbook_problem_id', $p->id)
            ->update(['is_done' => false]);
        if ($done) {
            PlaybookChecklistItem::query()->where('playbook_problem_id', $p->id)
                ->whereIn('id', $done)->update(['is_done' => true]);
        }
        return back()->with('success', 'Checklist na-update.');
    }

    /** Log a recurrence (nangyari ulit) — increments times_seen + flags recurring. */
    public function addRecurrence(Request $request, int $problem)
    {
        $this->checkAccess();
        $p = PlaybookProblem::query()->findOrFail($problem);
        $data = $request->validate([
            'occurred_at' => ['required', 'date'],
            'note'        => ['nullable', 'string'],
        ]);
        PlaybookRecurrence::create([
            'playbook_problem_id' => $p->id,
            'occurred_at'         => $data['occurred_at'],
            'note'                => $data['note'] ?? null,
            'logged_by'           => Auth::id(),
        ]);
        $p->increment('times_seen');
        $p->update(['status' => 'recurring']);
        return back()->with('success', 'Recurrence na-log (nangyari ulit).');
    }

    // ───────────────────────── helpers ─────────────────────────

    private function validateProblem(Request $request): array
    {
        return $request->validate([
            'title'                  => ['required', 'string', 'max:191'],
            'category'               => ['nullable', 'string', 'max:50'],
            'severity'               => ['required', 'in:' . implode(',', self::SEVERITIES)],
            'status'                 => ['required', 'in:' . implode(',', self::STATUSES)],
            'description'            => ['nullable', 'string'],
            'root_cause'             => ['nullable', 'string'],
            'solution'               => ['nullable', 'string'],
            'prevention'             => ['nullable', 'string'],
            'attachments'            => ['nullable', 'array'],
            'attachments.*'          => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
            'remove_attachment_ids'  => ['nullable', 'array'],
            'remove_attachment_ids.*'=> ['integer'],
            'checklist_items'        => ['nullable', 'array'],
        ]);
    }

    private function storeAttachments(Request $request, int $problemId): void
    {
        if (!$request->hasFile('attachments')) return;
        foreach ($request->file('attachments') as $file) {
            if (!$file) continue;
            $path = $file->store('playbook', 'public');
            PlaybookAttachment::create(['playbook_problem_id' => $problemId, 'path' => $path]);
        }
    }
}
