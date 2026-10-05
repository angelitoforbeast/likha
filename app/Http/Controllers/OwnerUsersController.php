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
        // Handoff 012, Amendment 012-1 (A2): bagong remember token sa parehong update, para patay na ang
        // lahat ng remember cookie na inilabas bago ang palit ng password (60 chars, gaya ng framework).
        $updates['remember_token'] = \Illuminate\Support\Str::random(60);
        $updates['updated_at'] = now();

        // Amendment 012-2 (B1): tapos na rin ang mga session na bukas na ng user na ito sa ibang browser.
        // Naiiwan ang session ng gumagawa ng request: kapag ibang user ang pinalitan, hindi naman kanya ang
        // mga row na iyon; kapag sarili niyang password, ito lang ang natitira sa kanya.
        // Iisang transaction: kapag pumalya ang pagbura ng sessions, hindi rin napapalitan ang password.
        $keepSessionId = $request->session()->getId();
        DB::transaction(function () use ($id, $updates, $keepSessionId) {
            DB::table('users')->where('id', $id)->update($updates);

            \App\Listeners\RefuseRememberedLoginUnlessCeo::endSessionsOf($id, $keepSessionId);
        });

        return response()->json([
            'ok'       => true,
            'id'       => $id,
            'password' => $newPlain, // echo back so UI updates the cell
        ]);
    }
}
