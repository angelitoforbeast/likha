<?php

namespace App\Http\Controllers;

use App\Models\ChatblastBroadcast;
use App\Services\Broadcast;
use App\Support\NavAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * /chatblast/broadcast (spec 263): one message to a chosen audience of PSIDs. Same access as /chatblast (NavAccess, 404
 * for others); the routes have the chatblast send middleware and throttles. Every input is checked by Broadcast.
 */
class ChatblastBroadcastController extends Controller
{
    public function __construct(private Broadcast $broadcast)
    {
    }

    private function checkAccess(): void
    {
        $role = preg_replace('/\s+/u', ' ', trim((string) (Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '')));
        if (!NavAccess::allows($role, '/chatblast')) abort(404);
    }

    private function result(array|string|null $r): JsonResponse
    {
        if ($r === null) return response()->json(['error' => 'No such broadcast.'], 404);

        return is_string($r) ? response()->json(['error' => $r], 422) : response()->json($r);
    }

    public function index()
    {
        $this->checkAccess();

        return view('chatblast.broadcast', [
            'pages' => $this->broadcast->pages(),
            'defaultCap' => Broadcast::DEFAULT_CAP,
            'maxCap' => Broadcast::MAX_CAP,
        ]);
    }

    /** The broadcasts, newest first, with their state and total. */
    public function data()
    {
        $this->checkAccess();
        $list = ChatblastBroadcast::orderByDesc('id')->limit(50)->get()->map(fn ($b) => $this->broadcast->summary($b))->all();

        return response()->json(['broadcasts' => $list]);
    }

    /** The flow names across the chosen pages (GET flows of each page, cached). */
    public function flows(Request $request)
    {
        $this->checkAccess();
        $v = $this->broadcast->validate(['pages' => (array) $request->input('pages', [])]);
        if (is_string($v)) return response()->json(['error' => $v], 422);

        return response()->json($this->broadcast->flowNames($v['pages']));
    }

    public function preview(Request $request)
    {
        $this->checkAccess();
        $id = $request->filled('id') ? (int) $request->input('id') : null;

        return $this->result($this->broadcast->preview($id, $request->only(['pages', 'orders', 'phone', 'text', 'flow', 'cap', 'name', ...Broadcast::DATES]), Auth::id()));
    }

    public function start(Request $request, int $id)
    {
        $this->checkAccess();
        $total = $request->input('total');

        return $this->result($this->broadcast->start($id, is_numeric($total) ? (int) $total : null, Auth::id()));
    }

    public function test(Request $request, int $id)
    {
        $this->checkAccess();
        $page = $request->input('page');
        $r = $this->broadcast->test($id, is_string($page) ? $page : '', Auth::id());
        if (is_string($r)) return response()->json(['error' => $r], 422);

        return response()->json(['id' => $r->id, 'status' => $r->status]);
    }

    public function progress(int $id)
    {
        $this->checkAccess();

        return $this->result($this->broadcast->progress($id));
    }

    public function pause(int $id)
    {
        $this->checkAccess();

        return $this->result($this->broadcast->pause($id));
    }

    public function resume(int $id)
    {
        $this->checkAccess();

        return $this->result($this->broadcast->resume($id));
    }

    public function stop(int $id)
    {
        $this->checkAccess();

        return $this->result($this->broadcast->stop($id));
    }

    public function cap(Request $request, int $id)
    {
        $this->checkAccess();

        return $this->result($this->broadcast->setCap($id, (int) $request->input('cap')));
    }
}
