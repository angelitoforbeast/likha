<?php

namespace App\Http\Controllers;

use App\Models\BotcakeChat;
use App\Models\PageSetting;
use App\Services\BotcakeChatBlock;
use App\Services\BotcakePageSetup;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotPageCheck;
use App\Services\Chatbot\ChatbotProvider;
use App\Services\Chatbot\ChatbotVars;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use App\Support\NavAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * /chatbot: ang listahan ng page ng sariling chatbot. Isang row kada page na may Botcake ID at token; ang ACTIVE (On/Off),
 * FLOW at mga botfield ay nasa page_settings (provider chatbot). Access gaya ng /chatblast (NavAccess; 403 sa iba); ang
 * On/Off at ang pagdagdag ng page ay CEO lang, dahil binabago nito ang live na Botcake page. Walang token o key na
 * ibinabalik.
 */
class ChatbotController extends Controller
{
    private const MAX_CELLS = 5000; // kada Paste values: 60 page × mga 52 column ay mga 3,100
    private const SAMPLE_NAME = 'Juan Dela Cruz'; // ang sample na customer ng Preview
    private const HISTORY_LIMIT = 100;
    private const CHANGES_PER_PAGE = 50;
    private const HIDDEN_KEYS = ['flow_id', 'mock_ai', 'mock_ai_latency_ms']; // system lang: wala sa grid, wala sa Changes
    private const BOTCAKE_LABELS = ['page_id' => 'Botcake ID', 'token' => 'Botcake token']; // ang mga botcake key na nasa grid

    public function __construct(private PageSettings $settings, private ChatbotFields $fields)
    {
    }

    private function role(): string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';
        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    private function checkAccess(): void
    {
        if (!NavAccess::allows($this->role(), '/chatblast')) abort(403);
    }

    private function checkCeo(): void
    {
        $this->checkAccess();
        if ($this->role() !== 'CEO') abort(403);
    }

    public function index()
    {
        $this->checkAccess();
        $listed = array_map(fn ($p) => $this->settings->pageKey($p), $this->pages());
        // Para sa "+ Page": ang mga page sa page settings na wala pa sa listahan.
        $others = PageSetting::where('active', true)->where('page_name', '!=', \App\Services\ChatblastSetupCheck::ALL_PAGE)->distinct()->orderBy('page_name')->pluck('page_name')
            ->unique(fn ($p) => $this->settings->pageKey($p))->reject(fn ($p) => in_array($this->settings->pageKey($p), $listed, true))->values();

        return view('chatbot.index', ['isCeo' => $this->role() === 'CEO', 'otherPages' => $others, 'repliesOn' => \App\Services\Chatbot\BotcakeOutbox::enabled()]);
    }

    /** Ang mga column at row (isang query ng settings, at ang chats ngayong araw at ang huli kada page). */
    public function data()
    {
        $this->checkAccess();
        $columns = $this->fields->columns();
        $pages = $this->pages();

        $byPage = PageSetting::where('active', true)->whereIn('provider', ['botcake', ChatbotFields::PROVIDER])
            ->whereIn('key', array_merge(['page_id', 'active', 'flow_id', 'provider'], array_column($columns, 'key')))->orderBy('id')->get()
            ->groupBy(fn ($s) => $this->settings->pageKey($s->page_name));
        $stats = $this->chatStats();
        $flowNames = Schema::hasTable('chatbot_flows') ? \App\Models\ChatbotFlow::pluck('name', 'id') : collect();
        // Ang Check: ang naka-cache lang (ang wala pa ay kinukuha ng page isa-isa sa /chatbot/check).
        $check = app(ChatbotPageCheck::class);
        $prints = $check->prints();

        $rows = [];
        foreach ($pages as $page) {
            $cells = [];
            $pageId = null;
            $active = true;
            $flowId = null;
            $provider = ChatbotProvider::BOTCAKE;
            foreach ($byPage[$this->settings->pageKey($page)] ?? [] as $s) {
                if ($s->provider === 'botcake') {
                    if ($s->key === 'page_id') $pageId = (string) $s->plainValue();
                    continue;
                }
                if ($s->key === 'active') $active = $s->plainValue() !== '0';
                elseif ($s->key === 'flow_id') $flowId = (int) $s->plainValue();
                elseif ($s->key === 'provider') $provider = $s->plainValue() === ChatbotProvider::GATEWAY ? ChatbotProvider::GATEWAY : ChatbotProvider::BOTCAKE;
                else $cells[$s->key] = $s->plainValue();
            }
            // FLOW: ang flow na talagang nakatali (ayon sa id). Ang lumang text na hindi nakatali ay hiwalay, para
            // makita na hindi ito tumatakbo at mapili ang flow.
            $text = $cells['flow'] ?? null;
            $linked = $flowId ? ($flowNames[$flowId] ?? null) : null;
            if ($linked !== null) $cells['flow'] = $linked; else unset($cells['flow']);
            $rows[] = [
                'page' => $page, 'page_id' => $pageId, 'active' => $active, 'provider' => $provider, 'cells' => (object) $cells,
                'flow_text' => $linked === null && $text !== null && $text !== '' ? $text : null,
                'last_chat' => $stats['last'][$pageId] ?? null, 'today' => (int) ($stats['today'][$pageId] ?? 0),
                'check' => $check->cached($page, $prints),
            ];
        }

        return response()->json(['columns' => $columns, 'rows' => $rows, 'flows' => $this->flowNames(), 'gateway_ready' => ChatbotProvider::gatewayReady()]);
    }

