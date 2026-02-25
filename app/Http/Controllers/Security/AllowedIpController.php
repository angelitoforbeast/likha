<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Models\AllowedIp;
use Illuminate\Http\Request;

class AllowedIpController extends Controller
{
    public function index()
    {
        $role = auth()->user()->employeeProfile?->role;

        if ($role === 'CEO') {
            // CEO sees all
            $ips = AllowedIp::with('creator')->orderBy('id', 'desc')->get();
        } else {
            // Non-CEO (Data Encoder - OIC, Marketing - OIC) see only records they created
            $ips = AllowedIp::with('creator')
                ->where('created_by', auth()->id())
                ->orderBy('id', 'desc')
                ->get();
        }

        return view('security.allowed_ips.index', compact('ips'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'ip_address' => ['required', 'ip', 'unique:allowed_ips,ip_address'],
            'label'      => ['nullable', 'string', 'max:100'],
        ]);

        // record who created the IP
        $data['created_by'] = auth()->id();

        AllowedIp::create($data);

        return back()->with('success', 'IP added.');
    }

    public function update(Request $request, AllowedIp $allowedIp)
    {
        $data = $request->validate([
            'ip_address' => ['required', 'ip', 'unique:allowed_ips,ip_address,' . $allowedIp->id],
            'label'      => ['nullable', 'string', 'max:100'],
        ]);

        $allowedIp->update($data);

        return back()->with('success', 'IP updated.');
    }

    public function destroy(AllowedIp $allowedIp)
    {
        $allowedIp->delete();
        return back()->with('success', 'IP deleted.');
    }
}
