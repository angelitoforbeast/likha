<?php

namespace App\Http\Controllers;

use App\Services\Chatbot\ForbeastSite;
use App\Services\Chatbot\PageTransfers;
use App\Services\PageSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * /chatbot/owners: ang bawat page na konektado sa Forbeast at kung aling site ang may-ari (ForbeastSite, GET
 * /v1/site/pages), ang mga request na naghihintay, at ang history ng isang page. Ang CEO ang nakakakita ng lahat at
 * gumagawa ng lahat; ang Marketing at Marketing - OIC ay nakakakita lang ng mga page na walang may-ari at ng sa site na
 * ito (walang request), at take lang ang puwede. Ang iba: 403. Ang Take, Move, Ask at Accept ay kailangan ng sariling
 * password ng gumagawa (Hash::check dito, hindi ipinapadala) at ng phrase na nasa screen; ang Decline at Cancel ay hindi.
 * Ang page at ang may-ari ay laging binabasa ulit sa Forbeast bago sumulat (hindi ang galing sa form). Lahat ng galing sa
 * Forbeast ay text lang.
 */
class ChatbotOwnersController extends Controller
{
    public const CONFIRMED = ['take', 'move', 'ask', 'accept'];
    /** Ang mga role na puwedeng mag-claim (take) ng page na walang may-ari, gaya ng sa Connections ng isang page. */
    public const MARKETING = ['Marketing', 'Marketing - OIC'];
    /** Ang take, move o ask na kapapadala lang para sa isang page (cache, 2 minuto): ang action at ang transfer id. */
    private const BUSY = 'chatbot-owners:busy:';

    public function __construct(private ForbeastSite $site)
    {
    }

    /** Ang role na pare-pareho ang espasyo, gaya ng sa Connections (hal. " Marketing  -  OIC" → "Marketing - OIC"). */
    public static function role(?string $role): string
    {
        return preg_replace('/\s+/u', ' ', trim((string) $role));
    }

    /** Kung nabubuksan ng role ang Owners (ang screen at ang link nito sa nav). */
    public static function opens(?string $role): bool
    {
        return in_array(self::role($role), ['CEO', ...self::MARKETING], true);
    }

    /** CEO: true; marketing: false; ang iba: 403. */
    private function ceo(): bool
    {
        $role = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role;
        if (!self::opens($role)) abort(403);

        return self::role($role) === 'CEO';
    }

    public function index(Request $request)
    {
        $ceo = $this->ceo();
        $linked = ForbeastSite::linked();
        $res = $linked ? $this->site->pages() : null;
        $site = $res !== null && $res['ok'] && isset(ForbeastSite::SITES[$res['body']['site'] ?? '']) ? $res['body']['site'] : ForbeastSite::self();
        $pages = $res !== null && $res['ok'] ? $this->pages($res['body']) : [];
        // Marketing: ang walang may-ari at ang sa site na ito lang, at walang request (hindi nila ito magagalaw).
        if (!$ceo) {
            $pages = array_values(array_map(fn ($p) => ['pending' => []] + $p,
                array_filter($pages, fn ($p) => $p['owner'] === null || $p['owner'] === $site)));
        }
        $pages = array_map(fn ($p) => $this->claim($p, $site), $pages);

        $historyOf = (string) $request->query('history', '');
        $history = null;
        if ($linked && preg_match('/^\d{1,20}$/', $historyOf) && ($ceo || collect($pages)->contains(fn ($p) => $p['id'] === $historyOf))) {
            $h = $this->site->history($historyOf);
            $history = ['page' => $historyOf, 'error' => $h['ok'] ? null : $h['error'], 'items' => $h['ok'] ? self::list($h['body']['transfers'] ?? $h['body']) : []];
        }

        return view('chatbot.owners', ['linked' => $linked, 'error' => $res !== null && !$res['ok'] ? $res['error'] : null, 'site' => $site,
            'other' => ForbeastSite::other($site), 'pages' => $pages, 'history' => $history, 'ceo' => $ceo]);
    }

