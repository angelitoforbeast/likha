<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Models\AllowedIp;
use Illuminate\Http\Request;

class AllowedIpController extends Controller
{
    public function index()
    {
        $ips = AllowedIp::orderBy('id', 'desc')->get();
        return view('security.allowed_ips.index', compact('ips'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'ip_address' => ['required', 'ip', 'unique:allowed_ips,ip_address'],
            'label'      => ['nullable', 'string', 'max:100'],
        ]);

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
