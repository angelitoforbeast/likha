<?php

namespace App\Http\Controllers;

use App\Models\AddressNote;
use App\Services\OrderEncode\NoteFilter;
use App\Services\OrderEncode\NoteReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * /order-encode/notes: ang natutunang address ng encoder (address_notes), para tingnan, hanapin, aprubahan, tanggihan o palitan.
 * CEO lang, pati ang pagbabasa: ang sagot ng mga note ay nagpapasya ng PROCEED.
 */
class OrderEncodeNotesController extends Controller
{
    private const PER_PAGE = 50;

    private function getNormalizedRole(): string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';

        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    private function checkAccess(): void
    {
        if ($this->getNormalizedRole() !== 'CEO' || !Schema::hasTable('address_notes')) abort(404);
    }

    private function reviewer(): string
    {
        return (string) (Auth::user()?->name ?? 'CEO');
    }

    /** @return array<string, string> ang mga filter na galing sa request (mga string lang) */
    private function filters(Request $request): array
    {
        $out = [];
        foreach (NoteFilter::KEYS as $k) {
            $v = $request->input($k);
            if (is_string($v) && trim($v) !== '') $out[$k] = trim($v);
        }

        return $out;
    }

    public function index(Request $request)
    {
        $this->checkAccess();
        $filters = $this->filters($request);
        if (!$request->has('status')) $filters['status'] = 'pending'; // sa unang bukas, ang naghihintay ng tingin

        $notes = NoteFilter::query($filters)->orderByDesc('delivered')->orderByDesc('seen')->orderBy('id')->paginate(self::PER_PAGE)->withQueryString();
        $counts = AddressNote::query()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->all();

        // ang mga halimbawang order: ang petsa ng bawat isa para sa link sa checker 1
        $ids = $notes->getCollection()->flatMap(fn (AddressNote $n) => (array) $n->order_ids)->unique()->values()->all();
        $dates = $ids && Schema::hasTable('macro_output') && Schema::hasColumn('macro_output', 'ts_date')
            ? DB::table('macro_output')->whereIn('id', $ids)->pluck('ts_date', 'id')->map(fn ($d) => substr((string) $d, 0, 10))->all() : [];

        return view('order-encode.notes', [
            'notes' => $notes, 'filters' => $filters, 'dates' => $dates, 'filterCount' => NoteFilter::query($filters)->where('status', 'pending')->where('conflict', false)->count(),
            'counts' => ['pending' => (int) ($counts['pending'] ?? 0), 'approved' => (int) ($counts['approved'] ?? 0), 'rejected' => (int) ($counts['rejected'] ?? 0)],
            'statuses' => NoteFilter::STATUSES, 'sources' => NoteFilter::SOURCES,
        ]);
    }

    public function approve(Request $request, AddressNote $note, NoteReview $review)
    {
        $this->checkAccess();
        $review->approve($note, $this->reviewer(), $this->noteText($request));

        return back()->with('status', 'Approved.');
    }

    public function reject(Request $request, AddressNote $note, NoteReview $review)
    {
        $this->checkAccess();
        $review->reject($note, $this->reviewer(), $this->noteText($request));

        return back()->with('status', 'Rejected.');
    }

    public function edit(Request $request, AddressNote $note, NoteReview $review)
    {
        $this->checkAccess();
        $data = $request->validate([
            'province' => ['required', 'string', 'max:120'], 'city' => ['required', 'string', 'max:160'], 'barangay' => ['required', 'string', 'max:200'],
            'review_note' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $review->edit($note, $data['province'], $data['city'], $data['barangay'], $this->reviewer(), $data['review_note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['edit' => $e->getMessage()]);
        }

        return back()->with('status', 'Answer changed.');
    }

    /** Aprubahan ang mga napili, o lahat ng pending na walang conflict na tumutugma sa filter. */
    public function bulk(Request $request, NoteReview $review)
    {
        $this->checkAccess();
        $request->validate(['scope' => ['required', 'in:selected,filtered'], 'ids' => ['nullable', 'array', 'max:500'], 'ids.*' => ['integer']]);
        if ($request->input('scope') === 'filtered' && !$this->filters($request)) return back()->withErrors(['bulk' => 'Choose a filter first.']);
        $query = $request->input('scope') === 'selected'
            ? AddressNote::query()->whereIn('id', array_map('intval', (array) $request->input('ids')))
            : NoteFilter::query($this->filters($request));

        $done = $review->approveMany($query, $this->reviewer(), $this->noteText($request));

        return back()->with('status', "Approved {$done['approved']}; {$done['conflicts']} with a conflict need a person.");
    }

    private function noteText(Request $request): ?string
    {
        $t = $request->input('review_note');

        return is_string($t) ? $t : null;
    }
}
