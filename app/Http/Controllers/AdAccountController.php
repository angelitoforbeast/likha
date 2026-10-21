<?php

namespace App\Http\Controllers;

use App\Models\AdAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdAccountController extends Controller
{
    /**
     * One page handles: list + add + (optional) edit if {ad_account_id} is present.
     * History: ang pinalitan o binurang row ay naiiwan na active = false; active rows lang ang listahan.
     */
    public function index(Request $request, ?string $ad_account_id = null)
    {
        $rows = AdAccount::where('active', true)->orderBy('name')->get();

        $editing = null;
        if ($ad_account_id) {
            $editing = AdAccount::where('active', true)->where('ad_account_id', $ad_account_id)->first();
        }

        $log = $this->changeLog();

        $userIds = $rows->pluck('created_by')->merge(collect($log->items())->pluck('user_id'))->filter()->unique();
        $names = $userIds->isEmpty() ? collect() : User::whereIn('id', $userIds)->pluck('name', 'id');
        foreach ($rows as $r) {
            $r->added_by_name = $names[$r->created_by] ?? 'unknown';
        }
        foreach ($log->items() as $e) {
            $e->who = $names[$e->user_id] ?? 'unknown';
        }

        return view('ads_manager.ad_account.index', compact('rows', 'editing', 'log'));
    }

    /**
     * Bawat row = isang "added"/"changed" (created_at, created_by); bawat binurang row = isa pang "deleted"
     * (replaced_at, replaced_by). Pinakabago muna, 50 bawat page.
     */
    private function changeLog()
    {
        $writes = DB::table('ad_accounts')->selectRaw("id AS row_id, created_at AS at, created_by AS user_id, 'write' AS kind");
        $deletes = DB::table('ad_accounts')->where('action', 'deleted')
            ->selectRaw("id AS row_id, replaced_at AS at, replaced_by AS user_id, 'deleted' AS kind");

        $log = DB::query()->fromSub($writes->unionAll($deletes), 'ev')
            ->orderByDesc('at')->orderByDesc('row_id')->orderBy('kind')
            ->paginate(50, ['*'], 'log_page')->withQueryString();

        $rowsById = AdAccount::whereIn('id', collect($log->items())->pluck('row_id'))->get()->keyBy('id');
        // Ang pinalitang row: sabay isinusulat sa write(), kaya replaced_at/replaced_by = created_at/created_by ng bago.
        $replaced = AdAccount::where('active', false)->where(fn ($q) => $q->whereNull('action')->orWhere('action', '!=', 'deleted'))
            ->whereIn('replaced_at', $rowsById->pluck('created_at')->filter()->map(fn ($t) => $t->format('Y-m-d H:i:s'))->unique()->values())
            ->orderByDesc('id')->get();
        foreach ($log->items() as $e) {
            $row = $rowsById[$e->row_id];
            $e->ad_account_id = $row->ad_account_id;
            $e->name = $row->name;
            $e->old_ad_account_id = null;
            $e->old_name = null;

            if ($e->kind === 'deleted') {
                $e->action = 'deleted';
                continue;
            }
            $prev = $row->action === 'changed' || $row->action === 'deleted'
                ? $replaced->first(fn ($p) => $p->id < $row->id && $p->replaced_by === $row->created_by
                    && $p->replaced_at?->equalTo($row->created_at))
                : null;
            $e->action = $prev ? 'changed' : 'added';
            if ($prev) {
                $e->old_ad_account_id = $prev->ad_account_id;
                $e->old_name = $prev->name;
            }
        }

        return $log;
    }

    /**
     * Create or update via ad_account_id (single form).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'ad_account_id' => ['required', 'string', 'max:191'],
            'name'          => ['required', 'string', 'max:191'],
            'mode'          => ['nullable', 'in:create,update'],
            'original_ad_account_id' => ['nullable', 'string', 'max:191'], // for edit where ID might change
        ]);

        $saved = DB::transaction(function () use ($data, $request) {
            // If editing and user changes the ad_account_id, handle it properly.
            if (($data['mode'] ?? null) === 'update' && !empty($data['original_ad_account_id'])) {
                $row = $this->activeRow($data['original_ad_account_id']);
                if ($row) {
                    if ($data['ad_account_id'] !== $row->ad_account_id && $this->activeRow($data['ad_account_id'])) {
                        throw ValidationException::withMessages(['ad_account_id' => 'May ad account na may ganyang Ad Account ID.']);
                    }
                    return $this->write($row, $data, $request->user()?->id);
                }
            }

            // Default: create/update by ad_account_id (idempotent)
            return $this->write($this->activeRow($data['ad_account_id']), $data, $request->user()?->id);
        });

        return redirect()
            ->route('ad_accounts.index')
            ->with('status', $saved === 'changed' ? 'Ad account updated.' : 'Ad account saved.');
    }

    /** Ang nag-iisang active row ng ID, naka-lock hanggang matapos ang transaction. */
    private function activeRow(string $adAccountId): ?AdAccount
    {
        return AdAccount::where('active', true)->where('ad_account_id', $adAccountId)->lockForUpdate()->first();
    }

    /**
     * Bagong active row; ang dati (kung meron) ay naiiwan na inactive. Parehong value → walang bagong row.
     * @return string added|changed|unchanged
     */
    private function write(?AdAccount $current, array $data, ?int $userId): string
    {
        if ($current && $current->ad_account_id === $data['ad_account_id'] && $current->name === $data['name']) {
            return 'unchanged';
        }

        $now = now();
        $current?->update(['active' => false, 'replaced_at' => $now, 'replaced_by' => $userId]);

        $row = new AdAccount([
            'ad_account_id' => $data['ad_account_id'],
            'name'          => $data['name'],
            'created_by'    => $userId,
            'active'        => true,
            'action'        => $current ? 'changed' : 'added',
        ]);
        $row->created_at = $now;
        $row->updated_at = $now;
        $row->save();

        return $row->action;
    }

    /**
     * Delete: hindi binubura ang row; inactive na lang, action = deleted, at kung sino.
     */
    public function destroy(Request $request, string $ad_account_id)
    {
        AdAccount::where('active', true)->where('ad_account_id', $ad_account_id)->update([
            'active'      => false,
            'action'      => 'deleted',
            'replaced_at' => now(),
            'replaced_by' => $request->user()?->id,
        ]);

        return redirect()
            ->route('ad_accounts.index')
            ->with('status', 'Ad account deleted.');
    }
}
