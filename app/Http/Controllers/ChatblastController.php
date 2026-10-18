<?php

namespace App\Http\Controllers;

use App\Jobs\ChatblastBuildJob;
use App\Models\BotcakeMessage;
use App\Models\BotcakePsid;
use App\Models\ChatblastCampaign;
use App\Models\User;
use App\Services\BotcakeCustomerMatcher;
use App\Services\Chatblast;
use App\Services\ChatblastSender;
use App\Services\PageSettings;
use App\Support\ChatblastReason;
use App\Support\NavAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * /chatblast: ang mga order update na ipinapadala sa customer sa Botcake, bilang listahan na parang sheet. Ang access
 * ay ayon sa /owner/nav-settings (NavAccess; CEO laging pasok, 404 sa iba). Walang token sa anumang sagot.
 */
class ChatblastController extends Controller
{
    public const PER_PAGE = 100;
    public const STATUSES = ['ready', 'no_psid', 'no_config', 'queued', 'sending', 'sent', 'failed', 'field_mismatch', 'skipped', 'unknown'];
    // "To send": ang ipapadala pa, anuman ang petsa. "History": ang naproseso na, ayon sa From/To (pagtingin lang).
    public const TO_SEND = ['ready', 'queued', 'sending'];
    public const HISTORY = ['sent', 'failed', 'field_mismatch', 'skipped', 'no_psid', 'no_config', 'unknown'];
    // Ang mga chip ng History. Ang default ay "sent" (kasama ang unknown: "Sent (unconfirmed)"): ang talagang naipadala.
    public const HISTORY_CHIPS = [
        'sent' => ['sent', 'unknown'], 'failed' => ['failed', 'field_mismatch'], 'skipped' => ['skipped'],
        'no_psid' => ['no_psid'], 'no_config' => ['no_config'],
    ];

    /** Ang mga status ng napiling chip ng History (default: sent). */
    private function historyChip(Request $request): array
    {
        return self::HISTORY_CHIPS[$this->str($request, 'status', 30)] ?? self::HISTORY_CHIPS['sent'];
    }
    private const MAX_DAYS = 92;

    /** page => body ng shipping template, kinukuha minsan kada page kada request. */
    private array $templates = [];

    public function __construct(private Chatblast $chatblast, private ChatblastSender $sender, private PageSettings $settings)
    {
    }

    private function getNormalizedRole(): string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';
        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    private function checkAccess(): void
    {
        // CEO, o ang role na naka-check sa /owner/nav-settings para sa Chatblast (404 sa iba).
        if (!NavAccess::allows($this->getNormalizedRole(), '/chatblast')) abort(404);
    }

    /** Ang query param bilang string ('' kapag wala o hindi string, hal. ?q[]=x). */
    private function str(Request $request, string $key, int $max = 255): string
    {
        $v = $request->input($key);
        return is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
    }

    /** "To send" (/chatblast). */
    public function index()
    {
        return $this->page('send');
    }

    /** "History" (/chatblast/history). */
    public function history()
    {
        return $this->page('history');
    }

    private function page(string $view)
    {
        $this->checkAccess();

        return view('chatblast.index', [
            'view'      => $view,
            'campaigns' => ChatblastCampaign::orderBy('id')->get(['kind', 'name', 'active']),
            'statuses'  => self::STATUSES,
            'pages'     => BotcakeMessage::query()->distinct()->orderBy('page_name')->pluck('page_name')->all(),
            'today'     => Carbon::now('Asia/Manila')->toDateString(),
        ]);
    }