    /** Auto-save ng isang botfield. Blangko = burahin. Last write wins. */
    public function cell(Request $request)
    {
        $this->checkAccess();
        $page = $this->listedPage($this->str($request, 'page_name'));
        $key = strtolower($this->str($request, 'key'));
        $value = trim($this->str($request, 'value', ChatbotFields::MAX_VALUE + 1));
        if ($page === null) return response()->json(['error' => 'This page is not on the chatbot list.'], 422);
        if (in_array($key, ChatbotFields::RESERVED, true)) return response()->json(['error' => 'ACTIVE is changed with the On/Off switch.'], 422);
        if (!$this->fields->isColumn($key)) return response()->json(['error' => 'Unknown column.'], 422);
        if ($error = $this->valueError($key, $value)) return response()->json(['error' => $error], 422);
        if ($value !== '' && $key === 'flow' && !in_array($value, $this->flowNames(), true)) {
            return response()->json(['error' => 'Pick a flow from the list.'], 422);
        }
        // FLOW: ang pinili sa dropdown ay itinatali ayon sa id (chatbot.flow_id), para ang FLOW text lang ay hindi tumakbo.
        if ($key === 'flow') {
            $had = $this->settings->get($page, ChatbotFields::PROVIDER, 'flow');
            $flow = $value === '' ? null : \App\Models\ChatbotFlow::where('name', $value)->first();
            app(\App\Services\Chatbot\ChatbotPage::class)->link($page, $flow, Auth::id());
            $status = $flow ? ($had === $flow->name ? 'linked' : 'created') : ($had === null ? 'unchanged' : 'cleared');

            return response()->json(['status' => $status, 'value' => $flow?->name]);
        }

        if ($value === '') {
            $actives = $this->settings->pageQuery($page)->where('provider', ChatbotFields::PROVIDER)->where('key', $key)->where('active', true)->get();
            foreach ($actives as $s) $this->settings->deactivate($s, Auth::id());
            return response()->json(['status' => $actives->isEmpty() ? 'unchanged' : 'cleared', 'value' => null]);
        }

        $res = $this->settings->set($page, ChatbotFields::PROVIDER, $key, $value, false, Auth::id());

        return response()->json(['status' => $res['status'], 'value' => $res['setting']->plainValue()]);
    }

