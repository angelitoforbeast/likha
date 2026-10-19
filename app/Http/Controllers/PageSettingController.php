<?php

namespace App\Http\Controllers;

use App\Jobs\SyncBotcakeCustomersJob;
use App\Support\ChatblastNav;
use App\Models\MacroOutput;
use App\Models\PageSetting;
use App\Services\BotcakeCustomerSync;
use App\Services\BotcakeFlowNames;
use App\Services\BotcakePageSetup;
use App\Models\BotcakeMessage;
use App\Services\Chatblast;
use App\Services\ChatblastReady;
use App\Services\ChatblastSetupCheck;
use App\Jobs\RunChatblastSetupCheck;
use App\Services\ChatblastSender;
use App\Services\BotcakeSyncAll;
use App\Services\PageSettings;
use App\Services\PageSettingsGrid;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * /page-settings: settings kada page bilang rows (Page | Provider | Key | Value), ipinapakita bilang grid na parang
 * Google Sheet. CEO lang (404 sa iba). Ang secret ay hindi kailanman ibinabalik: huling 4 na character lang.
 */
class PageSettingController extends Controller
{

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
        // CEO, o ang role na naka-check sa /owner/nav-settings para sa Page Settings (404 sa iba).
        if (!ChatblastNav::allowsPageSettings($this->getNormalizedRole())) abort(404);
    }

    /** Ang grid: isang row kada page, isang column kada provider + key. Ang JS ang nag-e-edit at nagpo-poll. */
    public function index(PageSettingsGrid $grid)
    {
        $this->checkAccess();

        return view('page_settings.index', ['grid' => $grid->build(), 'isCeo' => $this->getNormalizedRole() === 'CEO']);
    }

    /**
     * Ang Setup box: ang tatlong pangalan para sa lahat ng page (page na "_all"), saka Check all. Para sa may access sa
     * page settings (gaya ng pag-edit ng cell).
     */
    public function saveSetup(Request $request)
    {
        $this->checkAccess();
        $names = [];
        foreach (ChatblastSetupCheck::NAME_KEYS as $what => $key) {
            $v = preg_replace('/\s+/u', ' ', trim((string) $request->input($what, '')));
            if ($v === '' || mb_strlen($v) > 255) return response()->json(['error' => ucfirst($what === 'field' ? 'custom field' : $what) . ' name: 1 to 255 characters.'], 422);
            $names[$key] = $v;
        }
        foreach ($names as $key => $v) $this->settings->set(ChatblastSetupCheck::ALL_PAGE, 'botcake', $key, $v, false, Auth::id(), true);

        return response()->json(['names' => app(ChatblastSetupCheck::class)->names()] + $this->queueCheckAll());
    }

    /** Check all: isang job sa queue "default" (hindi sabay ang dalawa). */
    public function checkAll()
    {
        $this->checkAccess();

        return response()->json($this->queueCheckAll());
    }

    public function setupProgress()
    {
        $this->checkAccess();

        return response()->json(['progress' => RunChatblastSetupCheck::progress()]);
    }

    /** @return array{queued: bool, progress: ?array} */
    private function queueCheckAll(): array
    {
        $p = RunChatblastSetupCheck::progress();
        // Naka-queue o tumatakbo pa (hanggang 1 oras): hindi na nagdadagdag ng isa pa.
        if ($p && in_array($p['status'] ?? '', ['queued', 'running'], true)
            && now()->diffInMinutes(\Illuminate\Support\Carbon::parse($p['started_at'] ?? $p['queued_at'] ?? now())) < 60) {
            return ['queued' => false, 'progress' => $p];
        }
        RunChatblastSetupCheck::queued(Auth::id());
        RunChatblastSetupCheck::dispatch(Auth::id());

        return ['queued' => true, 'progress' => RunChatblastSetupCheck::progress()];
    }

    /** Poll: kapag may ?since=, ang mga cell lang na nagbago mula noon; kung wala, ang buong grid. */
    public function grid(Request $request, PageSettingsGrid $grid)
    {
        $this->checkAccess();

        $since = (string) $request->query('since', '');
        if ($since === '') return response()->json($grid->build());

        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
            return response()->json(['error' => 'Bad since (YYYY-MM-DD HH:MM:SS).'], 422);
        }

        return response()->json($grid->changes(Carbon::parse($since)));
    }

    /**
     * Auto-save ng isang cell. Blangko = i-clear (deactivate), maliban sa secret: doon ang blangko ay walang binago,
     * at ang "clear" lang ang nagbubura. Last write wins.
     */
    public function saveCell(Request $request, PageSettingsGrid $grid)
    {
        $this->checkAccess();

        $page     = (string) $request->input('page_name', '');
        $provider = strtolower(trim((string) $request->input('provider', '')));
        $key      = strtolower(trim((string) $request->input('key', '')));
        $value    = trim((string) $request->input('value', ''));

        $errors = $this->settings->validate($page, $provider, $key, 'x');
        if ($errors && !isset($errors['value'])) {
            return response()->json(['error' => reset($errors)], 422);
        }

        $actives = $this->settings->pageQuery($page)->where('provider', $provider)->where('key', $key)
            ->where('active', true)->orderByDesc('id')->get();
        $current = $actives->first();
        // Secret ang column kapag secret na ito sa alinmang page, hindi lang kapag sinabi ng browser.
        $secret  = $request->boolean('is_secret')
            || PageSetting::where('provider', $provider)->where('key', $key)->where('active', true)->where('is_secret', true)->exists();

        if ($request->boolean('clear') || ($value === '' && !$secret)) {
            if (!$current) return response()->json(['status' => 'unchanged', 'cell' => null]);
            if (PageSettings::isSystemKey($provider . '.' . $key)) {
                return response()->json(['error' => $provider . '.' . $key . ' is written by the system.'], 422);
            }
            foreach ($actives as $s) $this->settings->deactivate($s, Auth::id());
            // Ang binurang template ay pinipili ulit ng system (kapag may iisang malinaw na template).
            if ($provider . '.' . $key === 'botcake.template.shipping') {
                return response()->json($this->refreshAfterSave($page, 'botcake.template.shipping', $grid) + ['status' => 'cleared', 'cell' => null]);
            }
            // Binurang flow ID: kasama ang pangalan nito, para hindi ito magamit sa pagpili ng template.
            if (BotcakeFlowNames::watches($provider . '.' . $key)) {
                $names = $this->settings->pageQuery($page)->where('provider', $provider)->where('key', $key . '.name')->where('active', true)->get();
                foreach ($names as $s) $this->settings->deactivate($s);
                return response()->json(['status' => 'cleared', 'cell' => null, 'cells' => [$provider . '.' . $key . '.name' => null]]);
            }
            return response()->json(['status' => 'cleared', 'cell' => null]);
        }

        if ($value === '') {
            return response()->json(['status' => 'unchanged', 'cell' => $current ? $grid->cell($current) : null]);
        }

        $errors = $this->settings->validate($page, $provider, $key, $value);
        if ($errors) return response()->json(['error' => reset($errors)], 422);

        $res = $this->settings->set($page, $provider, $key, $value, $secret, Auth::id());
        $out = ['status' => $res['status'], 'cell' => $grid->cell($res['setting'])];

        // Flow ID o template: ang pangalan o body mula sa Botcake, para sa page na ito lang. Hindi humaharang ang error.
        if (BotcakeFlowNames::watches($provider . '.' . $key)) {
            $out = $this->refreshAfterSave($page, $provider . '.' . $key, $grid) + $out;
        }

        // Bagong Botcake ID o token at kumpleto na ang dalawa: agad na Test (token, saka ang flow, field at template).
        // Naka-save na ang value; ang pagkabigo ng Botcake ay nasa refresh_error lang.
        if (in_array($provider . '.' . $key, ['botcake.page_id', 'botcake.token'], true) && $res['status'] !== 'unchanged') {
            // Ibang Botcake page: ang mga kandidato ng dating page ay hindi na totoo (pinupunan ulit ng check kapag ok).
            $cleared = [];
            if ($key === 'page_id') {
                foreach (['flow.shipping.options', 'field.message.options'] as $k) {
                    $old = $this->settings->pageQuery($page)->where('provider', 'botcake')->where('key', $k)->where('active', true)->get();
                    $old->each(fn ($s) => $this->settings->deactivate($s));
                    if ($old->isNotEmpty()) $cleared['botcake.' . $k] = null;
                }
            }
            $check = app(BotcakePageSetup::class)->check($page);
            $cells = array_merge($cleared, $this->cells($check['written'], $grid));
            if ($check['status'] !== 'skipped') {
                $out += ['token_test' => $check['status'], 'cells' => $cells, 'refresh_error' => $check['error']];
            } elseif ($cleared) {
                $out += ['cells' => $cells];
            }
        }

        return response()->json($out);
    }

    /**
     * Ang mga cell na isinulat ng BotcakeFlowNames (pangalan, template, body), para ma-update ang row nang walang reload.
     * Kapag pinili ng system ang template, iyon din ang cell ng template.
     */
    private function refreshAfterSave(string $page, string $full, PageSettingsGrid $grid): array
    {
        $res = app(BotcakeFlowNames::class)->afterSave($page, $full);
        $out = ['cells' => $this->cells($res['written'], $grid), 'refresh_error' => $res['error']];
        if (isset($res['written'][$full])) $out['cell'] = $grid->cell($res['written'][$full]);

        return $out;
    }

    /** @param array<string, ?PageSetting> $written */
    private function cells(array $written, PageSettingsGrid $grid): array
    {
        return array_map(fn ($s) => $s ? $grid->cell($s) : null, $written);
    }

    /** Ang dating values ng isang cell (secret = huling 4 lang). */
    public function history(Request $request, PageSettingsGrid $grid)
    {
        $this->checkAccess();

        return response()->json(['history' => $grid->history(
            (string) $request->query('page_name', ''),
            (string) $request->query('provider', ''),
            (string) $request->query('key', ''),
        )]);
    }

    /** "+ Page": ang pangalang gagamitin (trim) at kung may orders na ganitong PAGE. Walang isinusulat. */
    public function pageCheck(Request $request)
    {
        $this->checkAccess();

        $name = $this->settings->normPage((string) $request->query('page_name', ''));
        if ($name === '') return response()->json(['error' => 'The page is required.'], 422);

        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        $col  = DB::getDriverName() === 'pgsql' ? '"PAGE"' : '`PAGE`';

        return response()->json([
            'name'      => $name,
            'key'       => $this->settings->pageKey($name),
            'in_orders' => MacroOutput::whereRaw("LOWER($trim($col)) = ?", [$this->settings->pageKey($name)])->exists(),
        ]);
    }

    /** Bago o kapalit: kapag may active na ang page + provider + key, ito ang pumapalit (ang luma = history). */
    public function store(Request $request)
    {
        $this->checkAccess();

        $page     = (string) $request->input('page_name', '');
        $provider = (string) $request->input('provider', '');
        $key      = (string) $request->input('key', '');
        $value    = (string) $request->input('value', '');

        $errors = $this->settings->validate($page, $provider, $key, $value);
        if ($errors) {
            return back()->withErrors($errors)->withInput($request->except('value'));
        }

        $res = $this->settings->set($page, $provider, $key, $value, $request->boolean('is_secret'), Auth::id());

        $label = ['created' => 'Added', 'replaced' => 'Replaced', 'unchanged' => 'No change (same value)'][$res['status']];
        return redirect()->route('page-settings.index')->with('success', $label . ': ' . $res['setting']->page_name . ' · ' . $res['setting']->fullKey());
    }

    /**
     * Isang custom_fields call: ok o invalid, itinatala sa botcake.token_status. Kapag ok: ang pangalan ng mga flow at
     * ang template (isang flows read at isang templates call).
     */
    public function testToken(Request $request)
    {
        $this->checkAccess();

        $page = (string) $request->input('page_name', '');
        $res  = app(BotcakePageSetup::class)->check($page);
        if ($res['status'] === 'skipped') {
            return redirect()->route('page-settings.index')->with('error', 'The page needs botcake.page_id and botcake.token first.');
        }
        if ($res['status'] === 'error') {
            return redirect()->route('page-settings.index')->with('error', 'Could not test the token: ' . $res['error']);
        }

        $note = $res['status'] !== 'ok' ? '' : ($res['error'] === null ? ' · flow, field and template checked' : ' · flow, field and template not checked: ' . $res['error']);

        return redirect()->route('page-settings.index')->with($res['status'] === 'ok' ? 'success' : 'error', $this->settings->normPage($page) . ' · Token: ' . $res['status'] . $note);
    }

    /**
     * "Test lahat ng token": isang page kada request (isa-isang tinatawag ng browser), isang token check na walang
     * retry (hanggang 20 s). Itinatala gaya ng row button; ang error ay hindi itinatala. Kapag ok: ang pangalan ng mga
     * flow at ang template; ang buong request ay hindi lalampas nang ~BotcakePageSetup::TEST_BUDGET (25) segundo, kasama ang token check
     * at ang paghihintay ng rate limit. Ang mga cell na isinulat ay ibinabalik (cells) para ma-update ang row nang walang reload.
     */
    public function testTokenJson(Request $request, PageSettingsGrid $grid)
    {
        $this->checkAccess();

        $page = (string) $request->input('page_name', '');
        $res  = app(BotcakePageSetup::class)->check($page);
        if ($res['status'] === 'skipped') return response()->json(['status' => 'skipped', 'cell' => null]);
        if ($res['status'] === 'error') {
            return response()->json(['status' => 'error', 'error' => $res['error'], 'reason' => $res['reason'], 'cell' => null]);
        }

        $row = $res['written']['botcake.token_status'] ?? null;
        $out = ['status' => $res['status'], 'cell' => $row ? $grid->cell($row) : null, 'cells' => null];
        if ($res['status'] === 'ok') {
            $out['cells'] = $this->cells(array_diff_key($res['written'], ['botcake.token_status' => true]), $grid);
            $out['refresh_error'] = $res['error'];
        }

        return response()->json($out);
    }

    /**
     * "Send test" ng row: ang text ng pinakabagong mensahe ng page (o isang maikling sample) sa Test PSID ng page, sa
     * queue. Kapag naging sent (sulat, basa at flow: lahat 2xx), ang page ay Ready (botcake.test_ok, ChatblastReady).
     */
    public function sendTest(Request $request, ChatblastSender $sender)
    {
        $this->checkAccess();

        $page = $this->settings->normPage((string) $request->input('page_name', ''));
        foreach (Chatblast::REQUIRED as $key) {
            if (trim((string) $this->settings->get($page, 'botcake', $key)) === '') {
                return response()->json(['error' => 'Complete the setup first (Botcake ID, token, Chatblast flow and message field).'], 422);
            }
        }
        if (trim((string) $this->settings->get($page, 'botcake', 'test_psid')) === '') {
            return response()->json(['error' => 'Set a Test PSID first'], 422);
        }

        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        $latest = BotcakeMessage::where('is_test', false)->whereNotNull('text')->where('text', '<>', '')
            ->whereRaw("LOWER($trim(page_name)) = ?", [$this->settings->pageKey($page)])->orderByDesc('id')->first(['id']);
        $test = $latest ? $sender->sendTest($latest->id, Auth::id()) : $sender->sendTestText($page, ChatblastReady::SAMPLE_TEXT, Auth::id());
        // Nabura ang Test PSID pagkatapos ng check sa itaas: walang row na ginawa ang sender.
        if (!$test) return response()->json(['error' => 'Set a Test PSID first'], 422);

        return response()->json(['status' => $test->status, 'id' => $test->id]);
    }

    /**
     * "Mark as tested" (CEO lang): ang page na sinuri mismo ng may-ari ay Ready na nang walang Send test. Isinusulat ang
     * test_ok ngayon, kasama ang user id ng CEO; walang ipinapadala.
     */
    public function markTested(Request $request, PageSettingsGrid $grid)
    {
        $this->checkAccess();
        if ($this->getNormalizedRole() !== 'CEO') abort(403);

        $page = $this->settings->normPage((string) $request->input('page_name', ''));
        foreach (Chatblast::REQUIRED as $key) {
            if (trim((string) $this->settings->get($page, 'botcake', $key)) === '') {
                return response()->json(['error' => 'Complete the setup first (Botcake ID, token, Chatblast flow and message field).'], 422);
            }
        }

        $res = $this->settings->set($page, 'botcake', 'test_ok', now('Asia/Manila')->format('Y-m-d H:i:s'), false, Auth::id(), true);

        return response()->json(['status' => 'marked', 'cell' => $grid->cell($res['setting'])]);
    }

    /** Incremental sync ng isang page, sa queue. */
    public function sync(Request $request, BotcakeCustomerSync $sync)
    {
        $this->checkAccess();

        $key  = $this->settings->pageKey((string) $request->input('page_name', ''));
        $page = collect($sync->pages())->first(fn ($p) => $this->settings->pageKey($p) === $key);
        // Ang row button ay fetch (JSON, walang reload); ang form post ay redirect gaya ng dati.
        $json = $request->expectsJson();
        if (!$page) {
            $error = 'The page needs botcake.page_id and botcake.token first.';
            return $json ? response()->json(['error' => $error], 422) : redirect()->route('page-settings.index')->with('error', $error);
        }

        SyncBotcakeCustomersJob::dispatch($page);

        $message = $page . ': Botcake sync queued. The counts update by themselves.';
        return $json ? response()->json(['status' => 'queued', 'message' => $message]) : redirect()->route('page-settings.index')->with('success', $message);
    }

    /** "Sync lahat": isang run lang sabay; ang pindot habang may tumatakbo o naka-pause na run ay walang sinisimulan. */
    public function syncAll(BotcakeSyncAll $all)
    {
        $this->checkAccess();

        return response()->json($all->start(Auth::id()));
    }

    /** Pause / Resume / Stop ng Sync lahat: status lang ng run; ang job ang sumusunod bago kunin ang susunod na page. */
    public function syncAllControl(string $action, BotcakeSyncAll $all)
    {
        $this->checkAccess();

        return response()->json(['sync_all' => match ($action) {
            'pause'  => $all->pause(),
            'resume' => $all->resume(),
            'stop'   => $all->stop(),
        }]);
    }

    public function deactivate(int $id)
    {
        $this->checkAccess();

        $setting = PageSetting::findOrFail($id);
        // Ang ACTIVE ng chatbot ay sa On/Off ng /chatbot lang (CEO), hindi dito.
        if ($setting->fullKey() === 'chatbot.active') {
            return redirect()->route('page-settings.index')->withErrors(['value' => 'chatbot.active is changed with the On/Off switch on /chatbot.']);
        }
        $this->settings->deactivate($setting, Auth::id());

        return redirect()->route('page-settings.index')->with('success', 'Deactivated: ' . $setting->page_name . ' · ' . $setting->fullKey());
    }
}
