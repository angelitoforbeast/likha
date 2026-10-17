<?php

namespace App\Http\Controllers;

use App\Models\BotcakeMessage;
use App\Models\ChatblastCampaign;
use App\Services\Chatblast;
use App\Services\ChatblastSender;
use App\Services\PageSettings;
use App\Support\NavAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * /chatblast/campaigns: ang mga campaign ng chatblast at ang isang sheet ng lahat ng text (isang row kada campaign, araw
 * at bersyon). Access gaya ng /chatblast (NavAccess; CEO laging pasok, 404 sa iba).
 */
class ChatblastCampaignController extends Controller
{
    private const MAX_NAME = 100;
    private const MAX_STATUSES = 10;
    private const MAX_PAGES = 100;
    private const MAX_WINDOW = 60;
    private const MAX_TIMES = 6;

    public function __construct(private Chatblast $chatblast, private PageSettings $settings)
    {
    }

    private function getNormalizedRole(): string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';
        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    private function checkAccess(): void
    {
        if (!NavAccess::allows($this->getNormalizedRole(), '/chatblast')) abort(404);
    }

    public function index()
    {
        $this->checkAccess();

        return view('chatblast.campaigns', ['placeholders' => Chatblast::PLACEHOLDERS]);
    }

    /** Ang mga campaign (kasama ang bilang ngayong araw), ang sheet, at ang mapagpipiliang status at page. */
    public function data()
    {
        $this->checkAccess();

        $start = Carbon::now('Asia/Manila')->startOfDay()->timezone(config('app.timezone'));
        $real  = fn () => BotcakeMessage::where('is_test', false);
        $built  = $real()->where('created_at', '>=', $start)->selectRaw('kind, COUNT(*) AS n')->groupBy('kind')->pluck('n', 'kind');
        $sent   = $real()->where('status', 'sent')->where('sent_at', '>=', $start)->selectRaw('kind, COUNT(*) AS n')->groupBy('kind')->pluck('n', 'kind');
        $failed = $real()->whereIn('status', ['failed', 'field_mismatch'])->where('updated_at', '>=', $start)->selectRaw('kind, COUNT(*) AS n')->groupBy('kind')->pluck('n', 'kind');
        $sends  = [];
        $real()->where('status', 'sent')->selectRaw('kind, day, version, COUNT(*) AS n')->groupBy('kind', 'day', 'version')->get()
            ->each(function ($r) use (&$sends) { $sends[$r->kind . '|' . $r->day . '|' . $r->version] = (int) $r->n; });

        $campaigns = ChatblastCampaign::orderBy('id')->get(ChatblastCampaign::COLUMNS);
        $sheet = [];
        foreach ($campaigns as $c) {
            foreach ((array) $c->days as $d => $versions) {
                foreach ((array) $versions as $v => $text) {
                    $label = Chatblast::label($v);
                    $sheet[] = ['campaign_id' => $c->id, 'name' => $c->name, 'day' => $d + 1, 'version' => $label, 'text' => $text,
                        'sent' => $sends[$c->kind . '|' . ($d + 1) . '|' . $label] ?? 0];
                }
            }
        }

        return response()->json([
            'campaigns' => $campaigns->map(fn ($c) => $this->present($c) + ['today' => [
                'built' => (int) ($built[$c->kind] ?? 0), 'sent' => (int) ($sent[$c->kind] ?? 0), 'failed' => (int) ($failed[$c->kind] ?? 0),
            ]])->values()->all(),
            'sheet'    => $sheet,
            'statuses' => $this->jntStatuses(),
            'pages'    => $this->settings->pagesWith('botcake', Chatblast::REQUIRED),
        ]);
    }

    /** Bagong campaign: manual at naka-on, may isang araw na may isang text. */
    public function store(Request $request)
    {
        $this->checkAccess();
        [$data, $error] = $this->fields($request, null);
        if ($error === null) $error = $this->chatblast->invalid([[$request->input('text')]]);
        if ($error !== null) return response()->json(['error' => $error], 422);

        $c = DB::transaction(function () use ($data, $request) {
            $c = new ChatblastCampaign($data + ['kind' => 'tmp-' . bin2hex(random_bytes(8)), 'mode' => 'manual', 'active' => true,
                'days' => [[trim((string) $request->input('text'))]]]);
            Chatblast::log($c, Auth::id(), ['what' => 'create']);
            $c->save();
            $c->kind = 'c' . $c->id; // ang susi sa botcake_messages.kind: hindi nagbabago kahit palitan ang pangalan
            $c->save();
            return $c;
        });

        return response()->json(['campaign' => $this->present($c)], 201);
    }

