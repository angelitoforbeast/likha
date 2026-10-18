<?php

namespace App\Http\Controllers;

use App\Jobs\SyncBotcakeCustomersJob;
use App\Support\BotcakeClock;
use App\Support\NavAccess;
use App\Models\MacroOutput;
use App\Models\PageSetting;
use App\Services\BotcakeClient;
use App\Services\BotcakeCustomerSync;
use App\Services\BotcakeException;
use App\Services\BotcakeFlowNames;
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
    /** Hanggang ilang segundo mula sa simula ng Test token ang mga tawag sa Botcake (token, flows, templates). */
    private const TEST_BUDGET = 30;

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
        if (!NavAccess::allows($this->getNormalizedRole(), '/page-settings')) abort(404);
    }

    /** Ang grid: isang row kada page, isang column kada provider + key. Ang JS ang nag-e-edit at nagpo-poll. */
    public function index(PageSettingsGrid $grid)
    {
        $this->checkAccess();

        return view('page_settings.index', ['grid' => $grid->build()]);
    }

    /** Poll: kapag may ?since=, ang mga cell lang na nagbago mula noon; kung wala, ang buong grid. */
    public function grid(Request $request, PageSettingsGrid $grid)
    {
        $this->checkAccess();

        $since = (string) $request->query('since', '');
        if ($since === '') return response()->json($grid->build());

        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
            return response()->json(['error' => 'Mali ang since (YYYY-MM-DD HH:MM:SS).'], 422);
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
                return response()->json(['error' => 'Ang ' . $provider . '.' . $key . ' ay isinusulat ng system.'], 422);
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
        if ($name === '') return response()->json(['error' => 'Kailangan ang page.'], 422);

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

        $label = ['created' => 'Naidagdag', 'replaced' => 'Napalitan', 'unchanged' => 'Walang binago (parehong value)'][$res['status']];
        return redirect()->route('page-settings.index')->with('success', $label . ': ' . $res['setting']->page_name . ' · ' . $res['setting']->fullKey());
    }

    /**
     * Isang custom_fields call: ok o invalid, itinatala sa botcake.token_status. Kapag ok: ang pangalan ng mga flow at
     * ang template (isang flows read at isang templates call).
     */
    public function testToken(Request $request, BotcakeClient $client, BotcakeCustomerSync $sync, BotcakeClock $clock)
    {
        $this->checkAccess();
        $start = $clock->now();

        $page   = (string) $request->input('page_name', '');
        $pageId = $this->settings->get($page, 'botcake', 'page_id');
        $token  = $this->settings->get($page, 'botcake', 'token');
        if (!$pageId || !$token) {
            return redirect()->route('page-settings.index')->with('error', 'Kailangan muna ang botcake.page_id at botcake.token ng page.');
        }

        try {
            $status = $client->testToken($pageId, $token) ? 'ok' : 'invalid';
        } catch (BotcakeException $e) {
            return redirect()->route('page-settings.index')->with('error', 'Hindi nasubukan ang token: ' . $e->getMessage());
        }

        $sync->tokenStatus($page, $status, true);

        $note = '';
        if ($status === 'ok') {
            $res = app(BotcakeFlowNames::class)->afterTokenOk($page, $start + self::TEST_BUDGET);
            $note = $res['error'] === null ? ' · na-refresh ang flows at template' : ' · hindi na-refresh ang flows at template: ' . $res['error'];
        }

        return redirect()->route('page-settings.index')->with($status === 'ok' ? 'success' : 'error', $this->settings->normPage($page) . ' · Token: ' . $status . $note);
    }

    /**
     * "Test lahat ng token": isang page kada request (isa-isang tinatawag ng browser), isang Botcake call na walang
     * retry, kaya hindi lalampas nang ~20 segundo. Itinatala gaya ng row button; ang error ay hindi itinatala.
     * Kapag ok: ang pangalan ng mga flow at ang template, hanggang TEST_BUDGET segundo mula sa simula ng request;
     * ang mga cell na isinulat ay ibinabalik (cells) para ma-update ang row nang walang reload.
     */
    public function testTokenJson(Request $request, BotcakeClient $client, BotcakeCustomerSync $sync, PageSettingsGrid $grid, BotcakeClock $clock)
    {
        $this->checkAccess();
        $start = $clock->now();

        $page   = (string) $request->input('page_name', '');
        $pageId = $this->settings->get($page, 'botcake', 'page_id');
        $token  = $this->settings->get($page, 'botcake', 'token');
        if (!$pageId || !$token) return response()->json(['status' => 'skipped', 'cell' => null]);

        try {
            $status = $client->once()->testToken($pageId, $token) ? 'ok' : 'invalid';
        } catch (BotcakeException $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage(), 'reason' => $e->reason, 'cell' => null]);
        }

        $sync->tokenStatus($page, $status, true);
        $row = $this->settings->pageQuery($page)->where('provider', 'botcake')->where('key', 'token_status')
            ->where('active', true)->orderByDesc('id')->first();

        $out = ['status' => $status, 'cell' => $row ? $grid->cell($row) : null, 'cells' => null];
        if ($status === 'ok') {
            $res = app(BotcakeFlowNames::class)->afterTokenOk($page, $start + self::TEST_BUDGET);
            $out['cells'] = $this->cells($res['written'], $grid);
            $out['refresh_error'] = $res['error'];
        }

        return response()->json($out);
    }

    /** Incremental sync ng isang page, sa queue. */
    public function sync(Request $request, BotcakeCustomerSync $sync)
    {
        $this->checkAccess();

        $key  = $this->settings->pageKey((string) $request->input('page_name', ''));
        $page = collect($sync->pages())->first(fn ($p) => $this->settings->pageKey($p) === $key);
        if (!$page) {
            return redirect()->route('page-settings.index')->with('error', 'Kailangan muna ang botcake.page_id at botcake.token ng page.');
        }

        SyncBotcakeCustomersJob::dispatch($page);

        return redirect()->route('page-settings.index')->with('success', $page . ': naka-queue ang Botcake sync. I-refresh mamaya para sa bilang.');
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
        $this->settings->deactivate($setting, Auth::id());

        return redirect()->route('page-settings.index')->with('success', 'Na-deactivate: ' . $setting->page_name . ' · ' . $setting->fullKey());
    }
}
