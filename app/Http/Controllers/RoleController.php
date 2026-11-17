<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use DB;

class RoleController extends Controller
{
    /** Spec 294: only the CEO may see, add or rename roles (same rule as ChecklistController::isCeo()). */
    private function abortUnlessCeo(): void
    {
        $role = strtoupper(trim((string) (Auth::user()?->employeeProfile?->role ?? '')));
        if ($role !== 'CEO') abort(403);
    }

    public function index()
    {
        $this->abortUnlessCeo();
        $roles = DB::table('roles')->get();
        return view('roles.index', compact('roles'));
    }

    public function store(Request $request)
    {
        $this->abortUnlessCeo();

        $request->validate([
            'name' => 'required|string|max:255',
            'access_level' => 'nullable|integer',
        ]);

        DB::table('roles')->insert([
            'name' => $request->name,
            'guard_name' => 'web',
            'access_level' => $request->access_level,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Role added.');
    }

    public function update(Request $request, $id)
    {
        $this->abortUnlessCeo();

        $request->validate([
            'name' => 'required|string|max:255',
            'access_level' => 'nullable|integer',
        ]);

        DB::table('roles')->where('id', $id)->update([
            'name' => $request->name,
            'access_level' => $request->access_level,
            'updated_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Role updated.');
    }
}
