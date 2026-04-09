<?php

namespace App\Http\Controllers;

use App\Models\JntAccount;
use App\Models\PageJntMapping;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JntAccountController extends Controller
{
    // ── Accounts ────────────────────────────────────────────────

    public function index()
    {
        $accounts = JntAccount::orderBy('label')->get();
        return view('jnt.accounts.index', compact('accounts'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'label'       => 'required|string|max:100',
            'eccompanyid' => 'required|string|max:100',
            'customerid'  => 'required|string|max:100',
        ]);

        $data['force_uppercase'] = $request->boolean('force_uppercase');

        JntAccount::create($data);

        return back()->with('success', 'JNT Account added successfully.');
    }

    public function update(Request $request, JntAccount $account)
    {
        $data = $request->validate([
            'label'       => 'required|string|max:100',
            'eccompanyid' => 'required|string|max:100',
            'customerid'  => 'required|string|max:100',
        ]);

        $data['force_uppercase'] = $request->boolean('force_uppercase');

        $account->update($data);

        return back()->with('success', 'JNT Account updated.');
    }

    public function destroy(JntAccount $account)
    {
        $account->delete();
        return back()->with('success', 'JNT Account deleted.');
    }

    // ── Page Mapping ─────────────────────────────────────────────

    public function mapping()
    {
        $accounts = JntAccount::orderBy('label')->get();

        $pages = DB::table('macro_output')
            ->whereNotNull('PAGE')
            ->where('PAGE', '!=', '')
            ->select('PAGE')
            ->distinct()
            ->orderBy('PAGE')
            ->pluck('PAGE');

        $mappings = PageJntMapping::with('account')
            ->get()
            ->keyBy('page');

        return view('jnt.accounts.mapping', compact('accounts', 'pages', 'mappings'));
    }

    public function saveMapping(Request $request)
    {
        $data = $request->validate([
            'mappings'   => 'array',
            'mappings.*' => 'nullable|integer|exists:jnt_accounts,id',
        ]);

        foreach ($data['mappings'] ?? [] as $page => $accountId) {
            if ($accountId) {
                PageJntMapping::updateOrCreate(
                    ['page' => $page],
                    ['jnt_account_id' => (int) $accountId]
                );
            } else {
                PageJntMapping::where('page', $page)->delete();
            }
        }

        return back()->with('success', 'Page mappings saved.');
    }
}
