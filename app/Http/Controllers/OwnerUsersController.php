<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * CEO-only oversight view ng employee credentials.
 *
 * View at /owner/users:
 *   - Lists every user with: name (from employee_profiles), role, email,
 *     password (plaintext from `password_plain` if set), date created.
 *   - Read-only listing — walang Create/Delete actions.
 *   - Inline "Edit Password" action lang ang sole mutation: CEO can set a
 *     new password, na sini-store sa BOTH `password` (bcrypt hash) at
 *     `password_plain` (plaintext, para makita ulit later).
 *
 * Security: route guarded by CEO role check. Plaintext exposure is intentional
 * per CEO request. Walang password_plain sa response kung non-CEO accesses
 * (defense in depth: kahit may bug sa frontend gate, server response strips it).
 */
class OwnerUsersController extends Controller
{
    private function checkAccess(): void
    {
        $raw  = Auth::user()?->employeeProfile?->role ?? '';
        $norm = preg_replace('/\s+/u', ' ', trim((string) $raw));
        if (!preg_match('/^ceo$/iu', $norm)) abort(404);
    }

    /** GET /owner/users — table view */
    public function index(Request $request)
    {
        $this->checkAccess();

        $hasPlain = Schema::hasColumn('users', 'password_plain');

        $rows = DB::table('users as u')
            ->leftJoin('employee_profiles as ep', 'ep.user_id', '=', 'u.id')
            ->select([
                'u.id',
                'u.email',
                'u.created_at',
                $hasPlain ? 'u.password_plain' : DB::raw('NULL as password_plain'),
                'ep.name as employee_name',
                'ep.role',
                'ep.employment_type',
                'ep.status as employment_status',
            ])
            ->orderBy('ep.role')
            ->orderBy('ep.name')
            ->orderBy('u.email')
            ->get();

        return view('owner.users', [
            'users' => $rows,
        ]);
    }

    /** POST /owner/users/{id}/password — set new password */
    public function updatePassword(Request $request, int $id)
    {
        $this->checkAccess();

        $validated = $request->validate([
            'password' => 'required|string|min:4|max:255',
        ]);

        $newPlain = (string) $validated['password'];

        $exists = DB::table('users')->where('id', $id)->exists();
        if (!$exists) abort(404, 'User not found.');

        $updates = ['password' => Hash::make($newPlain)];
        if (Schema::hasColumn('users', 'password_plain')) {
            $updates['password_plain'] = $newPlain;
        }
        $updates['updated_at'] = now();

        DB::table('users')->where('id', $id)->update($updates);

        return response()->json([
            'ok'       => true,
            'id'       => $id,
            'password' => $newPlain, // echo back so UI updates the cell
        ]);
    }
}
