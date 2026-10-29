<?php

namespace App\Http\Controllers;

use App\Models\BotcakeChat;
use App\Services\Chatbot\MetaReplySpeed;
use App\Services\Chatbot\ResponseSpeed;
use App\Support\NavAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** /chatbot/speed: ang bilis ng unang sagot ng bot kada oras sa isang araw. Access gaya ng /chatbot/health (403 sa iba). */
class ChatbotSpeedController extends Controller
{
    public function __construct(private ResponseSpeed $speed, private MetaReplySpeed $meta)
    {
    }

    public function index(Request $request)
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';
        if (!NavAccess::allows(preg_replace('/\s+/u', ' ', trim((string) $raw)), '/chatblast')) abort(403);

        $pages = array_flip(BotcakeChat::pageMap());
        ksort($pages, SORT_NATURAL | SORT_FLAG_CASE);
        $out = $this->speed->output($request->query());
        // Ang unang section: ang reply time sa orasan ng Meta mula sa Forbeast (hindi nakakasira ng screen kapag wala ito).
        $meta = $this->meta->output($out['filters'], $out['dates']['today']);
        if ($meta['chart'] !== null) $out['charts']['meta'] = $meta['chart'];

        return view('chatbot.speed', $out + ['meta' => $meta, 'pages' => $pages, 'bots' => \App\Services\Chatbot\ChatbotConnections::bots()]);
    }
}
