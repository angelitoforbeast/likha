<?php

namespace App\Http\Controllers;

use App\Models\BotcakeChat;
use App\Services\Chatbot\MetaReplySpeed;
use App\Services\Chatbot\ResponseSpeed;
use App\Support\NavAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * /chatbot/speed: ang bilis ng unang sagot ng bot kada oras sa isang araw. Access gaya ng /chatbot/health (403 sa iba).
 * Spec 306: ang page at ang Not sent ay nagbabasa lang ng nabuo ng warm; bawat SELECT ng request ay may hangganang
 * ResponseSpeed::PAGE_SECONDS (MySQL), at ang lampas ay sagot na "too slow" agad, hindi isang worker na hawak nang isang minuto.
 */
class ChatbotSpeedController extends Controller
{
    public function __construct(private ResponseSpeed $speed, private MetaReplySpeed $meta)
    {
    }

    public function index(Request $request)
    {
        $this->authorizeSpeed();
        $bots = \App\Services\Chatbot\ChatbotConnections::bots();
        try {
            [$pages, $out] = ResponseSpeed::withBudget(ResponseSpeed::PAGE_SECONDS, function () use ($request) {
                $pages = array_flip(BotcakeChat::pageMap());
                ksort($pages, SORT_NATURAL | SORT_FLAG_CASE);

                return [$pages, $this->speed->output($request->query())];
            });
        } catch (\Throwable $e) {
            if (!ResponseSpeed::isTimeout($e)) throw $e;
            $this->tooSlow('page', $e);
            $f = ResponseSpeed::filters($request->query());

            return view('chatbot.speed', ['filters' => $f, 'slow' => true, 'dates' => ResponseSpeed::dates($f['date']), 'pages' => [], 'bots' => $bots]);
        }
        $extra = ['pages' => $pages, 'bots' => $bots];
        // Wala pa ang araw (binubuo ng warm): ang maikling "computing", at ang page ay nagre-reload pagkalipas ng isang minuto.
        if (!empty($out['computing'])) return view('chatbot.speed', $out + $extra);
        // Ang unang section: ang reply time sa orasan ng Meta mula sa Forbeast (hindi nakakasira ng screen kapag wala ito).
        $meta = $this->meta->output($out['filters'], $out['dates']['today']);
        if ($meta['chart'] !== null) $out['charts']['meta'] = $meta['chart'];

        return view('chatbot.speed', $out + ['meta' => $meta] + $extra);
    }

    /**
     * Ang Sequences section, binubuksan ng page pagkatapos nitong lumabas (ito ang pinakamabigat kuwentahin). Walang part:
     * ang section ng araw (HTML at ang mga numero); part=chart: ang chart nito kasama ang naunang araw. Parehong filter at
     * access ng page. Ang default na view ng ngayon at kahapon ay galing lang sa warm (kapag wala pa: "building", agad);
     * ang may page o bot ay kinukuwenta rito gaya ng dati.
     */
    public function sequences(Request $request)
    {
        $this->authorizeSpeed();
        try {
            if ($request->query('part') === 'chart') return response()->json(ResponseSpeed::withBudget(ResponseSpeed::PAGE_SECONDS, fn () => $this->speed->sequencesOutput($request->query(), true, true)));
            $out = ResponseSpeed::withBudget(ResponseSpeed::PAGE_SECONDS, fn () => $this->speed->sequencesOutput($request->query(), false, true));
        } catch (\Throwable $e) {
            if (!ResponseSpeed::isTimeout($e)) throw $e;
            $this->tooSlow('sequences', $e);

            return response()->json(['slow' => true, 'html' => '<p class="text-sm text-amber-700" role="status" data-sp-seq-state="slow">' . e(self::TOO_SLOW) . '</p>']);
        }
        $prev = CarbonImmutable::parse($out['filters']['date'])->subDay()->toDateString();

        return response()->json($out + ['html' => view('chatbot.partials.speed-sequences', ['sequences' => $out['sequences'], 'builtAt' => $out['built_at'], 'charts' => ['prev_date' => $prev]])->render()]);
    }

    /** Ang listahan ng Not sent (pinakabago muna) ng araw o ng isang oras (?hour=0–23), parehong filter at access ng page. */
    public function notSent(Request $request)
    {
        $this->authorizeSpeed();
        try {
            $out = ResponseSpeed::withBudget(ResponseSpeed::PAGE_SECONDS, fn () => $this->speed->notSentOutput($request->query()));
        } catch (\Throwable $e) {
            if (!ResponseSpeed::isTimeout($e)) throw $e;
            $this->tooSlow('not-sent', $e);

            return response()->json(['slow' => true, 'html' => '<p class="text-sm text-amber-700" role="status">' . e(self::TOO_SLOW) . '</p>']);
        }

        return response()->json($out + ['html' => view('chatbot.partials.speed-not-sent', $out)->render()]);
    }

    public const TOO_SLOW = 'The numbers are taking too long to load. Try again in a minute.';

    /** Isang linya ng log kada sagot na "too slow" (walang customer data): kung aling bahagi at ang mensahe ng MySQL. */
    private function tooSlow(string $part, \Throwable $e): void
    {
        Log::warning('Chatbot speed too slow', ['part' => $part, 'error' => mb_substr($e->getMessage(), 0, 200)]);
    }

    private function authorizeSpeed(): void
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';
        if (!NavAccess::allows(preg_replace('/\s+/u', ' ', trim((string) $raw)), '/chatblast')) abort(403);
    }
}
