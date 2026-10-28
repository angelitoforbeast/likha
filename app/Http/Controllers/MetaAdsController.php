<?php

namespace App\Http\Controllers;

use App\Jobs\CompareMetaAds;
use App\Jobs\SyncMetaAdsReports;
use App\Models\UploadLog;
use App\Services\MetaAds\Connections;
use App\Services\MetaAds\GraphException;
use App\Services\MetaAds\Sync;
use App\Support\NavAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Facebook (Meta Ads API) sa /ads_manager/report: Connect / Disconnect ng sariling Facebook, Compare with uploads,
 * Sync now, at ang write mode (CEO lang). Pasok ang may "Ads" link (/ads_manager/report) sa /owner/nav-settings.
 */
class MetaAdsController extends Controller
{
    public const NAV_URL = '/ads_manager/report';
    /** Ang mga role na pumipili ng ad account (ang write mode ay CEO lang). */
    public const ACCOUNT_ROLES = ['CEO', 'Marketing', 'Marketing - OIC'];

    public function __construct(private Connections $connections)
    {
    }

    private function getNormalizedRole(): string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';
        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    public static function allowed(): bool
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';

        return NavAccess::allows((string) $raw, self::NAV_URL);
    }

    private function checkAccess(): void
    {
        if (!self::allowed()) abort(404);
    }

    /** Ang card sa /ads_manager/report (null = walang access): mga naka-connect, write mode, mga huling API run at event. */
    public static function panel(): ?array
    {
        if (!self::allowed()) return null;
        $connections = app(Connections::class);

        $people = [];
        $all = $connections->all();
        $names = $all ? \App\Models\User::whereIn('id', array_keys($all))->pluck('name', 'id') : collect();
        $shown = array_column($connections->accounts($all), 'name', 'id');
        foreach ($all as $userId => $c) {
            foreach ($c['ad_accounts'] ?? [] as $i => $acc) $c['ad_accounts'][$i]['name'] = $shown[(string) ($acc['id'] ?? '')] ?? ($acc['name'] ?? '');
            $people[] = $connections->strip($c) + [
                'likha_name' => $names[$userId] ?? ('user ' . $userId),
                'reconnect'  => $connections->needsReconnect($c),
                'mine'       => $userId === (int) Auth::id(),
            ];
        }

        $logs = UploadLog::whereIn('type', [Sync::LOG_TYPE, CompareMetaAds::LOG_TYPE, 'ads_manager_api_event'])->orderByDesc('id')->limit(20)
            ->get(['id', 'type', 'status', 'original_name', 'inserted', 'updated', 'skipped', 'error_message', 'user_id', 'created_at']);
        $who  = $logs->pluck('user_id')->filter()->unique();
        $whoNames = $who->isEmpty() ? collect() : \App\Models\User::whereIn('id', $who)->pluck('name', 'id');
        foreach ($logs as $l) $l->who = $whoNames[$l->user_id] ?? 'system';

        // Ang huling Compare (kahit sino ang nagpatakbo): "running…" o ang report; ang mga numero nito ay nasa pagpili ng account.
        $compare = UploadLog::where('type', CompareMetaAds::LOG_TYPE)->orderByDesc('id')->first();
        $lastNumbers = UploadLog::where('type', CompareMetaAds::LOG_TYPE)->whereIn('status', ['done', 'partial'])->orderByDesc('id')->first()?->protected_details;
        $numbers  = collect($lastNumbers['accounts'] ?? [])->keyBy('account_id');
        $selected = array_flip(Sync::accounts());
        $accounts = [];
        foreach ($connections->accounts() as $id => $a) {
            $accounts[] = $a + ['selected' => isset($selected[(string) $id]), 'compare' => $numbers[(string) $id] ?? null];
        }

        return [
            'set_up'     => $connections->appId() !== null,
            'people'     => $people,
            'mine'       => $connections->get((int) Auth::id()) !== null,
            'write_mode' => Sync::writeMode(),
            'schedule'   => Sync::schedule(),
            'is_ceo'     => (new self($connections))->getNormalizedRole() === 'CEO',
            'can_choose_accounts' => in_array((new self($connections))->getNormalizedRole(), self::ACCOUNT_ROLES, true),
            'range'      => Sync::range(null, null),
            'accounts'   => $accounts,
            'logs'       => $logs,
            'compare'    => $compare,
        ];
    }

    private function back()
    {
        return redirect()->route('ads_manager.report');
    }

    public function connect(Request $request)
    {
        $this->checkAccess();
        if ($this->connections->appId() === null) return $this->back()->withErrors(['meta' => 'Meta app not set up yet']);

        $state = Str::random(40);
        $request->session()->put('meta_ads_state', $state);

        return redirect()->away($this->connections->dialogUrl($state));
    }

    public function callback(Request $request)
    {
        $this->checkAccess();
        $expected = (string) $request->session()->pull('meta_ads_state', '');
        $state    = (string) $request->query('state', '');
        if ($expected === '' || $state === '' || !hash_equals($expected, $state)) {
            return $this->back()->withErrors(['meta' => 'Facebook connect refused: the request did not come from this page. Try Connect again.']);
        }
        if ($this->connections->appId() === null) return $this->back()->withErrors(['meta' => 'Meta app not set up yet']);

        $code = (string) $request->query('code', '');
        if ($code === '') return $this->back()->withErrors(['meta' => 'Facebook connect cancelled.']);

        try {
            $c = $this->connections->connect((int) Auth::id(), $code);
        } catch (GraphException $e) {
            Log::warning('MetaAds: connect failed', ['user' => Auth::id(), 'error' => $e->getMessage()]);
            return $this->back()->withErrors(['meta' => 'Facebook connect failed: ' . $e->getMessage()]);
        }
        Connections::event('connect', Auth::id());

        return $this->back()->with('status', '✅ Facebook connected: ' . $c['fb_user_name'] . ' (' . count($c['ad_accounts']) . ' ad account(s))');
    }

    public function disconnect()
    {
        $this->checkAccess();
        $this->connections->disconnect((int) Auth::id());
        Connections::event('disconnect', Auth::id());

        return $this->back()->with('status', 'Facebook disconnected.');
    }

    public function compare(Request $request)
    {
        $this->checkAccess();
        [$from, $to] = $this->range($request);

        // Sa queue: ang report ay nasa upload_logs row; ang page ay "running…" hanggang matapos.
        $log = CompareMetaAds::startLog($from, $to, Auth::id());
        CompareMetaAds::dispatch($log->id, $from, $to);

        return $this->back()->with('status', '🔎 Compare queued: #' . $log->id . " ({$from} to {$to})");
    }

    public function sync(Request $request)
    {
        $this->checkAccess();
        if (!Sync::writeMode()) return $this->back()->withErrors(['meta' => 'Write mode is off: Sync now is not allowed. Use Compare with uploads.']);
        if (!Sync::accounts()) return $this->back()->withErrors(['meta' => Sync::NO_ACCOUNTS]);
        [$from, $to] = $this->range($request);

        $log = Sync::startLog($from, $to, Auth::id());
        SyncMetaAdsReports::dispatch($log->id, $from, $to);

        return $this->back()->with('status', '📥 Sync queued: #' . $log->id . " ({$from} to {$to})");
    }

    public function writeMode(Request $request)
    {
        $this->checkAccess();
        if ($this->getNormalizedRole() !== 'CEO') abort(403);
        $data = $request->validate([
            'write_mode' => 'required|in:on,off',
            'schedule'   => ['nullable', 'string', 'max:200', 'regex:/^\s*([01]\d|2[0-3]):[0-5]\d(\s*,\s*([01]\d|2[0-3]):[0-5]\d)*\s*$/'],
        ]);

        Sync::setWriteMode($data['write_mode'] === 'on');
        if (!empty($data['schedule'])) Sync::setSchedule($data['schedule']);
        Connections::event('write mode ' . $data['write_mode'] . ' (' . implode(', ', Sync::schedule()) . ')', Auth::id());

        return $this->back()->with('status', 'Meta Ads write mode: ' . $data['write_mode']);
    }

    /** Ang mga ad account na binabasa ng Sync at Compare (ACCOUNT_ROLES). Ang ID na wala sa connection ay tinatanggihan. */
    public function accounts(Request $request, Sync $sync)
    {
        $this->checkAccess();
        if (!in_array($this->getNormalizedRole(), self::ACCOUNT_ROLES, true)) abort(403);
        $data = $request->validate(['accounts' => 'nullable|array|max:500', 'accounts.*' => 'string|max:50']);

        $unknown = $sync->chooseAccounts($data['accounts'] ?? [], Auth::id());
        if ($unknown) return $this->back()->withErrors(['meta' => 'Not in any connection: ' . implode(', ', $unknown)]);

        return $this->back()->with('status', 'Meta Ads ad accounts saved: ' . count(Sync::accounts()) . ' selected');
    }

    /** from / to (Y-m-d, Manila); default ang huling 7 araw kasama ngayon. Hanggang 31 araw. */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to'   => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);

        return Sync::range($data['from'] ?? null, $data['to'] ?? null);
    }
}
