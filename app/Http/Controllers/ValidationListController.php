<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\PhoneWhitelist;
use App\Models\FbnameBlacklist;
use App\Models\KeywordBlacklist;
use App\Models\AddressKeywordBlacklist;

class ValidationListController extends Controller
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
        return preg_match('/^(ceo|marketing|marketing\s*[-–—]\s*oic)$/iu', $this->userRole()) === 1;
    }

    private function canDelete(): bool
    {
        return preg_match('/^(ceo|marketing\s*[-–—]\s*oic)$/iu', $this->userRole()) === 1;
    }

    /* ═══════════════════════════════════════════
     *  PAGE
     * ═══════════════════════════════════════════ */

    public function index(Request $request)
    {
        if (! $this->canAccess()) abort(403);

        return view('macro_output.validation-lists');
    }

    /* ═══════════════════════════════════════════
     *  PHONE WHITELIST  (JSON API)
     * ═══════════════════════════════════════════ */

    public function phoneData(Request $request)
    {
        if (! $this->canAccess()) abort(403);

        $items = PhoneWhitelist::with('creator')
            ->where('host_scope', $this->scope($request))
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'items'      => $items,
            'can_delete' => $this->canDelete(),
        ]);
    }

    public function phoneStore(Request $request)
    {
        if (! $this->canAccess()) abort(403);

        $data = $request->validate([
            'phone_number' => 'required|string|max:20',
            'reason'       => 'nullable|string|max:255',
        ]);

        $phone = preg_replace('/[^0-9]/', '', $data['phone_number']);
        $scope = $this->scope($request);

        if (PhoneWhitelist::where('phone_number', $phone)->where('host_scope', $scope)->exists()) {
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

    public function phoneDestroy(PhoneWhitelist $phone)
    {
        if (! $this->canDelete()) abort(403);
        $phone->delete();
        return response()->json(['ok' => true]);
    }

    /* ═══════════════════════════════════════════
     *  FB NAME BLACKLIST  (JSON API)
     * ═══════════════════════════════════════════ */

    public function fbnameData(Request $request)
    {
        if (! $this->canAccess()) abort(403);

        $items = FbnameBlacklist::with('creator')
            ->where('host_scope', $this->scope($request))
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'items'      => $items,
            'can_delete' => $this->canDelete(),
        ]);
    }

    public function fbnameStore(Request $request)
    {
        if (! $this->canAccess()) abort(403);

        $data = $request->validate([
            'fb_name' => 'required|string|max:255',
            'reason'  => 'nullable|string|max:255',
        ]);

        $fbName = trim($data['fb_name']);
        $scope  = $this->scope($request);

        if (FbnameBlacklist::whereRaw('LOWER(fb_name) = ?', [mb_strtolower($fbName)])->where('host_scope', $scope)->exists()) {
            return response()->json(['error' => 'FB name already blacklisted.'], 422);
        }

        $item = FbnameBlacklist::create([
            'fb_name'    => $fbName,
            'reason'     => $data['reason'] ?? null,
            'host_scope' => $scope,
            'created_by' => Auth::id(),
        ]);
        $item->load('creator');

        return response()->json(['item' => $item], 201);
    }

    public function fbnameDestroy(FbnameBlacklist $fbname)
    {
        if (! $this->canDelete()) abort(403);
        $fbname->delete();
        return response()->json(['ok' => true]);
    }

    /* ═══════════════════════════════════════════
     *  KEYWORD BLACKLIST  (JSON API)
     * ═══════════════════════════════════════════ */

    public function keywordData(Request $request)
    {
        if (! $this->canAccess()) abort(403);

        $items = KeywordBlacklist::with('creator')
            ->where('host_scope', $this->scope($request))
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'items'      => $items,
            'can_delete' => $this->canDelete(),
        ]);
    }

    public function keywordStore(Request $request)
    {
        if (! $this->canAccess()) abort(403);

        $data = $request->validate([
            'keyword' => 'required|string|max:255',
            'reason'  => 'nullable|string|max:255',
        ]);

        $keyword = trim($data['keyword']);
        $scope   = $this->scope($request);

        if (KeywordBlacklist::whereRaw('LOWER(keyword) = ?', [mb_strtolower($keyword)])->where('host_scope', $scope)->exists()) {
            return response()->json(['error' => 'Keyword already blacklisted.'], 422);
        }

        $item = KeywordBlacklist::create([
            'keyword'    => $keyword,
            'reason'     => $data['reason'] ?? null,
            'host_scope' => $scope,
            'created_by' => Auth::id(),
        ]);
        $item->load('creator');

        return response()->json(['item' => $item], 201);
    }

    public function keywordDestroy(KeywordBlacklist $keyword)
    {
        if (! $this->canDelete()) abort(403);
        $keyword->delete();
        return response()->json(['ok' => true]);
    }

    /* ═══════════════════════════════════════════
     *  ADDRESS KEYWORD BLACKLIST  (JSON API)
     *  — hinahanap sa ADDRESS (Line 1) tuwing Validate / Validate 1
     * ═══════════════════════════════════════════ */

    public function addressKeywordData(Request $request)
    {
        if (! $this->canAccess()) abort(403);

        $items = AddressKeywordBlacklist::with('creator')
            ->where('host_scope', $this->scope($request))
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'items'      => $items,
            'can_delete' => $this->canDelete(),
        ]);
    }

    public function addressKeywordStore(Request $request)
    {
        if (! $this->canAccess()) abort(403);

        $data = $request->validate([
            'keyword' => 'required|string|max:255',
            'reason'  => 'nullable|string|max:255',
        ]);

        $keyword = trim($data['keyword']);
        $scope   = $this->scope($request);

        if (AddressKeywordBlacklist::whereRaw('LOWER(keyword) = ?', [mb_strtolower($keyword)])->where('host_scope', $scope)->exists()) {
            return response()->json(['error' => 'Address keyword already blacklisted.'], 422);
        }

        $item = AddressKeywordBlacklist::create([
            'keyword'    => $keyword,
            'reason'     => $data['reason'] ?? null,
            'host_scope' => $scope,
            'created_by' => Auth::id(),
        ]);
        $item->load('creator');

        return response()->json(['item' => $item], 201);
    }

    public function addressKeywordDestroy(AddressKeywordBlacklist $addressKeyword)
    {
        if (! $this->canDelete()) abort(403);
        $addressKeyword->delete();
        return response()->json(['ok' => true]);
    }
}
