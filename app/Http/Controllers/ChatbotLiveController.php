<?php

namespace App\Http\Controllers;

use App\Models\BotcakeChat;
use App\Services\Chatbot\LiveFeed;
use App\Support\NavAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** /chatbot/live: ang animated na mapa ng ginagawa ng bot ngayon, at ang JSON na pino-poll nito. Access gaya ng /chatbot/speed (403 sa iba). */
class ChatbotLiveController extends Controller
{
    public function __construct(private LiveFeed $live)
    {
    }

    public function index(Request $request)
    {
        $this->allow();
        $pages = array_flip(BotcakeChat::pageMap());
        ksort($pages, SORT_NATURAL | SORT_FLAG_CASE);
        // Ang unang laman: ang mga bilang at ang cursor ngayon, para ang unang poll ay tuloy mula sa pagbukas ng page.
        $feed = $this->live->feed(['page_id' => $request->query('page_id'), 'bot' => $request->query('bot')]);

        return view('chatbot.live', ['feed' => $feed, 'pages' => $pages, 'page' => LiveFeed::page($request->query()), 'bot' => LiveFeed::bot($request->query())]);
    }

    public function feed(Request $request)
    {
        $this->allow();

        return response()->json($this->live->feed($request->query()))->header('Cache-Control', 'no-store');
    }

    private function allow(): void
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';
        if (!NavAccess::allows(preg_replace('/\s+/u', ' ', trim((string) $raw)), '/chatblast')) abort(403);
    }
}
