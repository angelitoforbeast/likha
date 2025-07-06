<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class RoleAssignmentController extends Controller
{
    public function index()
    {
        $users = User::with('roles')->get();
        $roles = Role::orderBy('access_level', 'desc')->get(); // Make sure 'access_level' exists in roles table
        return view('assign_roles', compact('users', 'roles'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'role_name' => 'required|exists:roles,name',
        ]);

        $user = User::findOrFail($request->input('user_id'));
        $roleName = $request->input('role_name');

        $role = Role::where('name', $roleName)->first();

        $user->syncRoles([$role->name]);

        return response()->json([
            'success' => true,
            'role' => $role->name,
            'access_level' => $role->access_level,
        ]);
    }
}
