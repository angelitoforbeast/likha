<?php

namespace App\Http\Controllers;

use App\Models\BotcakePsid;
use App\Services\BotcakeCustomerMatcher;
use App\Services\PageSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * /page-settings/customers?page=: ang Botcake customers (API rows ng botcake_psids) ng isang page, read-only.
 * CEO lang (404 sa iba). 50 bawat page, pinakabagong interaction muna. Ang paghahanap ay sa naka-index na
 * name_key (simula ng pangalan) o phone_key (buong 09XXXXXXXXX). Ang bilang ng orders ay isang grouped query
 * para sa 50 na nakikita.
 */
class BotcakeCustomersController extends Controller
{
    public const STATUSES = ['all', 'available', 'inactive'];

    public function __construct(private PageSettings $settings)
    {
    }

    private function getNormalizedRole(): string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';
        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    private function checkAccess(): void
    {
        if ($this->getNormalizedRole() !== 'CEO') abort(404);
    }

    public function index(Request $request)
    {
        $this->checkAccess();

        $page   = $this->settings->normPage($this->text($request->query('page')));
        $search = trim($this->text($request->query('q')));
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : 'all';
        $hasAd  = $request->boolean('has_ad');

        // Ang botcake_psids.page ay ang baybay ng page sa Botcake settings (iyon ang ginamit ng sync).
        $apiPage = $this->settings->pageQuery($page)->where('provider', 'botcake')->where('active', true)
            ->orderBy('id')->value('page_name') ?? $page;

        $q = BotcakePsid::where('source', 'api')->where('page', $apiPage);
        if ($status !== 'all') $q->where('status', $status);
        if ($hasAd) $q->whereNotNull('ad_id');
        if ($search !== '') {
            $phone = BotcakeCustomerMatcher::normPhone($search);
            if ($phone !== '') {
                $q->where('phone_key', $phone);
            } else {
                // Simula ng pangalan, para magamit ang index. Ang % o _ sa hinahanap ay wildcard (read-only, CEO lang).
                $q->where('name_key', 'like', BotcakeCustomerMatcher::normName($search) . '%');
            }
        }

        // Pinakabagong interaction muna; ang walang petsa ay huli (Postgres: NULL ang una sa DESC kung hindi sasabihin).
        $q->orderByRaw(DB::getDriverName() === 'pgsql' ? 'last_interactive_at DESC NULLS LAST' : 'last_interactive_at DESC')
            ->orderByDesc('id');

        $customers = $q->paginate(50, ['*'], 'p')->withQueryString();

        // Ilang order ng page ang may PSID ng bawat customer na nakikita: isang grouped query.
        $psids = collect($customers->items())->pluck('psid')->filter()->unique()->values()->all();
        $orderCounts = $psids
            ? $this->orderCountsQuery($page, $psids)->pluck('c', 'botcake_psid')->map(fn ($c) => (int) $c)->all()
            : [];

        return view('page_settings.customers', compact('customers', 'orderCounts', 'page', 'search', 'status', 'hasAd'));
    }

    /** Bilang ng orders ng page kada PSID (sa botcake_psid index). */
    public function orderCountsQuery(string $page, array $psids)
    {
        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        $col  = DB::getDriverName() === 'pgsql' ? '"PAGE"' : '`PAGE`';

        return DB::table('macro_output')->whereIn('botcake_psid', $psids)
            ->whereRaw("LOWER($trim($col)) = ?", [$this->settings->pageKey($page)])
            ->groupBy('botcake_psid')->selectRaw('botcake_psid, COUNT(*) AS c');
    }

    /** Query string bilang text; ang array (hal. ?q[]=x) ay itinuturing na blangko. */
    private function text(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }
}