    /**
     * Paste values: maraming botfield sa isang request at isang transaction, lahat o wala (isang mali = 422, walang
     * na-save). Bawat isa ay gaya ng isang cell (PageSettings, parehong limit at link check). Ang ACTIVE at FLOW ay
     * nilalaktawan; ang blangko ay walang binabago maliban kung blanks_empty. Ibinabalik ang dating value ng bawat
     * nabago ('' = wala dati), para sa Undo, na ipinapadala ulit dito na may blanks_empty.
     */
    public function cells(Request $request)
    {
        $this->checkAccess();
        $changes = $request->input('changes');
        if (!is_array($changes) || $changes === []) return response()->json(['error' => 'Nothing to save.'], 422);
        if (count($changes) > self::MAX_CELLS) return response()->json(['error' => 'At most ' . self::MAX_CELLS . ' fields per save.'], 422);
        $blanksEmpty = $request->boolean('blanks_empty');
        $columns = array_column($this->fields->columns(), 'key');
        $listed = [];
        foreach ($this->pages() as $p) $listed[$this->settings->pageKey($p)] = $p;

        $todo = [];
        $errors = [];
        $skipped = 0;
        foreach (array_values($changes) as $c) {
            $c = is_array($c) ? $c : [];
            [$pageIn, $key, $value] = array_map(fn ($f) => is_scalar($c[$f] ?? null) ? trim((string) $c[$f]) : '', ['page_name', 'key', 'value']);
            $key = strtolower($key);
            if ($key === 'flow' || in_array($key, ChatbotFields::RESERVED, true)) { $skipped++; continue; }
            $page = $listed[$this->settings->pageKey($pageIn)] ?? null;
            $error = $page === null ? 'This page is not on the chatbot list.' : (in_array($key, $columns, true) ? $this->valueError($key, $value) : 'Unknown column.');
            if ($error === null && isset($c['value']) && !is_scalar($c['value'])) $error = 'The value must be text.';
            if ($error !== null) { $errors[] = ['page_name' => $pageIn, 'key' => $key, 'error' => $error]; continue; }
            if ($value === '' && !$blanksEmpty) continue;
            $todo[$this->settings->pageKey($page) . "\0" . $key] = [$page, $key, $value]; // parehong cell: ang huli ang masusunod
        }
        if ($errors) {
            return response()->json(['error' => count($errors) . (count($errors) === 1 ? ' field' : ' fields') . ' cannot be saved, so nothing was saved.', 'errors' => array_slice($errors, 0, 50)], 422);
        }

        $undo = [];
        $unchanged = 0;
        $pages = [];
        DB::transaction(function () use ($todo, &$undo, &$unchanged, &$pages) {
            foreach ($todo as [$page, $key, $value]) {
                $before = (string) $this->settings->get($page, ChatbotFields::PROVIDER, $key);
                if ($value === '') {
                    $actives = $this->settings->pageQuery($page)->where('provider', ChatbotFields::PROVIDER)->where('key', $key)->where('active', true)->get();
                    foreach ($actives as $s) $this->settings->deactivate($s, Auth::id());
                    $changed = $actives->isNotEmpty();
                } else {
                    $changed = $this->settings->set($page, ChatbotFields::PROVIDER, $key, $value, false, Auth::id())['status'] !== 'unchanged';
                }
                if (!$changed) { $unchanged++; continue; }
                $undo[] = ['page_name' => $page, 'key' => $key, 'value' => $before];
                $pages[$this->settings->pageKey($page)] = true;
            }
        });

        return response()->json(['saved' => count($undo), 'unchanged' => $unchanged, 'skipped' => $skipped, 'pages' => count($pages), 'undo' => $undo]);
    }

    /** "+ Column": bagong botfield para sa lahat ng page. */
    public function column(Request $request)
    {
        $this->checkAccess();
        $col = $this->fields->add($this->str($request, 'name', 100));
        if ($col === null) return response()->json(['error' => 'Use letters or numbers (ACTIVE is taken); at most ' . ChatbotFields::MAX_ADDED . ' added columns.'], 422);

        return response()->json($col + ['kind' => ChatbotFields::kind($col['key'])]);
    }

    /**
     * Ang daan ng chatbot ng page: Botcake o ang Forbeast Gateway (chatbot.provider, may history). Access gaya ng isang cell;
     * ang gateway ay mapipili lang kapag naka-set up (GATEWAY_URL).
     */
    public function provider(Request $request)
    {
        $this->checkAccess();
        $page = $this->listedPage($this->str($request, 'page_name'));
        $provider = strtolower($this->str($request, 'provider', 20));
        if ($page === null) return response()->json(['error' => 'This page is not on the chatbot list.'], 422);
        if (!in_array($provider, ChatbotProvider::ALL, true)) return response()->json(['error' => 'Pick Botcake or Forbeast Gateway.'], 422);
        if ($provider === ChatbotProvider::GATEWAY && !ChatbotProvider::gatewayReady()) {
            return response()->json(['error' => 'The Forbeast Gateway is not set up yet.'], 422);
        }
        $res = $this->settings->set($page, ChatbotFields::PROVIDER, 'provider', $provider, false, Auth::id());

        return response()->json(['status' => $res['status'], 'provider' => $provider]);
    }

