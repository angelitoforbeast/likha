<?php

namespace App\Http\Controllers;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Services\Chatbot\ChatbotResults;
use App\Support\NavAccess;
use Carbon\Carbon;
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

    /**
     * Spec 278: ang naitabing kopya lang ang ipinapakita (binubuo ng chatbot:results-warm). Walang kopya: idinadagdag sa
     * wanted at agad sumasagot ng "binubuo pa" (ang page ay kusang nagre-reload); walang report query sa request.
     */
    public function index(Request $request)
    {
        $this->checkAccess();
        $copy = $this->results->stored($request->query());
        $pages = array_flip(BotcakeChat::pageMap());
        ksort($pages, SORT_NATURAL | SORT_FLAG_CASE);
        $form = ['pages' => $pages, 'flows' => ChatbotFlow::orderBy('name')->pluck('name', 'id')->all(), 'pick' => ChatbotResults::compareFrom($request->query())];

        if (!$copy) {
            $this->results->want($request->query());
            $f = ChatbotResults::filters($request->query());

            return view('chatbot.results', $form + ['building' => true, 'asOf' => null, 'filters' => $f + ['page' => null, 'flow' => null], 'by_message' => [], 'compare' => null,
                'versions' => $this->results->versions($f['flow_id'] !== null ? (int) $f['flow_id'] : null)]);
        }

        return view('chatbot.results', $copy['out'] + $form + ['building' => false,
            'asOf' => Carbon::parse($copy['built_at'])->timezone('Asia/Manila')->format('H:i')]);
    }

    /** Ang kopya at ang built_at nito; walang kopya: 202 {status: building} at idinadagdag sa wanted. */
    public function json(Request $request)
    {
        $this->checkAccess();
        $copy = $this->results->stored($request->query());
        if (!$copy) {
            $this->results->want($request->query());

            return response()->json(['status' => 'building'], 202);
        }

        return response()->json($copy['out'] + ['built_at' => $copy['built_at']]);
    }
}