    /**
     * Ang listahan ng "To send" (view=send, ang default) o ng "History" (view=history): 100 kada pahina, pinakabago muna,
     * ang bilang kada status (at sa To send, kada sequence at kada page ng ready, at ilan ang ipapadala ng "Send all
     * ready"), at ang lagay ng pagpapadala. Ilang query lang kahit ilang row: bilang (isang GROUP BY), mga row, J&T status
     * ng 100 waybill, at ang lagay.
     */
    public function list(Request $request)
    {
        $this->checkAccess();

        // light=1: ang mabilis na poll (kada 4 s) ay hindi nagbibilang; ang bilang ay kinukuha kada 30 s o pagkatapos ng aksyon.
        $light  = $this->str($request, 'light', 1) === '1';
        $status = $this->str($request, 'status');
        $send   = $this->str($request, 'view', 10) !== 'history';
        $grouped = $light ? null : $this->filtered($request, false)
            ->selectRaw('status, kind, page_name, COUNT(*) AS n')->groupBy('status', 'kind', 'page_name')->get();
        $sum = fn ($rows, string $key) => $rows->groupBy($key)->map(fn ($g) => (int) $g->sum('n'))->sortKeys()->all();
        $counts = $grouped === null ? null : $sum($grouped, 'status');
        $total  = $counts === null ? null : (!$send ? array_sum(array_intersect_key($counts, array_flip($this->historyChip($request))))
            : (in_array($status, self::STATUSES, true) ? ($counts[$status] ?? 0) : array_sum($counts)));
        $readyRows = $grouped?->where('status', 'ready');
        $page   = max(1, (int) $this->str($request, 'p', 6));

        $rows = $this->ordered($this->filtered($request, true), $send ? null : $this->historyChip($request))->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)
            ->get(['id', 'created_at', 'page_name', 'kind', 'day', 'version', 'fb_name', 'waybill', 'jnt_status', 'psid', 'text', 'status', 'reason', 'attempts', 'last_http', 'sent_at', 'is_test']);
        $jnt = $rows->isEmpty() ? collect() : DB::table('from_jnts')->whereIn('waybill_number', $rows->pluck('waybill')->unique()->values())
            ->selectRaw('waybill_number, MAX(status) AS status')->groupBy('waybill_number')->pluck('status', 'waybill_number');