    /** ACTIVE On (isulat ang block at i-publish) o Off (i-unpublish lang). Ang error ng Botcake ay walang binabago dito. */
    public function active(Request $request)
    {
        $this->checkCeo();
        $page = $this->listedPage($this->str($request, 'page_name'));
        if ($page === null) return response()->json(['error' => 'This page is not on the chatbot list.'], 422);

        $chatBlock = app(BotcakeChatBlock::class);
        $r = $request->boolean('on') ? $chatBlock->turnOn($page) : $chatBlock->turnOff($page);

        return response()->json([
            'active' => $this->settings->get($page, ChatbotFields::PROVIDER, 'active') !== '0',
            'error' => $r['ok'] ? null : $r['error'],
        ]);
    }

    /**
     * "+ Page": bago (pangalan, Botcake ID at token) o mula sa page na nasa page settings na (ang kulang lang ang
     * kailangan). Isinusulat gaya ng page settings (PageSettings::set; ang token ay secret), saka ang Test token nito, at
     * kapag pumasa, ang On (BotcakeChatBlock::turnOn). Pumalya ang test o ang On: naka-save ang page pero Off.
     */
    public function addPage(Request $request)
    {
        $this->checkCeo();
        $page = $this->settings->normPage($this->str($request, 'page_name'));
        $pageId = $this->str($request, 'page_id', 100);
        $token = $this->str($request, 'token', 1000);
        if ($page === '') return response()->json(['error' => 'The page name is required.'], 422);
        if ($this->listedPage($page) !== null) return response()->json(['error' => 'This page is already on the list.'], 422);

        $pageId = $pageId !== '' ? $pageId : trim((string) $this->settings->get($page, 'botcake', 'page_id'));
        $hasToken = $token !== '' || trim((string) $this->settings->get($page, 'botcake', 'token')) !== '';
        if ($pageId === '' || !$hasToken) return response()->json(['error' => 'A Botcake ID and a token are needed.'], 422);
        if ($errors = $this->settings->validate($page, 'botcake', 'page_id', $pageId)) return response()->json(['error' => reset($errors)], 422);
        // Isang Botcake ID, isang page: ang pangalawang page na may parehong ID ay aagaw sa mga chat ng una (mawawala).
        $owner = PageSetting::where('provider', 'botcake')->where('key', 'page_id')->where('active', true)->where('value', $pageId)
            ->pluck('page_name')->first(fn ($p) => $this->settings->pageKey($p) !== $this->settings->pageKey($page));
        if ($owner !== null) return response()->json(['error' => "Botcake ID {$pageId} is already used by {$owner}."], 422);

        $this->settings->set($page, 'botcake', 'page_id', $pageId, false, Auth::id());
        if ($token !== '') $this->settings->set($page, 'botcake', 'token', $token, true, Auth::id());
        BotcakeChat::forgetPageMap();

        try {
            $check = app(BotcakePageSetup::class)->check($page);
        } catch (Throwable $e) {
            report($e);
            $check = ['status' => 'error', 'error' => 'Botcake: no answer'];
        }

        // Pumasa ang token: ise-setup agad gaya ng On (key, block, publish). Pumalya: naka-save pa rin pero Off.
        $listed = $this->listedPage($page) ?? $page;
        $error = $check['error'] ?? null;
        $on = false;
        if ($check['status'] === 'ok') {
            try {
                $r = app(BotcakeChatBlock::class)->turnOn($listed);
            } catch (Throwable $e) {
                report($e);
                $r = ['ok' => false, 'error' => 'Could not set up the page: Botcake did not answer.'];
            }
            $on = $r['ok'];
            if (!$on) $error = $r['error'] . ' The bot stays Off.';
        } else {
            $error = 'The token test failed' . ($error ? ' (' . $error . ')' : '') . ', so the bot was not set up and stays Off.';
        }
        if (!$on) $this->settings->set($listed, ChatbotFields::PROVIDER, 'active', '0', false, null, true);

        return response()->json(['page' => $listed, 'token_test' => $check['status'], 'active' => $on, 'error' => $error]);
    }

