<?php

namespace App\Http\Controllers;

use App\Models\BotcakeChat;
use App\Services\Chatbot\ProblemAcks;
use App\Services\Chatbot\ProblemsPanel;
use App\Services\PageSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Ang Acknowledge, Done at Undo ng isang linya sa banner ng mga problema (ProblemsPanel), may note. Sinumang naka-login sa
 * Chatbot screens ay pwede; ang sino at kailan ay nasa row (page settings, may created_by).
 */
class ChatbotProblemAckController extends Controller
{
    public function __construct(private PageSettings $settings, private ProblemAcks $acks)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'string', 'size:12'],
            'status' => ['required', 'in:ack,done,clear'],
            'note' => ['nullable', 'string', 'max:' . ProblemAcks::MAX_NOTE],
            'until' => ['nullable', 'string', 'max:20'],
            'level' => ['nullable', 'string', 'max:10'],
        ]);
        // Ang page ay dapat nasa listahan ng chatbot (hindi gumagawa ng bagong page sa settings).
        $known = in_array($this->settings->pageKey($data['page']), array_map(fn ($p) => $this->settings->pageKey($p), BotcakeChat::pageMap()), true);
        if (!$known) return response()->json(['error' => 'This page is not on the chatbot list.'], 422);

        $res = $this->acks->set($data['page'], $data['kind'], $data['status'], (string) ($data['note'] ?? ''), $data['until'] ?? null,
            (string) ($data['level'] ?? 'white'), $request->user());
        if (!$res['ok']) return response()->json(['error' => $res['error']], 422);
        Cache::forget(ProblemsPanel::CACHE);

        return response()->json(['ok' => true]);
    }

    /**
     * Acknowledge all o Done all: ang lahat ng linyang nakikita ngayon sa banner, iisang note (at tagal, sa Acknowledge).
     * CEO lang. Ang bawat linya ay sariling row, kaya bumabalik pa rin ang bawat isa ayon sa sarili nitong panuntunan.
     */
    public function storeAll(Request $request): JsonResponse
    {
        $role = preg_replace('/\s+/u', ' ', trim((string) ($request->user()?->employeeProfile?->role ?? $request->user()?->role ?? '')));
        if ($role !== 'CEO') return response()->json(['error' => 'Only the CEO can acknowledge or mark all done.'], 403);
        $data = $request->validate([
            'status' => ['required', 'in:ack,done'],
            'note' => ['required', 'string', 'max:' . ProblemAcks::MAX_NOTE],
            'until' => ['nullable', 'string', 'max:20'],
        ]);
        $panel = app(ProblemsPanel::class)->panel();
        $done = 0;
        foreach ($panel['pages'] ?? [] as $p) {
            foreach ($p['lines'] as $l) {
                if (empty($l['kind'])) continue;
                $res = $this->acks->set($p['page'], $l['kind'], $data['status'], $data['note'], $data['until'] ?? null, $l['level'], $request->user());
                if (!$res['ok']) return response()->json(['error' => $res['error'], 'saved' => $done], 422);
                $done++;
            }
        }
        Cache::forget(ProblemsPanel::CACHE);

        return response()->json(['ok' => true, 'saved' => $done]);
    }
}