    public function act(Request $request, string $action)
    {
        if (!$this->ceo() && $action !== 'take') abort(403);
        $back = redirect('/chatbot/owners');
        $fail = fn (string $msg) => $back->with('owners_error', $msg);
        $target = trim((string) $request->input('target'));
        if (!preg_match('/^\d{1,20}$/', $target)) return $fail('Pick a page or request first.');
        if (!ForbeastSite::linked()) return $fail('This site is not linked to Forbeast yet.');
        // Ang pangalawang pindot habang tumatakbo pa ang una: tinatanggihan bago ang anumang tawag sa Forbeast.
        if (in_array($action, ['take', 'move', 'ask'], true) && Cache::has(self::BUSY . $target)) {
            return $fail($action === 'take' ? 'This page is already being claimed.' : 'A request for this page is already in progress.');
        }

        $user = Auth::user();
        // Ang password muna, bago ang anumang tawag sa Forbeast.
        if (in_array($action, self::CONFIRMED, true) && !Hash::check((string) $request->input('password'), (string) $user->password)) {
            return $fail('Wrong password. Nothing was sent.');
        }

        $res = $this->site->pages();
        if (!$res['ok']) return $fail($res['error']);
        $site = isset(ForbeastSite::SITES[$res['body']['site'] ?? '']) ? $res['body']['site'] : ForbeastSite::self();
        $pages = $this->pages($res['body']);

        $plan = $this->plan($action, $target, $pages, $site);
        if (is_string($plan)) return $fail($plan);
        // confirm_text: ang pangalan ng phrase box na hindi mukhang login field (para hindi i-autofill ng browser); ang
        // phrase ay para sa form na bukas na bago ang palit.
        if ($plan['phrase'] !== null && ForbeastSite::squash((string) $request->input('confirm_text', $request->input('phrase'))) !== $plan['phrase']) {
            return $fail('The phrase does not match. Type: ' . $plan['phrase'] . '. Nothing was sent.');
        }

        $actor = mb_substr(ForbeastSite::squash($user->name . ' ' . $user->email), 0, 200);
        // Ang add ay atomic: sa dalawang sabay na pindot, isa lang ang aabot sa Forbeast.
        $busy = in_array($action, ['take', 'move', 'ask'], true);
        if ($busy && !Cache::add(self::BUSY . $target, ['action' => $action, 'transfer' => null], now()->addMinutes(2))) {
            return $fail($action === 'take' ? 'This page is already being claimed.' : 'A request for this page is already in progress.');
        }
        $out = match ($action) {
            'take' => $this->site->take($target, $actor, $plan['phrase']),
            'move', 'ask' => $this->site->request($target, $actor, $plan['phrase']),
            default => $this->site->answer($target, $action, $actor, $plan['phrase']),
        };
        if (!$out['ok']) {
            if ($busy) Cache::forget(self::BUSY . $target);
            return $fail($out['error']);
        }
        if ($busy) {
            $id = $out['body']['transfer_id'] ?? $out['body']['id'] ?? null;
            Cache::put(self::BUSY . $target, ['action' => $action, 'transfer' => is_scalar($id) && ctype_digit((string) $id) ? (string) $id : null], now()->addMinutes(2));
        }

        return $back->with('owners_status', $plan['done']);
    }

    /**
     * Ang take na tumatakbo pa sa Forbeast (job na ilang segundo pagkatapos ng 202): "claiming" habang wala pang may-ari;
     * "claimed" sa unang pagkakita na sa site na ito na; "claim_error" (ang sinabi ng Forbeast) kapag failed o stuck.
     */
    private function claim(array $page, string $site): array
    {
        $page += ['claiming' => null, 'claimed' => false, 'claim_error' => null];
        $busy = Cache::get(self::BUSY . $page['id']);
        if (!is_array($busy) || ($busy['action'] ?? null) !== 'take') return $page;
        if ($page['owner'] !== null) {
            Cache::forget(self::BUSY . $page['id']);
            return ['claimed' => $page['owner'] === $site] + $page;
        }

        $transfer = $busy['transfer'] ?? null;
        $h = $transfer !== null ? $this->site->history($page['id']) : ['ok' => false];
        $t = $h['ok'] ? collect(self::list($h['body']['transfers'] ?? $h['body']))->first(fn ($t) => (string) ($t['id'] ?? '') === $transfer) : null;
        if ($t === null || !in_array($t['status'] ?? null, ['failed', 'stuck'], true)) return ['claiming' => ['transfer' => $transfer]] + $page;

        Cache::forget(self::BUSY . $page['id']);
        $said = collect(self::list($t['steps'] ?? []))->map(fn ($s) => $s['error'] ?? $s['message'] ?? null)->push($t['error'] ?? $t['message'] ?? null)
            ->filter(fn ($v) => is_scalar($v) && trim((string) $v) !== '')->last();

        return ['claim_error' => 'The claim did not finish (' . $t['status'] . ')' . ($said !== null ? ': ' . mb_substr(trim((string) $said), 0, 300) : '.')] + $page;
    }