        return response()->json([
            'rows'     => $rows->map(fn ($m) => $this->row($m, $jnt[$m->waybill] ?? null))->all(),
            'total'    => $total,
            'page'     => $page,
            'per_page' => self::PER_PAGE,
            'counts'   => $counts,
            'by_kind'  => $send && $readyRows ? $sum($readyRows, 'kind') : null,
            'by_page'  => $send && $readyRows ? $sum($readyRows, 'page_name') : null,
            // Ang ipapadala ng "Send all ready": page at sequence lang ang nagpapakitid (hindi ang hanap).
            'sendable' => $send && !$light ? $this->sendable($request)->count() : null,
            'state'    => $this->state(),
            'test_psid' => $this->testPsidOf($this->str($request, 'page')),
        ]);
    }

    /**
     * Kanino ang Test PSID ng page na naka-filter (buong pangalan sa Botcake at huling 4 ng PSID), para makita bago mag
     * "Send test" na hindi kapangalan ang tatanggap. Null kapag walang page na napili o walang Test PSID.
     */
    private function testPsidOf(string $page): ?array
    {
        if ($page === '') return null;
        $psid = trim((string) $this->settings->get($page, 'botcake', 'test_psid'));
        if ($psid === '') return null;

        return [
            'name' => $this->pageCustomers($page)->where('psid', $psid)->value('fb_name') ?? '(not among the page\'s Botcake customers)',
            'psid_last4' => mb_substr($psid, -4),
        ];
    }

    /** Ang side panel: lahat ng kailangan sa debug (buong text, bawat hakbang, mga oras, dahilan). Walang token. */
    public function show(int $id)
    {
        $this->checkAccess();

        $m = BotcakeMessage::findOrFail($id);
        $names = User::whereIn('id', array_filter([$m->created_by, $m->sent_by]))->pluck('name', 'id');
        $at = fn ($t) => $t?->timezone('Asia/Manila')->format('Y-m-d H:i:s');

        return response()->json([
            'id' => $m->id, 'page' => $m->page_name, 'kind' => $m->kind, 'day' => $m->day, 'version' => $m->version,
            'step' => self::step($m), 'waybill' => $m->waybill, 'customer' => $m->fb_name,
            'psid' => $m->psid, 'text' => $m->text, 'vars' => $m->vars, 'jnt_status' => $m->jnt_status, 'status' => $m->status,
            'reason' => ChatblastReason::display($m->reason), 'attempts' => $m->attempts, 'last_http' => $m->last_http, 'steps' => $m->steps ?? [],
            'is_test' => $m->is_test, 'test_of' => $m->test_of, 'macro_output_id' => $m->macro_output_id,
            'created_at' => $at($m->created_at), 'queued_at' => $at($m->queued_at), 'sent_at' => $at($m->sent_at), 'updated_at' => $at($m->updated_at),
            'created_by' => $m->created_by ? ($names[$m->created_by] ?? 'user #' . $m->created_by) : 'system',
            'sent_by' => $m->sent_by ? ($names[$m->sent_by] ?? 'user #' . $m->sent_by) : null,
            // jnt_status: ang J&T noong binuo ang row; jnt_now: ang kasalukuyang from_jnts (hal. Delivered na).
            'jnt_now' => $this->chatblast->currentStatus($m->waybill),
            'customer_saw' => $this->customerSaw($m->page_name, (string) $m->text),
            'botcake_url' => $this->botcakeUrl($m->page_name, $m->psid),
        ]);
    }

    /**
     * Ang nakita ng customer sa Messenger: ang body ng shipping template ng page (botcake.template.shipping.text, mula sa
     * Test token o sa pag-save ng flow) na ang {{1}} ay ang text ng row. Nakatala lang, walang tawag sa Botcake. Ang
     * marker (nagsisimula sa "!": hindi nasuri, hindi approved) o walang body: ang text lang, may paalala.
     * @return array{text: string, note: ?string}
     */
    private function customerSaw(string $page, string $text): array
    {
        $body = $this->templates[$page] ??= (string) $this->settings->get($page, 'botcake', 'template.shipping.text');
        if (trim($body) === '' || str_starts_with($body, '!') || !str_contains($body, '{{1}}')) {
            return ['text' => $text, 'note' => 'Template text unknown: run Test token on the page'];
        }

        return ['text' => str_replace('{{1}}', $text, $body), 'note' => null];
    }

    /** Ang "Open in Botcake" (services.botcake.inbox_url): kapag may PSID ang row at may botcake.page_id ang page. */
    private function botcakeUrl(string $page, ?string $psid): ?string
    {
        $pageId = trim((string) $this->settings->get($page, 'botcake', 'page_id'));
        if (trim((string) $psid) === '' || $pageId === '') return null;

        return strtr((string) config('services.botcake.inbox_url'), ['{page_id}' => rawurlencode($pageId), '{psid}' => rawurlencode((string) $psid)]);
    }

    /** "Kunin ang mga bago": para sa campaign ng filter, o lahat ng naka-on na campaign kapag walang napili. */
    public function build(Request $request)
    {
        $this->checkAccess();
        $kind = $this->str($request, 'kind', 30);
        if ($kind !== '' && !($c = $this->chatblast->campaign($kind))) return response()->json(['error' => 'Unknown sequence.'], 422);
        if ($kind !== '' && !$c->active) return response()->json(['error' => 'This sequence is off; turn it on in Sequences first.'], 422);

        // Sa queue (ChatblastBuildJob): ang pagbuo sa lahat ng page ay lumalampas sa oras ng web request. Ang lagay at
        // ang resulta ay nasa state.build ng list. May tumatakbo nang build (button o chatblast:auto): 409.
        $busy = response()->json(['error' => 'A fetch is already running; try again after it.'], 409);
        $probe = Cache::lock(Chatblast::BUILD_LOCK, 5);
        if (!$probe->get()) return $busy;
        $probe->release();
        $run = ChatblastBuildJob::start($kind ?: null, Auth::id());

        return $run === null ? $busy : response()->json(['run' => $run]);
    }

    /** "Send" / "Send selected": ang mga napiling row na maaaring ipadala. */
    public function send(Request $request)
    {
        $this->checkAccess();
        $ids = $request->input('ids');
        if (!is_array($ids) || !$ids || count($ids) > 1000) return response()->json(['error' => 'Select 1 to 1000 rows.'], 422);

        return response()->json(['queued' => $this->sender->queue(array_filter($ids, 'is_numeric'), Auth::id()), 'state' => $this->state()]);
    }

    /** "Send all ready" ng To send: ang ready ng napiling page at sequence, anuman ang petsa (sendable()). */
    public function sendFiltered(Request $request)
    {
        $this->checkAccess();
        return response()->json(['queued' => $this->queueAll($this->sendable($request)), 'state' => $this->state()]);
    }

    /** "Retry failed" ng History: failed at field_mismatch sa filter ng History (From/To, page, sequence, hanap). */
    public function retryFailed(Request $request)
    {
        $this->checkAccess();
        return response()->json(['queued' => $this->queueAll($this->filtered($request, false, 'history')->whereIn('status', ['failed', 'field_mismatch'])), 'state' => $this->state()]);
    }

    public function pause()
    {
        $this->checkAccess();
        $this->sender->pause();

        return response()->json(['state' => $this->state()]);
    }

    public function resume()
    {
        $this->checkAccess();
        $this->sender->resume();

        return response()->json(['state' => $this->state()]);
    }

    /** "Send test": ang text ng row sa test PSID ng page. */
    public function test(Request $request)
    {
        $this->checkAccess();
        $id = (int) $this->str($request, 'id', 20);
        if (!BotcakeMessage::whereKey($id)->exists()) return response()->json(['error' => 'No such row.'], 422);

        $test = $this->sender->sendTest($id, Auth::id());
        if (!$test) return response()->json(['error' => 'This page has no Test PSID. Pick one under "Test PSID" first.'], 422);

        return response()->json(['id' => $test->id, 'status' => $test->status]);
    }

    /** Hanapin ang sariling account sa mga Botcake customer ng page (pangalan; huling 4 lang ng PSID ang ibinabalik). */
    public function searchTestPsid(Request $request)
    {
        $this->checkAccess();
        $page = $this->str($request, 'page');
        $q    = BotcakeCustomerMatcher::normName($this->str($request, 'q', 100));
        if ($page === '' || mb_strlen($q) < 2) return response()->json(['customers' => []]);

        $rows = $this->pageCustomers($page)->where('name_key', 'like', addcslashes($q, '%_\\') . '%')->orderBy('fb_name')->limit(20)->get(['id', 'fb_name', 'psid']);

        return response()->json([
            'customers' => $rows->map(fn ($r) => ['id' => $r->id, 'name' => $r->fb_name, 'psid_last4' => mb_substr((string) $r->psid, -4)])->all(),
            'current'   => $this->settings->get($page, 'botcake', 'test_psid') ? mb_substr((string) $this->settings->get($page, 'botcake', 'test_psid'), -4) : null,
        ]);
    }

    /** I-save ang napiling customer bilang botcake.test_psid ng page (hindi secret). */
    public function saveTestPsid(Request $request)
    {
        $this->checkAccess();
        $page = $this->str($request, 'page');
        $customer = $page === '' ? null : $this->pageCustomers($page)->whereKey((int) $this->str($request, 'customer_id', 20))->first();
        if (!$customer) return response()->json(['error' => 'No such customer on this page.'], 422);

        $this->settings->set($page, 'botcake', 'test_psid', (string) $customer->psid, false, Auth::id());

        return response()->json(['name' => $customer->fb_name, 'psid_last4' => mb_substr((string) $customer->psid, -4)]);
    }

    private function pageCustomers(string $page)
    {
        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        return BotcakePsid::where('source', 'api')->whereRaw("LOWER($trim(page)) = ?", [$this->settings->pageKey($page)]);
    }

    private function queueAll($query): int
    {
        $queued = 0;
        $query->select('id')->chunkById(500, function ($rows) use (&$queued) { $queued += $this->sender->queue($rows->pluck('id')->all(), Auth::id()); });

        return $queued;
    }

    /**
     * Ang "Send all ready" ng To send: lahat ng ready (hindi test) ng mga naka-on na sequence (ang naka-off ay tinatanggihan
     * din ng sender), pinakikitid lang ng page at sequence; walang petsa.
     */
    private function sendable(Request $request)
    {
        $q = BotcakeMessage::where('status', 'ready')->where('is_test', false)->whereNotIn('kind', ChatblastCampaign::where('active', false)->pluck('kind'));
        if (($page = $this->str($request, 'page')) !== '') $q->where('page_name', $page);
        if (($kind = $this->str($request, 'kind', 30)) !== '') $q->where('kind', $kind);

        return $q;
    }

    /** [from, to] ng History (Manila; default ngayong araw, hanggang 92 araw). */
    private function range(Request $request): array
    {
        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->str($request, $key, 10)) ? Carbon::parse($this->str($request, $key, 10), 'Asia/Manila') : null;
        $to   = ($date('to') ?? Carbon::now('Asia/Manila'))->endOfDay();
        $from = ($date('from') ?? $to->copy())->startOfDay();
        if ($from->gt($to)) $from = $to->copy()->startOfDay();
        if ($from->diffInDays($to) > self::MAX_DAYS) $from = $to->copy()->subDays(self::MAX_DAYS)->startOfDay();

        return [$from, $to];
    }

    /**
     * Ang ayos ng listahan. To send: ang pinakabagong nabuo muna (id). History: ang pinakabagong naproseso muna: sa Sent,
     * sent_at pababa (ang walang sent_at, hal. ilang unconfirmed, sa huli); sa ibang chip, updated_at pababa. Ang id ang
     * tie-breaker. Sa MySQL at SQLite, NULL ang pinakamaliit kaya nasa huli na sa DESC; sa pgsql, kailangan ang NULLS LAST.
     */
    private function ordered($q, ?array $chip)
    {
        if ($chip === null) return $q->orderByDesc('id');
        if ($chip === self::HISTORY_CHIPS['sent']) {
            DB::getDriverName() === 'pgsql' ? $q->orderByRaw('sent_at DESC NULLS LAST') : $q->orderByDesc('sent_at');
        } else {
            $q->orderByDesc('updated_at');
        }

        return $q->orderByDesc('id');
    }

    /**
     * Ang query ng listahan, para sa pagtingin: sa To send (default), ang ready, queued at sending na hindi test, anuman
     * ang petsa; sa History (view=history), ang naproseso na ayon sa petsa ng pagbuo (From/To). Pareho: page, sequence,
     * status (kapag $withStatus, sa loob ng view) at hanap (simula ng pangalan, o eksaktong waybill).
     */
    private function filtered(Request $request, bool $withStatus, ?string $view = null)
    {
        $view ??= $this->str($request, 'view', 10) === 'history' ? 'history' : 'send';
        if ($view === 'send') {
            $q = BotcakeMessage::whereIn('status', self::TO_SEND)->where('is_test', false);
        } else {
            // Ang petsa ay kung kailan naproseso (naipadala: sent_at; ang iba: ang huling pagbabago), hindi ang pagbuo:
            // ang lumang ready na ipinadala o pumalya ngayon ay nasa History ngayong araw.
            [$from, $to] = $this->range($request);
            $q = BotcakeMessage::whereIn('status', self::HISTORY)->where(fn ($w) => $w
                ->where(fn ($s) => $s->where('status', 'sent')->whereBetween('sent_at', [$from, $to]))
                ->orWhere(fn ($o) => $o->where('status', '<>', 'sent')->whereBetween('updated_at', [$from, $to])));
        }

        if (($page = $this->str($request, 'page')) !== '') $q->where('page_name', $page);
        if (($kind = $this->str($request, 'kind', 30)) !== '') $q->where('kind', $kind);
        if ($withStatus && $view === 'history') $q->whereIn('status', $this->historyChip($request));
        elseif ($withStatus && in_array($status = $this->str($request, 'status'), self::STATUSES, true)) $q->where('status', $status);
        if (($s = $this->str($request, 'q', 100)) !== '') {
            $q->where(fn ($w) => $w->where('waybill', $s)->orWhere('fb_name', 'like', addcslashes($s, '%_\\') . '%'));
        }

        return $q;
    }

    /** Ang lagay ng pagpapadala: naka-pause, ilan ang nasa queue (lahat), at ilan ang naipadala sa huling oras. */
    private function state(): array
    {
        return [
            'paused' => $this->sender->paused(),
            'queued' => BotcakeMessage::whereIn('status', ['queued', 'sending'])->count(),
            'sent'   => BotcakeMessage::where('status', 'sent')->where('sent_at', '>=', now()->subHour())->count(),
            'build'  => ChatblastBuildJob::run(), // ang huling "Refresh now" (queued, running, done, failed, busy, stopped)
            'refresh' => $this->chatblast->refreshState(), // "Updated HH:MM · next refresh HH:MM"
        ];
    }

    /** Hal. "Araw 3 · B" (o "Araw 3" kapag walang bersyon, hal. kulang sa rider info). */
    private static function step(BotcakeMessage $m): string
    {
        return 'Day ' . $m->day . ($m->version ? ' · ' . $m->version : '');
    }

    private function row(BotcakeMessage $m, ?string $jnt): array
    {
        return [
            'id' => $m->id, 'at' => $m->created_at?->timezone('Asia/Manila')->format('m-d H:i'), 'page' => $m->page_name, 'kind' => $m->kind,
            'day' => $m->day, 'version' => $m->version, 'step' => self::step($m),
            'customer' => $m->fb_name, 'waybill' => $m->waybill, 'jnt' => $jnt ?? $m->jnt_status,
            // Ang naipadala: ang unang linya ng nakita ng customer (template + text); ang iba: ang text ng row.
            'psid_last4' => $m->psid ? mb_substr($m->psid, -4) : null,
            'first_line' => strtok($m->status === 'sent' ? $this->customerSaw($m->page_name, (string) $m->text)['text'] : (string) $m->text, "\n") ?: '',
            'text' => $m->text, 'status' => $m->status, 'reason' => ChatblastReason::display($m->reason), 'attempts' => $m->attempts, 'http' => $m->last_http,
            'sent_at' => $m->sent_at?->timezone('Asia/Manila')->format('m-d H:i'), 'is_test' => $m->is_test,
        ];
    }
}
