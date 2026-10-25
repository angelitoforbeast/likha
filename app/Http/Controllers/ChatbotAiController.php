<?php

namespace App\Http\Controllers;

use App\Services\Chatbot\ChatbotAiCompare;
use App\Services\Chatbot\ChatbotAiSettings;
use App\Services\PageSettings;
use App\Support\NavAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Ang AI ng chatbot sa /chatbot/pages: aling AI ang sumasagot kada page (at ang site default), at ang paghahambing ng mga
 * AI ng isang page. Access gaya ng page (/chatblast): lahat ng may access ay maaaring tumingin at magpalit.
 */
class ChatbotAiController extends Controller
{
    private const MAX_PAGES = 200; // gaya ng bulk na "Replies via"

    public function __construct(private PageSettings $settings, private ChatbotAiSettings $ai)
    {
    }

    private function checkAccess(): void
    {
        $role = preg_replace('/\s+/u', ' ', trim((string) (Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '')));
        if (!NavAccess::allows($role, '/chatblast')) abort(403);
    }

    /** Ang pangalan ng page sa listahan ng chatbot (trim, laki ng letra at espasyo ay hindi mahalaga), o null. */
    private function listedPage(mixed $name): ?string
    {
        if (!is_scalar($name)) return null;
        $key = $this->settings->pageKey(mb_substr((string) $name, 0, 255));
        foreach ($this->settings->pagesWith('botcake', ['page_id', 'token']) as $p) if ($this->settings->pageKey($p) === $key) return $p;

        return null;
    }

    /** Ang AI ng isa o higit pang page (lahat o wala). */
    public function save(Request $request)
    {
        $this->checkAccess();
        $names = $request->input('page_names');
        $names = is_array($names) ? array_values($names) : [$request->input('page_name')];
        if (count($names) < 1 || count($names) > self::MAX_PAGES) return response()->json(['error' => 'Pick 1 to ' . self::MAX_PAGES . ' pages.'], 422);
        $pages = [];
        foreach ($names as $name) {
            $page = $this->listedPage($name);
            if ($page === null) return response()->json(['error' => 'This page is not on the chatbot list.'], 422);
            $pages[$page] = $page;
        }
        [$error, $changes] = ChatbotAiSettings::clean($request->only(ChatbotAiSettings::PAGE_FIELDS), false);
        if ($error !== null) return response()->json(['error' => $error], 422);
        $res = $this->ai->savePages(array_values($pages), $changes, Auth::id());
        if ($res['error'] !== null) return response()->json(['error' => $res['error']], 422);

        return response()->json(['status' => $res['status'], 'pages' => array_values($pages), 'ai' => array_map(fn ($p) => $this->ai->row($p), $pages)]);
    }

    /** Ang site default (provider, fallback, Local model at timeout, cap at breaker). */
    public function site(Request $request)
    {
        $this->checkAccess();
        [$error, $changes] = ChatbotAiSettings::clean($request->only(ChatbotAiSettings::SITE_FIELDS), true);
        if ($error !== null) return response()->json(['error' => $error], 422);
        $res = $this->ai->saveSite($changes, Auth::id());
        if ($res['error'] !== null) return response()->json(['error' => $res['error']], 422);

        return response()->json(['status' => $res['status'], 'ai_site' => $this->ai->site()]);
    }

    /** Ang paghahambing ng mga AI ng isang page sa isang hanay ng petsa (Manila). */
    public function compare(Request $request, ChatbotAiCompare $compare)
    {
        $this->checkAccess();
        $str = fn (string $k) => is_scalar($request->input($k)) ? (string) $request->input($k) : '';
        $page = $this->listedPage($str('page'));

        return response()->json($compare->forPage($page, $str('from'), $str('to')));
    }
}