    /**
     * Ang Check ng isang page: ang naka-cache (GET), o kinukuwenta ulit kasama ang mga link ng larawan (POST, "Check
     * again"; naka-queue rin ang content check ng page, dahil may tanong ito sa AI).
     */
    public function check(Request $request, ChatbotPageCheck $check)
    {
        $this->checkAccess();
        $page = $this->listedPage($this->str($request, 'page_name'));
        if ($page === null) return response()->json(['error' => 'This page is not on the chatbot list.'], 422);
        if ($request->isMethod('post')) \App\Jobs\RunChatbotContentCheck::dispatch($page);

        return response()->json(['page' => $page] + $check->check($page, $request->isMethod('post')));
    }

    /**
     * Preview: ang mga pangunahing text (Main Flow, Main Flow 2, Fill-Up Form, Loop 3 Form, Seq 1–20) gaya ng makikita ng
     * isang sample na customer, pinupunan gaya ng FlowRun::vars (ang botfield na may variable ay isang antas lang). Walang
     * isinusulat.
     */
    public function preview(Request $request)
    {
        $this->checkAccess();
        $page = $this->listedPage($this->str($request, 'page_name'));
        if ($page === null) return response()->json(['error' => 'This page is not on the chatbot list.'], 422);

        $bot = app(ChatbotPage::class)->botfields($page);
        $run = [
            'last_input' => '', 'full_name' => self::SAMPLE_NAME, 'first_name' => explode(' ', self::SAMPLE_NAME)[0], 'psid' => 'SAMPLE',
            'page_id' => (string) $this->settings->get($page, 'botcake', 'page_id'), 'page_name' => $page,
            'current_date' => now('Asia/Manila')->toDateString(), 'last_interaction' => now('Asia/Manila')->format('Y-m-d H:i'),
        ];
        $base = ChatbotVars::merge($bot, $run);
        $labels = array_column($this->fields->columns(), 'label', 'key');
        $text = fn (string $key) => trim($bot[$key] ?? '') === '' ? null : ChatbotVars::interpolate((string) $bot[$key], $base);
        $image = fn (string $key) => $this->isImageUrl(trim($bot[$key] ?? '')) ? trim($bot[$key]) : null;

        $items = [];
        $add = function (string $label, ?string $text, ?string $image) use (&$items) {
            if ($text !== null || $image !== null) $items[] = ['label' => $label, 'text' => $text, 'image' => $image];
        };
        $add($labels['picture'] ?? 'PICTURE', null, $image('picture'));
        foreach (['main_flow', 'main_flow_2', 'fill_up_form', 'loop_3_form'] as $key) $add($labels[$key] ?? $key, $text($key), null);
        for ($i = 1; $i <= 20; $i++) $add($labels['seq_' . $i] ?? 'Seq ' . $i, $text('seq_' . $i), $image('seq_img_' . $i));

        return response()->json(['page' => $page, 'sample' => self::SAMPLE_NAME, 'items' => $items]);
    }

    /** History ng isang botfield ng page: lahat ng bersyon, bago muna. Ang secret ay "(hidden)". */
    public function history(Request $request)
    {
        $this->checkAccess();
        $page = $this->listedPage($this->str($request, 'page_name'));
        $key = strtolower($this->str($request, 'key'));
        if ($page === null) return response()->json(['error' => 'This page is not on the chatbot list.'], 422);
        if (!$this->fields->isColumn($key) && !in_array($key, ['active', 'flow'], true)) return response()->json(['error' => 'Unknown column.'], 422);

        $rows = $this->settings->pageQuery($page)->where('provider', ChatbotFields::PROVIDER)->where('key', $key)->orderByDesc('id')->limit(self::HISTORY_LIMIT)->get();
        $names = $this->userNames($rows->pluck('created_by'));

        return response()->json(['versions' => $rows->map(fn ($s) => [
            'id' => $s->id, 'value' => $this->shown($s), 'secret' => (bool) $s->is_secret, 'current' => (bool) $s->active,
            'by' => $this->who($s, $names), 'at' => $s->created_at?->timezone('Asia/Manila')->format('Y-m-d H:i'),
            'restorable' => !$s->active && $this->restorable($s),
        ])->all()]);
    }

