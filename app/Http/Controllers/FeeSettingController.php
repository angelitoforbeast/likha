<?php

namespace App\Http\Controllers;

use App\Models\FeeSetting;
use Illuminate\Http\Request;

class FeeSettingController extends Controller
{
    public function index(Request $request)
    {
        $host  = strtolower((string) $request->getHost());
        $scope = str_contains($host, 'incepxion') ? 'incepxion' : 'likha';

        $settings = FeeSetting::where('host_scope', $scope)
            ->orderBy('setting_key')
            ->orderByDesc('effective_date')
            ->get();

        return view('jnt.fee-settings', [
            'settings' => $settings,
            'scope'    => $scope,
        ]);
    }

    public function store(Request $request)
    {
        $host  = strtolower((string) $request->getHost());
        $scope = str_contains($host, 'incepxion') ? 'incepxion' : 'likha';

        $validated = $request->validate([
            'setting_key'    => 'required|in:cod_fee_rate,cod_fee_vat_rate',
            'setting_value'  => 'required|numeric|min:0|max:1',
            'effective_date' => 'required|date',
            'description'    => 'nullable|string|max:255',
        ]);

        $validated['host_scope'] = $scope;

        // Check for duplicate (same key + same effective_date + same scope)
        $exists = FeeSetting::where('setting_key', $validated['setting_key'])
            ->where('host_scope', $scope)
            ->where('effective_date', $validated['effective_date'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['effective_date' => 'A setting with this key and effective date already exists.'])->withInput();
        }

        FeeSetting::create($validated);

        return redirect()->route('fee-settings.index')->with('success', 'Fee setting created successfully.');
    }

    public function update(Request $request, FeeSetting $fee_setting)
    {
        $validated = $request->validate([
            'setting_value'  => 'required|numeric|min:0|max:1',
            'effective_date' => 'required|date',
            'description'    => 'nullable|string|max:255',
        ]);

        // Check for duplicate (same key + same effective_date + same scope, excluding current)
        $exists = FeeSetting::where('setting_key', $fee_setting->setting_key)
            ->where('host_scope', $fee_setting->host_scope)
            ->where('effective_date', $validated['effective_date'])
            ->where('id', '!=', $fee_setting->id)
            ->exists();

        if ($exists) {
            return back()->withErrors(['effective_date' => 'A setting with this key and effective date already exists.'])->withInput();
        }

        $fee_setting->update($validated);

        return redirect()->route('fee-settings.index')->with('success', 'Fee setting updated successfully.');
    }

    public function destroy(FeeSetting $fee_setting)
    {
        $fee_setting->delete();

        return redirect()->route('fee-settings.index')->with('success', 'Fee setting deleted successfully.');
    }
}
