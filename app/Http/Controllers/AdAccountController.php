<?php

namespace App\Http\Controllers;

use App\Models\AdAccount;
use Illuminate\Http\Request;

class AdAccountController extends Controller
{
    /**
     * One page handles: list + add + (optional) edit if {ad_account_id} is present.
     */
    public function index(Request $request, ?string $ad_account_id = null)
    {
        $rows = AdAccount::orderBy('name')->get();

        $editing = null;
        if ($ad_account_id) {
            $editing = AdAccount::where('ad_account_id', $ad_account_id)->first();
        }

        return view('ads_manager.ad_account.index', compact('rows', 'editing'));
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

        // If editing and user changes the ad_account_id, handle it properly.
        if (($data['mode'] ?? null) === 'update' && !empty($data['original_ad_account_id'])) {
            // If ID changed, update that row; else it falls back to updateOrCreate
            $row = AdAccount::where('ad_account_id', $data['original_ad_account_id'])->first();
            if ($row) {
                $row->ad_account_id = $data['ad_account_id'];
                $row->name = $data['name'];
                $row->save();

                return redirect()
                    ->route('ad_accounts.index')
                    ->with('status', 'Ad account updated.');
            }
        }

        // Default: create/update by ad_account_id (idempotent)
        AdAccount::updateOrCreate(
            ['ad_account_id' => $data['ad_account_id']],
            ['name' => $data['name']]
        );

        return redirect()
            ->route('ad_accounts.index')
            ->with('status', 'Ad account saved.');
    }

    /**
     * Optional delete (if you want it).
     */
    public function destroy(Request $request, string $ad_account_id)
    {
        AdAccount::where('ad_account_id', $ad_account_id)->delete();

        return redirect()
            ->route('ad_accounts.index')
            ->with('status', 'Ad account deleted.');
    }
}
