<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\EmployeeProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role; // ← idagdag sa taas ng file

class RoleAssignmentController extends Controller
{
    // Kayang gawing CEO ng page na ito ang kahit sinong account, kaya CEO lang ang puwede rito.
    private function isCeo(): bool
    {
        $role = (string) (Auth::user()?->employeeProfile?->role ?? '');
        return strcasecmp(trim($role), 'CEO') === 0;
    }

    public function index()
    {
        if (!$this->isCeo()) {
            abort(403);
        }

        $users = User::with('employeeProfile')->get();
        

$roles = Role::pluck('name'); // Dynamic list from roles table


        return view('assign_roles', compact('users', 'roles'));
    }

    public function update(Request $request)
    {
        if (!$this->isCeo()) {
            return response()->json(['success' => false, 'error' => 'CEO lang ang puwedeng magpalit ng role.'], 403);
        }

        $request->validate([
            'user_id' => 'required|exists:users,id',
            'role_name' => 'required|string|max:255',
        ]);

        // JSON ang sagot (hindi redirect ng validation) dahil fetch ang tumatawag at JSON ang binabasa nito.
        if (!Role::where('name', $request->role_name)->exists()) {
            return response()->json(['success' => false, 'error' => 'Walang ganyang role.'], 422);
        }

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