    /**
     * Baguhin ang settings, on/off at mode. Ang auto ay kailangang may confirm (nagpapadala ito nang kusa) at
     * itinatala kung sino at kailan; ang off ay humihinto agad sa mga naka-queue ng campaign.
     */
    public function update(Request $request, int $id)
    {
        $this->checkAccess();
        $c = ChatblastCampaign::findOrFail($id);
        [$data, $error] = $this->fields($request, $c);
        if ($error !== null) return response()->json(['error' => $error], 422);

        $mode = $request->has('mode') ? $request->input('mode') : $c->mode;
        if (!in_array($mode, ['manual', 'auto'], true)) return response()->json(['error' => 'Mode: manual o auto lang.'], 422);
        if ($mode === 'auto' && $c->mode !== 'auto' && !$request->boolean('confirm')) {
            return response()->json(['error' => 'Kumpirmahin muna: ang auto ay kusang nagpapadala sa mga oras ng campaign.'], 422);
        }
        $active = $request->has('active') ? filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : $c->active;
        if ($active === null) return response()->json(['error' => 'On/off: true o false lang.'], 422);

        DB::transaction(function () use ($c, $data, $mode, $active) {
            $c = ChatblastCampaign::whereKey($c->id)->lockForUpdate()->first();
            $changed = array_keys(array_filter($data, fn ($v, $k) => $c->{$k} !== $v, ARRAY_FILTER_USE_BOTH));
            $c->fill($data);
            if ($changed) Chatblast::log($c, Auth::id(), ['what' => 'settings', 'fields' => $changed]);

            if ($mode !== $c->mode) {
                Chatblast::log($c, Auth::id(), ['what' => 'mode', 'old' => $c->mode, 'new' => $mode]);
                Log::info('chatblast: campaign mode', ['campaign' => $c->id, 'old' => $c->mode, 'new' => $mode, 'by' => Auth::id()]);
                $c->forceFill(['mode' => $mode, 'mode_changed_by' => Auth::id(), 'mode_changed_at' => now()]);
                // Mula ngayon lang: ang oras na lumipas na bago i-on ang auto ay hindi na hinahabol.
                if ($mode === 'auto') $c->auto_ran_at = now();
            }
            if ($active !== $c->active) {
                Chatblast::log($c, Auth::id(), ['what' => 'active', 'old' => $c->active, 'new' => $active]);
                $c->active = $active;
                if (!$active) {
                    BotcakeMessage::where('kind', $c->kind)->where('status', 'queued')->update(['status' => 'skipped', 'reason' => ChatblastSender::OFF]);
                }
            }
            $c->save();
        });

        return response()->json(['campaign' => $this->present($c->fresh())]);
    }

    /** Alisin ang campaign na wala pang mensahe (ang may mensahe: i-off na lang, para buo ang talaan). */
    public function destroy(int $id)
    {
        $this->checkAccess();
        $c = ChatblastCampaign::findOrFail($id);
        if (BotcakeMessage::where('kind', $c->kind)->exists()) {
            return response()->json(['error' => 'May mga mensahe na ang campaign na ito; i-off na lang.'], 422);
        }
        $c->delete();

        return response()->json(['deleted' => true]);
    }

    /** Isang cell ng sheet: ang text ng isang araw at bersyon. */
    public function text(Request $request, int $id)
    {
        $this->checkAccess();
        [$d, $v] = $this->cell($request);
        $text = $request->input('text');
        if (!is_string($text)) return response()->json(['error' => 'Walang text.'], 422);

        return $this->edit($id, function (array $days) use ($d, $v, $text, &$old) {
            if (!isset($days[$d][$v])) throw new InvalidArgumentException('Walang ganitong araw o bersyon.');
            $old = $days[$d][$v];
            $days[$d][$v] = $text;
            return $days;
        }, ['what' => 'text', 'day' => $d + 1, 'version' => Chatblast::label(max(0, $v)), 'old' => &$old, 'new' => trim($text)]);
    }

    /** Bagong araw sa dulo: kopya ng bersyon A ng huling araw (para buo agad; palitan sa sheet). */
    public function addDay(int $id)
    {
        $this->checkAccess();

        return $this->edit($id, function (array $days) {
            $days[] = [$days[count($days) - 1][0]];
            return $days;
        }, ['what' => 'add_day']);
    }

    /** Bagong bersyon ng araw: kopya ng bersyon A nito. */
    public function addVersion(Request $request, int $id)
    {
        $this->checkAccess();
        [$d] = $this->cell($request);

        return $this->edit($id, function (array $days) use ($d) {
            if (!isset($days[$d])) throw new InvalidArgumentException('Walang ganitong araw.');
            $days[$d][] = $days[$d][0];
            return $days;
        }, ['what' => 'add_version', 'day' => $d + 1]);
    }

    /** Alisin ang isang bersyon; ang huling bersyon ng araw ay inaalis ang araw (kahit isang araw ang natitira). */
    public function remove(Request $request, int $id)
    {
        $this->checkAccess();
        [$d, $v] = $this->cell($request);

        return $this->edit($id, function (array $days) use ($d, $v, &$old) {
            if (!isset($days[$d][$v])) throw new InvalidArgumentException('Walang ganitong araw o bersyon.');
            $old = $days[$d][$v];
            array_splice($days[$d], $v, 1);
            if (!$days[$d]) array_splice($days, $d, 1);
            return $days;
        }, ['what' => 'remove', 'day' => $d + 1, 'version' => Chatblast::label(max(0, $v)), 'old' => &$old]);
    }