    /**
     * Ang gagawin (ang phrase o null, at ang mensahe kapag tapos), o ang dahilan kung bakit hindi puwede.
     * @param array<int, array> $pages
     * @return array{phrase: ?string, done: string}|string
     */
    private function plan(string $action, string $target, array $pages, string $site): array|string
    {
        $other = ForbeastSite::other($site);
        if (in_array($action, ['take', 'move', 'ask'], true)) {
            $page = collect($pages)->firstWhere('id', $target);
            if ($page === null) return 'Forbeast does not list this page.';
            if ($action === 'take') {
                return $page['owner'] === null ? ['phrase' => ForbeastSite::phrase('TAKE', $page['name'], $site), 'done' => 'Take started for ' . $page['name'] . '.']
                    : 'This page already has an owner, so it cannot be taken.';
            }
            if ($action === 'move') {
                return $page['owner'] === $site ? ['phrase' => ForbeastSite::phrase('TRANSFER', $page['name'], $other), 'done' => 'Asked ' . ForbeastSite::label($other) . ' to take ' . $page['name'] . '.']
                    : 'This page is not owned by this site.';
            }

            return $page['owner'] === $other ? ['phrase' => ForbeastSite::phrase('TRANSFER', $page['name'], $site), 'done' => 'Asked ' . ForbeastSite::label($other) . ' for ' . $page['name'] . '.']
                : 'This page is not owned by ' . ForbeastSite::label($other) . '.';
        }

        foreach ($pages as $page) {
            foreach ($page['pending'] as $req) {
                if ($req['id'] !== $target) continue;
                $ours = $req['by'] === $site;
                if ($action === 'cancel') return $ours ? ['phrase' => null, 'done' => 'Request cancelled.'] : 'Only the site that asked can cancel a request.';
                if ($ours) return 'Only the other site can accept or decline this request.';

                return $action === 'accept' ? ['phrase' => ForbeastSite::phrase('TRANSFER', $page['name'], $req['to']), 'done' => 'Accepted: the move of ' . $page['name'] . ' has started.']
                    : ['phrase' => null, 'done' => 'Request declined.'];
            }
        }

        return 'This request is no longer pending.';
    }

    /**
     * Ang mga page mula sa sagot ng Forbeast, sinala (text lang, may hangganan). Ang site na humiling: ang may-ari sa push,
     * ang pupuntahan sa pull.
     * @return array<int, array{id: string, name: string, picture: ?string, owner: ?string, connected_at: ?string, pending: array}>
     */
    private function pages(array $body): array
    {
        $str = fn ($v, int $max = 200) => is_scalar($v) && trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, $max) : null;
        $site = fn ($v) => is_string($v) && isset(ForbeastSite::SITES[$v]) ? $v : null;
        $out = [];
        foreach (self::list($body['pages'] ?? []) as $p) {
            $id = $str($p['meta_page_id'] ?? null, 20);
            $name = $str($p['name'] ?? null, 255);
            if ($id === null || !ctype_digit($id) || $name === null) continue;
            $pending = [];
            foreach (self::list($p['pending'] ?? []) as $r) {
                $rid = $str($r['id'] ?? null, 20);
                $dir = in_array($r['direction'] ?? null, ['push', 'pull'], true) ? $r['direction'] : null;
                if ($rid === null || !ctype_digit($rid) || $dir === null || $site($r['to'] ?? null) === null) continue;
                $pending[] = ['id' => $rid, 'direction' => $dir, 'from' => $site($r['from'] ?? null), 'to' => $r['to'],
                    'by' => $dir === 'push' ? $site($r['from'] ?? null) : $r['to'], 'requested_by' => $str($r['requested_by'] ?? null), 'requested_at' => $str($r['requested_at'] ?? null, 40)];
            }
            $pic = $str($p['picture_url'] ?? null, 500);
            $out[] = ['id' => $id, 'name' => $name, 'picture' => $pic !== null && str_starts_with($pic, 'https://') ? $pic : null,
                'owner' => $site($p['owner'] ?? null), 'connected_at' => $str($p['connected_at'] ?? null, 40), 'pending' => $pending, 'hold' => $this->hold($id)];
        }
        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * Ang hold dito ng isang paglipat (naka-pause ang follow-ups ng page): ang id at kailan, o null. Walang expiry: nakikita
     * ito ng CEO hanggang ang release o ang hold:false ng Forbeast.
     * @return array{transfer: string, since: string}|null
     */
    private function hold(string $metaPageId): ?array
    {
        $page = app(PageTransfers::class)->page($metaPageId);
        if ($page === null) return null;
        $row = app(PageSettings::class)->pageQuery($page)->where('provider', PageTransfers::HOLD_PROVIDER)->where('key', PageTransfers::HOLD_KEY)
            ->where('active', true)->orderByDesc('id')->first(['value', 'created_at']);

        return $row === null ? null : ['transfer' => (string) $row->value, 'since' => $row->created_at->toIso8601String()];
    }

    /** @return array<int, array> ang mga array lang ng isang listahan */
    private static function list(mixed $v): array
    {
        return is_array($v) && array_is_list($v) ? array_values(array_filter($v, 'is_array')) : [];
    }
}
