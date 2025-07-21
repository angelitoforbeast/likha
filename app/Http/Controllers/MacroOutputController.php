<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MacroOutput;

class MacroOutputController extends Controller
{
    public function edit($id)
    {
        $record = MacroOutput::findOrFail($id);
        return view('macro_output.edit', compact('record'));
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'FULL NAME' => 'required|string|max:255',
            'PHONE NUMBER' => 'required|string|max:100',
            'ADDRESS' => 'required|string',
            'PROVINCE' => 'required|string|max:255',
            'CITY' => 'required|string|max:255',
            'BARANGAY' => 'required|string|max:255',
            'STATUS' => 'nullable|string|max:255',
        ]);

        $record = MacroOutput::findOrFail($id);
        $record->update($validated);

        return redirect()->back()->with('success', 'Record updated successfully.');
    }

    public function index(Request $request)
{
    $query = MacroOutput::query();

    // Filter by TIMESTAMP (string match based on formatted date)
    $formattedDate = null;
    if ($request->filled('date')) {
        $formattedDate = \Carbon\Carbon::parse($request->date)->format('d-m-Y');
        $query->where('TIMESTAMP', 'LIKE', "%$formattedDate");
    }

    // Filter by PAGE
    if ($request->filled('PAGE')) {
        $query->where('PAGE', $request->PAGE);
    }

    // Fetch required columns including 'all_user_input'
    $records = $query->select(
        'id',
        'FULL NAME',
        'PHONE NUMBER',
        'ADDRESS',
        'PROVINCE',
        'CITY',
        'BARANGAY',
        'STATUS',
        'PAGE',
        'TIMESTAMP',
        'all_user_input'
    )
    ->orderByDesc('id')
    ->paginate(100);

    // Populate page options based on selected date
    $pages = collect();
    if ($formattedDate) {
        $pages = MacroOutput::where('TIMESTAMP', 'LIKE', "%$formattedDate")
            ->select('PAGE')
            ->distinct()
            ->orderBy('PAGE')
            ->pluck('PAGE');
    }

    return view('macro_output.index', compact('records', 'pages'));
}

    public function updateField(Request $request)
{
    $request->validate([
        'id' => 'required|integer',
        'field' => 'required|string',
        'value' => 'nullable|string',
    ]);

    $record = \App\Models\MacroOutput::findOrFail($request->id);
    $record->update([$request->field => $request->value]);

    return response()->json(['status' => 'success']);
}





public function bulkUpdate(Request $request)
{
    foreach ($request->input('records', []) as $id => $fields) {
        \App\Models\MacroOutput::where('id', $id)->update($fields);
    }

    return redirect()->back()->with('success', 'All updates saved!');
}

}
