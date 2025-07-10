<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use DB;

class RoleController extends Controller
{
    public function index()
    {
        $roles = DB::table('roles')->get();
        return view('roles.index', compact('roles'));
    }

    public function store(Request $request)
    {
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
