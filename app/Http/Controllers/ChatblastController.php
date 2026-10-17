<?php

namespace App\Http\Controllers;

use App\Models\BotcakeMessage;
use App\Models\BotcakePsid;
use App\Models\User;
use App\Services\BotcakeCustomerMatcher;
use App\Services\Chatblast;
use App\Services\ChatblastSender;
use App\Services\PageSettings;
use App\Support\NavAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * /chatblast: ang mga order update na ipinapadala sa customer sa Botcake, bilang listahan na parang sheet. Ang access
 * ay ayon sa /owner/nav-settings (NavAccess; CEO laging pasok, 404 sa iba). Walang token sa anumang sagot.
 */
class ChatblastController extends Controller
{
    public const PER_PAGE = 100;
    public const STATUSES = ['ready', 'no_psid', 'no_config', 'queued', 'sending', 'sent', 'failed', 'field_mismatch', 'skipped', 'unknown'];
    private const MAX_DAYS = 92;

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

    public function index()
    {
        $this->checkAccess();

        $sequences = [];
        foreach (array_keys(Chatblast::KINDS) as $kind) $sequences[$kind] = $this->chatblast->sequence($kind);

        return view('chatblast.index', [
            'kinds'     => array_keys(Chatblast::KINDS),
            'sequences' => $sequences,
            'placeholders' => Chatblast::PLACEHOLDERS,
            'statuses'  => self::STATUSES,
            'pages'     => BotcakeMessage::query()->distinct()->orderBy('page_name')->pluck('page_name')->all(),
            'today'     => Carbon::now('Asia/Manila')->toDateString(),
        ]);
    }

    /**
     * Ang listahan (100 kada pahina, pinakabago muna), ang bilang kada status para sa filter, at ang lagay ng pagpapadala.
     * Ilang query lang kahit ilang row: bilang (GROUP BY), mga row, J&T status ng 100 waybill, at ang lagay.
     */
    public function list(Request $request)
    {
        $this->checkAccess();

        // light=1: ang mabilis na poll (kada 4 s) ay hindi nagbibilang; ang bilang ay kinukuha kada 30 s o pagkatapos ng aksyon.
        $light  = $this->str($request, 'light', 1) === '1';
        $status = $this->str($request, 'status');
        $counts = $light ? null : $this->filtered($request, false)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')
            ->map(fn ($n) => (int) $n)->sortKeys()->all();
        $total  = $counts === null ? null : (in_array($status, self::STATUSES, true) ? ($counts[$status] ?? 0) : array_sum($counts));
        $page   = max(1, (int) $this->str($request, 'p', 6));

        $rows = $this->filtered($request, true)->orderByDesc('id')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)
            ->get(['id', 'created_at', 'page_name', 'kind', 'day', 'version', 'fb_name', 'waybill', 'jnt_status', 'psid', 'text', 'status', 'reason', 'attempts', 'last_http', 'sent_at', 'is_test']);
        $jnt = $rows->isEmpty() ? collect() : DB::table('from_jnts')->whereIn('waybill_number', $rows->pluck('waybill')->unique()->values())
            ->selectRaw('waybill_number, MAX(status) AS status')->groupBy('waybill_number')->pluck('status', 'waybill_number');

