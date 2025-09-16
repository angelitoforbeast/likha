<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\JntReturnScanned;
use Carbon\Carbon;

class JntReturnScannedController extends Controller
{
    public function index(Request $request)
    {
        // Default filter: this month
        $start = $request->input('start_date') ?? Carbon::now()->startOfMonth()->toDateString();
        $end   = $request->input('end_date')   ?? Carbon::now()->endOfMonth()->toDateString();

        $query = JntReturnScanned::query()
            ->whereBetween('scanned_at', [$start, $end]);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('waybill_number', 'LIKE', "%$search%");
        }

        $rows = $query->orderBy('scanned_at', 'desc')->paginate(20);

        return view('jnt.return.scanned', compact('rows', 'start', 'end'));
    }

    public function upload(Request $request)
    {
        $request->validate([
            'waybills' => 'required|string',
            'scanned_at' => 'required|date',
        ]);

        $waybills = preg_split('/\r\n|\r|\n/', trim($request->waybills));
        $date     = Carbon::parse($request->input('scanned_at'));

        foreach ($waybills as $wb) {
            $wb = trim($wb);
            if ($wb === '') continue;

            JntReturnScanned::updateOrCreate(
                ['waybill_number' => $wb],
                ['scanned_at' => $date, 'scanned_by' => auth()->user()->name ?? 'system']
            );
        }

        return redirect()->route('jnt.return.scanned')->with('success', 'Waybills uploaded successfully!');
    }

    public function destroy($id)
    {
        JntReturnScanned::findOrFail($id)->delete();
        return back()->with('success', 'Deleted successfully!');
    }
}
