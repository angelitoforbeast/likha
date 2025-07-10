<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\EmployeeProfile;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role; // ← idagdag sa taas ng file

class RoleAssignmentController extends Controller
{
    public function index()
    {
        $users = User::with('employeeProfile')->get();
        

$roles = Role::pluck('name'); // Dynamic list from roles table


        return view('assign_roles', compact('users', 'roles'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'role_name' => 'required|string|max:255',
        ]);

        $profile = EmployeeProfile::where('user_id', $request->user_id)->first();

        if (!$profile) {
            return response()->json(['success' => false, 'error' => 'Employee profile not found.']);
        }

        $profile->role = $request->role_name;
        $profile->save();

        return response()->json([
            'success' => true,
            'role' => $profile->role,
            'access_level' => '-', // Optional if no access level in employee_profiles
        ]);
    }
}