    /** Restore: ibinabalik ang lumang bersyon sa pamamagitan ng PageSettings::set (kaya bagong row din ito sa history). */
    public function restore(Request $request)
    {
        $this->checkAccess();
        $s = PageSetting::find((int) $this->str($request, 'id', 20));
        $page = $s ? $this->listedPage((string) $s->page_name) : null;
        if (!$s || $page === null || !$this->restorable($s)) return response()->json(['error' => 'This version cannot be restored here.'], 422);
        $value = trim((string) $s->plainValue());
        if ($value === '' || ($error = $this->valueError($s->key, $value))) return response()->json(['error' => $error ?? 'This version is empty.'], 422);

        $res = $this->settings->set($page, ChatbotFields::PROVIDER, $s->key, $value, false, Auth::id());

        return response()->json(['status' => $res['status'], 'key' => $s->key, 'value' => $res['setting']->plainValue()]);
    }

    /**
     * Changes: ang mga pagbabago sa lahat ng chatbot page (provider chatbot, at ang Botcake ID at token ng grid), bago
     * muna, 50 kada pahina. Bawat row ay ipinapares sa nauna nitong bersyon (parehong page, provider at key). Filter:
     * page, user, field (key, o "botcake.page_id"), at petsa (Manila, kasama ang dulo).
     */
    public function changes(Request $request)
    {
        $this->checkAccess();
        $page = $this->str($request, 'page');
        $field = strtolower($this->str($request, 'field', 120));
        $user = $this->str($request, 'user', 20);

        $q = ($page !== '' ? $this->settings->pageQuery($page) : PageSetting::query())->where('page_name', '!=', \App\Services\ChatblastSetupCheck::ALL_PAGE)
            ->where(fn ($w) => $w->where(fn ($a) => $a->where('provider', ChatbotFields::PROVIDER)->whereNotIn('key', self::HIDDEN_KEYS))
                ->orWhere(fn ($b) => $b->where('provider', 'botcake')->whereIn('key', array_keys(self::BOTCAKE_LABELS))));
        $users = $this->userNames((clone $q)->whereNotNull('created_by')->distinct()->pluck('created_by'));

        if ($field !== '') {
            [$provider, $key] = str_starts_with($field, 'botcake.') ? ['botcake', substr($field, 8)] : [ChatbotFields::PROVIDER, $field];
            $q->where('provider', $provider)->where('key', $key);
        }
        if (ctype_digit($user)) $q->where('created_by', (int) $user);
        foreach (['from' => 'startOfDay', 'to' => 'endOfDay'] as $param => $edge) {
            $day = $this->str($request, $param, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) continue;
            try {
                $at = \Carbon\Carbon::createFromFormat('Y-m-d', $day, 'Asia/Manila')->{$edge}()->timezone(config('app.timezone'));
            } catch (Throwable $e) {
                continue;
            }
            $q->where('created_at', $param === 'from' ? '>=' : '<=', $at);
        }

        $total = (clone $q)->count();
        $last = max(1, (int) ceil($total / self::CHANGES_PER_PAGE));
        $p = min($last, max(1, (int) $this->str($request, 'p', 6)));
        $rows = $q->orderByDesc('id')->forPage($p, self::CHANGES_PER_PAGE)->get();
        $labels = array_column($this->fields->columns(), 'label', 'key');

        $entries = $rows->map(function ($s) use ($users, $labels) {
            $before = PageSetting::where('page_name', $s->page_name)->where('provider', $s->provider)->where('key', $s->key)->where('id', '<', $s->id)->orderByDesc('id')->first();
            return [
                'id' => $s->id, 'at' => $s->created_at?->timezone('Asia/Manila')->format('Y-m-d H:i'), 'by' => $this->who($s, $users),
                'page' => $s->page_name, 'provider' => $s->provider, 'key' => $s->key,
                'field' => $s->provider === 'botcake' ? self::BOTCAKE_LABELS[$s->key] : ($labels[$s->key] ?? ucwords(str_replace('_', ' ', $s->key))),
                'old' => $before ? $this->shown($before) : null, 'new' => $this->shown($s),
            ];
        })->all();

        $fields = array_merge(
            array_map(fn ($k, $l) => ['key' => (string) $k, 'label' => $l], array_keys($labels), $labels),
            array_map(fn ($k, $l) => ['key' => 'botcake.' . $k, 'label' => $l], array_keys(self::BOTCAKE_LABELS), self::BOTCAKE_LABELS),
        );

        return response()->json([
            'entries' => $entries, 'total' => $total, 'page' => $p, 'last_page' => $last, 'per_page' => self::CHANGES_PER_PAGE,
            'users' => collect($users)->map(fn ($n, $id) => ['id' => (int) $id, 'name' => $n])->values()->all(),
            'pages' => $this->pages(), 'fields' => $fields,
        ]);
    }

