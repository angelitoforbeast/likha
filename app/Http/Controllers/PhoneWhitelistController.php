<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\PhoneWhitelist;

class PhoneWhitelistController extends Controller
{
    /* ─── helpers ─── */

    private function scope(Request $request): string
    {
        return str_contains(strtolower((string) $request->getHost()), 'incepxion')
            ? 'incepxion' : 'likha';
    }

    private function userRole(): string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? '';
        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    private function canAccess(): bool
    {
        $role = $this->userRole();
        return preg_match('/^(ceo|marketing|marketing\s*[-–—]\s*oic)$/iu', $role) === 1;
    }

    private function canDelete(): bool
    {
        $role = $this->userRole();
        return preg_match('/^(ceo|marketing\s*[-–—]\s*oic)$/iu', $role) === 1;
    }

    /* ─── Full Page ─── */

    public function index(Request $request)
    {
        if (! $this->canAccess()) abort(403);

        return view('macro_output.phone-whitelist');
    }

    /* ─── JSON API ─── */

    public function data(Request $request)
    {
        if (! $this->canAccess()) abort(403);

        $scope = $this->scope($request);

        $items = PhoneWhitelist::with('creator')
            ->where('host_scope', $scope)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'items'      => $items,
            'can_delete' => $this->canDelete(),
        ]);
    }

    public function store(Request $request)
    {
        if (! $this->canAccess()) abort(403);

        $data = $request->validate([
            'phone_number' => 'required|string|max:20',
            'reason'       => 'nullable|string|max:255',
        ]);

        $phone = preg_replace('/[^0-9]/', '', $data['phone_number']);
        $scope = $this->scope($request);

        // Check if already exists
        $exists = PhoneWhitelist::where('phone_number', $phone)
            ->where('host_scope', $scope)
            ->exists();

        if ($exists) {
            return response()->json(['error' => 'Phone number already whitelisted.'], 422);
        }

        $item = PhoneWhitelist::create([
            'phone_number' => $phone,
            'reason'       => $data['reason'] ?? null,
            'host_scope'   => $scope,
            'created_by'   => Auth::id(),
        ]);

        $item->load('creator');

        return response()->json(['item' => $item], 201);
    }

    public function destroy(PhoneWhitelist $phoneWhitelist)
    {
        if (! $this->canDelete()) abort(403);

        $phoneWhitelist->delete();

        return response()->json(['ok' => true]);
    }
}
