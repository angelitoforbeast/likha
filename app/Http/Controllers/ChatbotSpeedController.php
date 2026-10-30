<?php

namespace App\Http\Controllers;

use App\Models\BotcakeChat;
use App\Services\Chatbot\MetaReplySpeed;
use App\Services\Chatbot\ResponseSpeed;
use App\Support\NavAccess;
use Carbon\CarbonImmutable;
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
        $this->authorizeSpeed();
        $pages = array_flip(BotcakeChat::pageMap());
        ksort($pages, SORT_NATURAL | SORT_FLAG_CASE);
        $out = $this->speed->output($request->query());
        // Ang unang section: ang reply time sa orasan ng Meta mula sa Forbeast (hindi nakakasira ng screen kapag wala ito).
        $meta = $this->meta->output($out['filters'], $out['dates']['today']);
        if ($meta['chart'] !== null) $out['charts']['meta'] = $meta['chart'];

        return view('chatbot.speed', $out + ['meta' => $meta, 'pages' => $pages, 'bots' => \App\Services\Chatbot\ChatbotConnections::bots()]);
    }

    /**
     * Ang Sequences section, binubuksan ng page pagkatapos nitong lumabas (ito ang pinakamabigat kuwentahin). Walang part:
     * ang section ng araw (HTML at ang mga numero); part=chart: ang chart nito kasama ang naunang araw. Parehong filter at
     * access ng page.
     */
    public function sequences(Request $request)
    {
        $this->authorizeSpeed();
        if ($request->query('part') === 'chart') return response()->json($this->speed->sequencesOutput($request->query(), true));
        $out = $this->speed->sequencesOutput($request->query());
        $prev = CarbonImmutable::parse($out['filters']['date'])->subDay()->toDateString();

        return response()->json($out + ['html' => view('chatbot.partials.speed-sequences', ['sequences' => $out['sequences'], 'charts' => ['prev_date' => $prev]])->render()]);
    }

    /** Ang listahan ng Not sent (pinakabago muna) ng araw o ng isang oras (?hour=0–23), parehong filter at access ng page. */
    public function notSent(Request $request)
    {
        $this->authorizeSpeed();
        $out = $this->speed->notSentOutput($request->query());

        return response()->json($out + ['html' => view('chatbot.partials.speed-not-sent', $out)->render()]);
    }

    private function authorizeSpeed(): void
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';
        if (!NavAccess::allows(preg_replace('/\s+/u', ' ', trim((string) $raw)), '/chatblast')) abort(403);
    }
}
