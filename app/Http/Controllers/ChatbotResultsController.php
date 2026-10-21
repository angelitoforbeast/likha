<?php

namespace App\Http\Controllers;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Services\Chatbot\ChatbotResults;
use App\Support\NavAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * /chatbot/results: kung aling padala ang sinasagot, inoorderan at nade-deliver, kada flow version at botfield version.
 * results.json: ang parehong numero para kay Mira at sa AI (read-only). Access gaya ng /chatbot (403 sa iba).
 */
class ChatbotResultsController extends Controller
{
    public function __construct(private ChatbotResults $results)
    {
    }

    private function checkAccess(): void
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';
        if (!NavAccess::allows(preg_replace('/\s+/u', ' ', trim((string) $raw)), '/chatblast')) abort(403);
    }

    public function index(Request $request)
    {
        $this->checkAccess();
        $out = $this->results->output($request->query());
        $pages = array_flip(BotcakeChat::pageMap());
        ksort($pages, SORT_NATURAL | SORT_FLAG_CASE);

        return view('chatbot.results', $out + ['pages' => $pages, 'flows' => ChatbotFlow::orderBy('name')->pluck('name', 'id')->all(),
            'pick' => ChatbotResults::compareFrom($request->query())]);
    }

    public function json(Request $request)
    {
        $this->checkAccess();

        return response()->json($this->results->output($request->query()));
    }
}