        return response()->json([
            'rows'     => $rows->map(fn ($m) => $this->row($m, $jnt[$m->waybill] ?? null))->all(),
            'total'    => $total,
            'page'     => $page,
            'per_page' => self::PER_PAGE,
            'counts'   => $counts,
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
            'name' => $this->pageCustomers($page)->where('psid', $psid)->value('fb_name') ?? '(wala sa Botcake customers ng page)',
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
            'reason' => $m->reason, 'attempts' => $m->attempts, 'last_http' => $m->last_http, 'steps' => $m->steps ?? [],
            'is_test' => $m->is_test, 'test_of' => $m->test_of, 'macro_output_id' => $m->macro_output_id,
            'created_at' => $at($m->created_at), 'queued_at' => $at($m->queued_at), 'sent_at' => $at($m->sent_at), 'updated_at' => $at($m->updated_at),
            'created_by' => $m->created_by ? ($names[$m->created_by] ?? 'user #' . $m->created_by) : 'system',
            'sent_by' => $m->sent_by ? ($names[$m->sent_by] ?? 'user #' . $m->sent_by) : null,
        ]);
    }

    /** "Kunin ang mga bago": para sa uri ng filter, o lahat ng uri kapag walang napili. */
    public function build(Request $request)
    {
        $this->checkAccess();
        $kind = $this->str($request, 'kind', 30);
        if ($kind !== '' && !isset(Chatblast::KINDS[$kind])) return response()->json(['error' => 'Hindi kilalang uri.'], 422);

        return response()->json(['result' => $this->chatblast->build($kind ?: null, Auth::id())]);
    }

    /** "Send" / "Send selected": ang mga napiling row na maaaring ipadala. */
    public function send(Request $request)
    {
        $this->checkAccess();
        $ids = $request->input('ids');
        if (!is_array($ids) || !$ids || count($ids) > 1000) return response()->json(['error' => 'Pumili ng 1 hanggang 1000 row.'], 422);

        return response()->json(['queued' => $this->sender->queue(array_filter($ids, 'is_numeric'), Auth::id()), 'state' => $this->state()]);
    }

    /** "Send lahat ng ready": ang ready sa kasalukuyang filter (anuman ang napiling status). */
    public function sendFiltered(Request $request)
    {
        $this->checkAccess();
        return response()->json(['queued' => $this->queueFiltered($request, ['ready']), 'state' => $this->state()]);
    }

    /** "Retry failed": failed at field_mismatch sa kasalukuyang filter. */
    public function retryFailed(Request $request)
    {
        $this->checkAccess();
        return response()->json(['queued' => $this->queueFiltered($request, ['failed', 'field_mismatch']), 'state' => $this->state()]);
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
        if (!BotcakeMessage::whereKey($id)->exists()) return response()->json(['error' => 'Walang ganitong row.'], 422);

        $test = $this->sender->sendTest($id, Auth::id());
        if (!$test) return response()->json(['error' => 'Walang Test PSID ang page na ito. Pumili muna sa "Test PSID".'], 422);

        return response()->json(['id' => $test->id, 'status' => $test->status]);
    }

    /** I-save ang mga araw ng uri (bawat araw: mga bersyon ng text). Binubuo ulit ang text ng hindi pa naipapadala. */
    public function saveSequence(Request $request)
    {
        $this->checkAccess();
        $kind = $this->str($request, 'kind', 30);
        try {
            $changed = $this->chatblast->setSequence($kind, $request->input('days'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['changed' => $changed, 'days' => $this->chatblast->sequence($kind)]);
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
        if (!$customer) return response()->json(['error' => 'Walang ganitong customer sa page na ito.'], 422);

        $this->settings->set($page, 'botcake', 'test_psid', (string) $customer->psid, false, Auth::id());

        return response()->json(['name' => $customer->fb_name, 'psid_last4' => mb_substr((string) $customer->psid, -4)]);
    }

    private function pageCustomers(string $page)
    {
        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        return BotcakePsid::where('source', 'api')->whereRaw("LOWER($trim(page)) = ?", [$this->settings->pageKey($page)]);
    }

    /** @param array<int, string> $statuses */
    private function queueFiltered(Request $request, array $statuses): int
    {
        $queued = 0;
        $this->filtered($request, false)->whereIn('status', $statuses)->select('id')
            ->chunkById(500, function ($rows) use (&$queued) { $queued += $this->sender->queue($rows->pluck('id')->all(), Auth::id()); });

        return $queued;
    }

    /**
     * Ang query ng kasalukuyang filter: petsa (created_at, Manila; default ngayong araw, hanggang 92 araw), page, uri,
     * status (kapag $withStatus), at hanap (simula ng pangalan, o eksaktong waybill).
     */
    private function filtered(Request $request, bool $withStatus)
    {
        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->str($request, $key, 10)) ? Carbon::parse($this->str($request, $key, 10), 'Asia/Manila') : null;
        $to   = ($date('to') ?? Carbon::now('Asia/Manila'))->endOfDay();
        $from = ($date('from') ?? $to->copy())->startOfDay();
        if ($from->gt($to)) $from = $to->copy()->startOfDay();
        if ($from->diffInDays($to) > self::MAX_DAYS) $from = $to->copy()->subDays(self::MAX_DAYS)->startOfDay();

        $q = BotcakeMessage::query()->whereBetween('created_at', [$from, $to]);

        if (($page = $this->str($request, 'page')) !== '') $q->where('page_name', $page);
        if (($kind = $this->str($request, 'kind', 30)) !== '') $q->where('kind', $kind);
        if ($withStatus && in_array($status = $this->str($request, 'status'), self::STATUSES, true)) $q->where('status', $status);
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
        ];
    }

    /** Hal. "Araw 3 · B" (o "Araw 3" kapag walang bersyon, hal. kulang sa rider info). */
    private static function step(BotcakeMessage $m): string
    {
        return 'Araw ' . $m->day . ($m->version ? ' · ' . $m->version : '');
    }

    private function row(BotcakeMessage $m, ?string $jnt): array
    {
        return [
            'id' => $m->id, 'at' => $m->created_at?->timezone('Asia/Manila')->format('m-d H:i'), 'page' => $m->page_name, 'kind' => $m->kind,
            'day' => $m->day, 'version' => $m->version, 'step' => self::step($m),
            'customer' => $m->fb_name, 'waybill' => $m->waybill, 'jnt' => $jnt ?? $m->jnt_status,
            'psid_last4' => $m->psid ? mb_substr($m->psid, -4) : null, 'first_line' => strtok((string) $m->text, "\n") ?: '',
            'text' => $m->text, 'status' => $m->status, 'reason' => $m->reason, 'attempts' => $m->attempts, 'http' => $m->last_http,
            'sent_at' => $m->sent_at?->timezone('Asia/Manila')->format('m-d H:i'), 'is_test' => $m->is_test,
        ];
    }
}