    private function edit(int $id, callable $change, array $entry)
    {
        ChatblastCampaign::findOrFail($id);
        try {
            $changed = $this->chatblast->editDays($id, $change, Auth::id(), $entry);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['changed' => $changed, 'days' => ChatblastCampaign::findOrFail($id)->days]);
    }

    /** @return array{0:int, 1:int} [index ng araw, index ng bersyon] mula sa day (1, 2, …) at version (A, B, …) */
    private function cell(Request $request): array
    {
        $day = $request->input('day');
        $ver = $request->input('version');
        $d = is_numeric($day) ? (int) $day - 1 : -1;
        $v = is_string($ver) && preg_match('/^[A-Z]$/', $ver) ? ord($ver) - 65 : -1;

        return [$d, $v];
    }

    /**
     * Ang settings mula sa request (ang ibinigay lang kapag binabago; lahat ng kailangan kapag bago).
     * @return array{0: array<string, mixed>, 1: ?string} [data, error]
     */
    private function fields(Request $request, ?ChatblastCampaign $c): array
    {
        $data = [];
        $has = fn (string $k) => $c === null || $request->has($k);

        if ($has('name')) {
            $name = $request->input('name');
            if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > self::MAX_NAME) return [[], 'Pangalan: 1 hanggang ' . self::MAX_NAME . ' na character.'];
            $data['name'] = trim($name);
        }
        if ($has('statuses')) {
            $statuses = $request->input('statuses');
            $allowed = array_unique(array_merge($this->jntStatuses(), (array) ($c?->statuses ?? [])));
            if (!is_array($statuses) || !$statuses || count($statuses) > self::MAX_STATUSES) return [[], 'Pumili ng 1 hanggang ' . self::MAX_STATUSES . ' na J&T status.'];
            foreach ($statuses as $s) {
                if (!is_string($s) || !in_array($s, $allowed, true)) return [[], 'Hindi kilalang J&T status.'];
            }
            $data['statuses'] = array_values(array_unique($statuses));
        }
        if ($has('pages')) {
            $pages = $request->input('pages') ?? [];
            if (!is_array($pages) || count($pages) > self::MAX_PAGES) return [[], 'Mga page: listahan (walang laman = lahat).'];
            foreach ($pages as $p) {
                if (!is_string($p) || trim($p) === '' || mb_strlen($p) > 255) return [[], 'Mga page: pangalan ng page lang.'];
            }
            $data['pages'] = $pages ? array_values(array_unique(array_map('trim', $pages))) : null;
        }
        if ($has('window_days')) {
            $w = $request->input('window_days');
            if (!is_numeric($w) || (int) $w != $w || $w < 1 || $w > self::MAX_WINDOW) return [[], 'Window: 1 hanggang ' . self::MAX_WINDOW . ' na araw.'];
            $data['window_days'] = (int) $w;
        }
        if ($c === null || $request->has('send_times')) {
            $times = $request->input('send_times') ?? ($c === null ? ['09:00'] : []);
            if (!is_array($times) || count($times) > self::MAX_TIMES) return [[], 'Oras ng padala: hanggang ' . self::MAX_TIMES . '.'];
            foreach ($times as $t) {
                if (!is_string($t) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) return [[], 'Oras ng padala: HH:MM (00:00 hanggang 23:59).'];
            }
            $times = array_values(array_unique($times));
            sort($times);
            $data['send_times'] = $times;
        }
        if ($c === null || $request->has('requires_on_delivery')) {
            $data['requires_on_delivery'] = $request->boolean('requires_on_delivery');
        }

        return [$data, null];
    }

    /** Ang mga J&T status na nasa from_jnts (ang mapagpipilian ng trigger), naka-cache nang 10 minuto. */
    private function jntStatuses(): array
    {
        return Cache::remember('chatblast.jnt-statuses', 600, fn () => DB::table('from_jnts')->whereNotNull('status')->where('status', '<>', '')
            ->distinct()->orderBy('status')->pluck('status')->map(fn ($s) => (string) $s)->all());
    }

    private function present(ChatblastCampaign $c): array
    {
        return [
            'id' => $c->id, 'kind' => $c->kind, 'name' => $c->name, 'statuses' => $c->statuses, 'pages' => $c->pages,
            'window_days' => $c->window_days, 'send_times' => $c->send_times ?? [], 'requires_on_delivery' => $c->requires_on_delivery,
            'mode' => $c->mode, 'active' => $c->active, 'days' => count((array) $c->days),
            'mode_changed_at' => $c->mode_changed_at?->timezone('Asia/Manila')->format('Y-m-d H:i'),
        ];
    }
}