    /** Ang value na maipapakita: ang secret ay hindi kailanman. */
    private function shown(PageSetting $s): string
    {
        return $s->is_secret ? '(hidden)' : (string) $s->plainValue();
    }

    private function who(PageSetting $s, array $names): string
    {
        return $s->created_by ? ($names[$s->created_by] ?? 'user #' . $s->created_by) : 'system';
    }

    /** @return array<int, string> id => pangalan */
    private function userNames($ids): array
    {
        $ids = collect($ids)->filter()->unique()->values();

        return $ids->isEmpty() ? [] : \App\Models\User::whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /** Ang bersyong maibabalik: botfield ng chatbot na nae-edit sa cell (hindi ACTIVE, FLOW o secret). */
    private function restorable(PageSetting $s): bool
    {
        return $s->provider === ChatbotFields::PROVIDER && !$s->is_secret && $s->key !== 'flow'
            && !in_array($s->key, ChatbotFields::RESERVED, true) && $this->fields->isColumn($s->key);
    }

    /** @return array<int, string> ang mga page na may Botcake ID at token, ayon sa pangalan */
    private function pages(): array
    {
        $pages = $this->settings->pagesWith('botcake', ['page_id', 'token']);
        usort($pages, 'strcasecmp');

        return $pages;
    }

    /** Ang pangalan (gaya ng naitala) kapag nasa listahan ang page, kung hindi null. */
    private function listedPage(string $page): ?string
    {
        $key = $this->settings->pageKey($page);
        foreach ($this->pages() as $p) if ($this->settings->pageKey($p) === $key) return $p;

        return null;
    }

    /**
     * Ang chats ngayong araw at ang huling chat kada Botcake page ID (naka-cache 1 minuto). Ang huli: isang MAX kada page
     * sa index na (page_id, received_at), hindi isang GROUP BY sa buong table.
     * @return array{today: array<string, int>, last: array<string, string>}
     */
    private function chatStats(): array
    {
        if (!Schema::hasTable('botcake_chats')) return ['today' => [], 'last' => []];

        return Cache::remember('chatbot:chat-stats', 60, function () {
            $today = BotcakeChat::where('received_at', '>=', now('Asia/Manila')->startOfDay()->timezone(config('app.timezone')))
                ->whereNotNull('page_id')->selectRaw('page_id, COUNT(*) AS n')->groupBy('page_id')->pluck('n', 'page_id')->map(fn ($n) => (int) $n)->all();
            $last = [];
            foreach (array_keys(BotcakeChat::pageMap()) as $id) {
                $at = BotcakeChat::where('page_id', (string) $id)->max('received_at');
                if ($at) $last[(string) $id] = \Carbon\Carbon::parse($at)->timezone('Asia/Manila')->format('Y-m-d H:i');
            }
            return ['today' => $today, 'last' => $last];
        });
    }

    /** @return array<int, string> ang mga flow (para sa FLOW na dropdown) */
    private function flowNames(): array
    {
        return Schema::hasTable('chatbot_flows') ? \App\Models\ChatbotFlow::orderBy('name')->pluck('name')->all() : [];
    }

    /** Ang mali sa value ng isang botfield (haba, bytes, link ng larawan), o null. Iisa para sa isang cell at sa paste. */
    private function valueError(string $key, string $value): ?string
    {
        if (mb_strlen($value) > ChatbotFields::MAX_VALUE) return 'At most ' . ChatbotFields::MAX_VALUE . ' characters.';
        if (strlen($value) > ChatbotFields::MAX_BYTES) return 'Too long: at most ' . ChatbotFields::MAX_BYTES . ' bytes. Accented letters and emoji take 2 to 4 bytes each.';
        if ($value !== '' && ChatbotFields::kind($key) === 'image' && !$this->isImageUrl($value)) return 'Use an image link that starts with http:// or https://.';

        return null;
    }

    private function isImageUrl(string $value): bool
    {
        return (bool) filter_var($value, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function str(Request $request, string $key, int $max = 255): string
    {
        $v = $request->input($key);
        return is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
    }
}
