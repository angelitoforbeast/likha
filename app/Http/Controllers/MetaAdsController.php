<?php

namespace App\Http\Controllers;

use App\Jobs\CompareMetaAds;
use App\Jobs\SyncMetaAdsReports;
use App\Models\UploadLog;
use App\Services\MetaAds\AppConfig;
use App\Services\MetaAds\Connections;
use App\Services\MetaAds\Graph;
use App\Services\MetaAds\GraphException;
use App\Services\MetaAds\Sync;
use App\Support\NavAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
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

        $isCeo = (new self($connections))->getNormalizedRole() === 'CEO';

        return [
            'set_up'     => $connections->appId() !== null,
            'people'     => $people,
            'mine'       => $connections->get((int) Auth::id()) !== null,
            'write_mode' => Sync::writeMode(),
            'schedule'   => Sync::schedule(),
            'is_ceo'     => $isCeo,
            // Ang Meta app ng site (spec 281), CEO lang. Walang secret dito: Set / Not set lang.
            'app'        => $isCeo ? [
                'app_id'       => AppConfig::appId(),
                'config_id'    => AppConfig::configId(),
                'secret_set'   => AppConfig::appSecret() !== null,
                'sources'      => ['app_id' => AppConfig::source('app_id'), 'app_secret' => AppConfig::source('app_secret'), 'config_id' => AppConfig::source('config_id')],
                'redirect_uri' => $connections->redirectUri(),
                'app_domain'   => $connections->appDomain(),
                'connections'  => count($all),
                // Spec 282: para sa public na /privacy, at ang dalawang URL na ilalagay sa Meta app.
                'company_name'  => AppConfig::companyName(),
                'contact_email' => AppConfig::contactEmail(),
                'privacy_url'   => rtrim((string) config('app.url'), '/') . '/privacy',
                'deletion_url'  => rtrim((string) config('app.url'), '/') . '/meta/data-deletion',
            ] : null,
            'can_choose_accounts' => in_array((new self($connections))->getNormalizedRole(), self::ACCOUNT_ROLES, true),
            'range'      => Sync::range(null, null),
            'accounts'   => $accounts,
            'logs'       => $logs,
            'refused'    => \App\Services\MetaAds\DataHealth::refusals($logs->where('type', Sync::LOG_TYPE)->pluck('id')->all()),
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
        // The flag keeps the collapsed "Ad accounts to sync" section open on the page, so the refused choice is in view.
        if ($unknown) return $this->back()->withErrors(['meta' => 'Not in any connection: ' . implode(', ', $unknown)])->with('meta_accounts_error', true);

        return $this->back()->with('status', 'Meta Ads ad accounts saved: ' . count(Sync::accounts()) . ' selected');
    }

    /**
     * Ang Meta app ng site (spec 281), CEO lang (gaya ng write mode). Ang nagbago lang ang isinusulat; ang walang laman na
     * App Secret ay nagtatabi ng dati. Ang secret ay hindi ibinabalik sa session (walang old input nito, dontFlash).
     */
    public function saveApp(Request $request)
    {
        $this->checkAccess();
        if ($this->getNormalizedRole() !== 'CEO') abort(403);

        $v = Validator::make($request->only(['app_id', 'app_secret', 'config_id', 'company_name', 'contact_email']), [
            'app_id'        => ['required', 'string', 'max:32', 'regex:/^\d+$/'],
            'app_secret'    => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{32}$/'],
            'config_id'     => ['nullable', 'string', 'max:32', 'regex:/^\d+$/'],
            'company_name'  => ['nullable', 'string', 'max:150'],
            'contact_email' => ['nullable', 'string', 'max:150', 'email'],
        ], [
            'app_id.required'  => 'App ID is required',
            'app_id.*'         => 'App ID: digits only',
            'app_secret.*'     => 'App Secret: 32 hex characters (0-9, a-f)',
            'config_id.*'      => 'Configuration ID: digits only',
            'company_name.*'   => 'Company name: up to 150 characters',
            'contact_email.*'  => 'Contact email: enter a valid email address',
        ]);
        $v->after(function ($v) use ($request) {
            if ((string) $request->input('app_secret') === '' && AppConfig::appSecret() === null) {
                $v->errors()->add('app_secret', 'App Secret is required (none is saved yet)');
            }
        });
        if ($v->fails()) return $this->back()->withErrors($v)->withInput($request->only(['app_id', 'config_id', 'company_name', 'contact_email']));
        $data = $v->validated();

        $oldId      = AppConfig::appId();
        $oldConfig  = AppConfig::configId();
        $oldCompany = AppConfig::companyName();
        $oldEmail   = AppConfig::contactEmail();
        $changed    = AppConfig::save($data['app_id'], $data['app_secret'] ?? null, $data['config_id'] ?? null);
        // Spec 282: kapag nasa form ang dalawang field (laging nasa block), ang laman nila ang naka-save; blangko = wala.
        if ($request->has('company_name') || $request->has('contact_email')) {
            $changed = array_merge($changed, AppConfig::savePrivacy(
                $request->has('company_name') ? ($data['company_name'] ?? '') : $oldCompany,
                $request->has('contact_email') ? ($data['contact_email'] ?? '') : $oldEmail,
            ));
        }

        // Isang audit line kada save: sino, kailan, aling field. Ang App ID, Configuration ID, company at email ay hindi lihim; ang secret ay "changed" lang.
        $values = [];
        if (in_array('app_id', $changed, true)) $values['app_id'] = [(string) $oldId, $data['app_id']];
        if (in_array('config_id', $changed, true)) $values['config_id'] = [(string) $oldConfig, (string) ($data['config_id'] ?? '')];
        if (in_array('company_name', $changed, true)) $values['company_name'] = [(string) $oldCompany, (string) AppConfig::companyName()];
        if (in_array('contact_email', $changed, true)) $values['contact_email'] = [(string) $oldEmail, (string) AppConfig::contactEmail()];
        Connections::event('meta app saved: ' . ($changed ? implode(', ', $changed) : 'no change'), Auth::id(), ['changed' => $changed, 'values' => $values]);

        $res = $this->back()->with('status', $changed ? 'Meta app saved: ' . implode(', ', $changed) : 'Meta app: nothing changed');
        // Ang token ay pag-aari ng app: ang mga naka-connect sa lumang App ID ay kailangang mag-Connect ulit. Hindi binubura.
        $count = count($this->connections->all());
        if (in_array('app_id', $changed, true) && $oldId !== null && $count > 0) {
            $res->with('meta_app_warning', "App ID changed: the {$count} existing Facebook connection(s) were made with the old app and must reconnect (Connect Facebook again). They were not removed.");
        }

        return $res;
    }

    /** Sinusubok ang naka-save na App ID + App Secret: humihingi ng app token sa Graph (client_credentials), hindi ito itinatabi. */
    public function testApp(Graph $graph)
    {
        $this->checkAccess();
        if ($this->getNormalizedRole() !== 'CEO') abort(403);

        $id     = AppConfig::appId();
        $secret = AppConfig::appSecret();
        if ($id === null || $secret === null) return $this->back()->withErrors(['meta_app' => 'Meta app test: save the App ID and App Secret first.']);

        try {
            $graph->get('oauth/access_token', ['client_id' => $id, 'client_secret' => $secret, 'grant_type' => 'client_credentials'], null, false, 1, 20);
        } catch (GraphException $e) {
            // Graph::clean ang naglinis; ang secret mismo ay tinatanggal din kahit walang "client_secret=" sa harap.
            return $this->back()->withErrors(['meta_app' => 'Meta app test failed: ' . str_replace($secret, '[hidden]', $e->getMessage())]);
        }

        return $this->back()->with('status', 'Meta app test: OK (Facebook accepted App ID ' . $id . ' and its secret)');
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
