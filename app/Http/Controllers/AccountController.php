<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AccountRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * New accounts, made inside the app (spec 298): only a CEO or a Marketing - OIC, with the role set at creation.
 *
 * The password is stored as /owner/users stores the passwords it sets (bcrypt in `password`, the plain value in
 * `password_plain` for the CEO's oversight list), and `users.created_by` keeps who made the account. The role the
 * actor may give is checked here against AccountRoles, whatever the form sent.
 */
class AccountController extends Controller
{
    /** The old public sign-up's minimum, kept for accounts made here. */
    public const PASSWORD_MIN = 6;

    /** GET /accounts/create */
    public function create()
    {
        $roles = $this->allowedRoles();

        return view('accounts.create', [
            'roles'       => $roles,
            'passwordMin' => self::PASSWORD_MIN,
        ]);
    }

    /** POST /accounts */
    public function store(Request $request)
    {
        $roles = $this->allowedRoles();

        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role'     => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($roles) {
                if (!is_string($value) || !in_array(AccountRoles::canonical($value), $roles, true)) {
                    $fail('Choose one of the roles in the list.');
                }
            }],
            'password' => ['required', 'string', 'min:' . self::PASSWORD_MIN, 'max:255', 'confirmed'],
        ]);

        $role = AccountRoles::canonical($validated['role']);
        $creatorId = (int) Auth::id();

        // One transaction: an account is never left without its role, password copy or creator.
        $user = DB::transaction(function () use ($validated, $role, $creatorId) {
            // User::booted makes the employee profile (name, employee code, Active); the role is set on that profile.
            $user = User::create([
                'name'     => $validated['name'],
                'email'    => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);
            $user->employeeProfile()->update(['role' => $role]);

            $extra = [];
            if (Schema::hasColumn('users', 'password_plain')) {
                $extra['password_plain'] = $validated['password'];
            }
            if (Schema::hasColumn('users', 'created_by')) {
                $extra['created_by'] = $creatorId;
            }
            if ($extra) {
                DB::table('users')->where('id', $user->id)->update($extra);
            }

            return $user;
        });

        $profile = $user->employeeProfile()->first();

        return redirect()->route('accounts.create')->with('created', [
            'name'          => $profile?->name ?? $user->name,
            'email'         => $user->email,
            'role'          => $role,
            'employee_code' => $profile?->employee_code,
        ]);
    }

    /** The roles the signed-in user may give; 403 when they may not create accounts at all. */
    private function allowedRoles(): array
    {
        $roles = AccountRoles::creatableBy(Auth::user()?->employeeProfile?->role);
        if ($roles === []) {
            abort(403);
        }

        return $roles;
    }
}
