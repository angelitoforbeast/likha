<?php

namespace App\Http\Controllers;

use App\Models\BotcakeMessage;
use App\Models\ChatblastCampaign;
use App\Services\Chatblast;
use App\Services\ChatblastFlowInfo;
use App\Services\ChatblastSchedule;
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
 * /chatblast/sequence: ang mga sequence ng chatblast (gaya ng sequence sa Botcake; ang table ay chatblast_campaigns),
 * lahat sa isang page at ine-edit sa lugar: trigger, ang mga step (ang mensahe ng bawat isa mula sa /chatblast/campaigns,
 * at ang sariling oras), at ang schedule. Access gaya ng /chatblast (NavAccess; CEO laging pasok, 404 sa iba).
 */
class ChatblastCampaignController extends Controller
{
    private const MAX_NAME = 100;
    private const MAX_STATUSES = 10;
    private const MAX_PAGES = 100;
    private const MAX_WINDOW = 60;
    private const MAX_CAP = 100000;
    private const STALE = 'Nabago na ang mga step (ibang tab o tao); i-reload ang page.';

    public function __construct(private Chatblast $chatblast, private PageSettings $settings, private ChatblastSchedule $schedule)
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

        return view('chatblast.sequence', ['placeholders' => Chatblast::PLACEHOLDERS]);
    }

    /** Ang mga campaign (kasama ang bilang ngayong araw), ang sheet, at ang mapagpipiliang status at page. */
    public function data()
    {
        $this->checkAccess();

        $start = Carbon::now('Asia/Manila')->startOfDay()->timezone(config('app.timezone'));
        $real  = fn () => BotcakeMessage::where('is_test', false);
        $built  = $real()->where('created_at', '>=', $start)->selectRaw('kind, COUNT(*) AS n')->groupBy('kind')->pluck('n', 'kind');
        $sent   = $real()->where('status', 'sent')->where('sent_at', '>=', $start)->selectRaw('kind, COUNT(*) AS n')->groupBy('kind')->pluck('n', 'kind');
        $ready  = $real()->where('status', 'ready')->selectRaw('kind, COUNT(*) AS n')->groupBy('kind')->pluck('n', 'kind'); // para sa kumpirmasyon ng auto
        $failed = $real()->whereIn('status', ['failed', 'field_mismatch'])->where('updated_at', '>=', $start)->selectRaw('kind, COUNT(*) AS n')->groupBy('kind')->pluck('n', 'kind');
        $skipped = $real()->where('status', 'skipped')->where('updated_at', '>=', $start)->selectRaw('kind, COUNT(*) AS n')->groupBy('kind')->pluck('n', 'kind');
        // Ngayong araw kada step: nabuo, naipadala, failed, nilaktawan.
        // Gaya ng bilang ng sequence: nabuo ayon sa created_at, naipadala ayon sa sent_at, failed at nilaktawan ayon sa updated_at.
        $perStep = [];
        $byStep = function (string $bucket, $query) use (&$perStep) {
            $query->selectRaw('kind, day, COUNT(*) AS n')->groupBy('kind', 'day')->get()->each(function ($r) use (&$perStep, $bucket) {
                $perStep[$r->kind . '|' . $r->day] ??= ['built' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
                $perStep[$r->kind . '|' . $r->day][$bucket] += (int) $r->n;
            });
        };
        $byStep('built', $real()->where('created_at', '>=', $start));
        $byStep('sent', $real()->where('status', 'sent')->where('sent_at', '>=', $start));
        $byStep('failed', $real()->whereIn('status', ['failed', 'field_mismatch'])->where('updated_at', '>=', $start));
        $byStep('skipped', $real()->where('status', 'skipped')->where('updated_at', '>=', $start));

        $campaigns = ChatblastCampaign::orderBy('id')->get(ChatblastCampaign::COLUMNS);
        $messages = \App\Models\ChatblastMessage::orderBy('name')->get(['id', 'name', 'versions']);
        $names = $messages->pluck('name', 'id');
        $now = Carbon::now('Asia/Manila');

        return response()->json([
            'campaigns' => $campaigns->map(fn ($c) => $this->present($c) + [
                'today' => ['built' => (int) ($built[$c->kind] ?? 0), 'sent' => (int) ($sent[$c->kind] ?? 0),
                    'failed' => (int) ($failed[$c->kind] ?? 0), 'skipped' => (int) ($skipped[$c->kind] ?? 0)],
                'ready' => (int) ($ready[$c->kind] ?? 0),
                'summary' => $this->schedule->summary($c),
                'next_run' => $this->schedule->nextRun($c, $now)?->format('Y-m-d H:i'),
                'steps' => array_map(fn ($versions, $i) => [
                    'day' => $i + 1, 'message_id' => ((array) $c->step_messages)[$i] ?? null,
                    'message' => $names[((array) $c->step_messages)[$i] ?? 0] ?? null, 'versions' => $versions,
                    'times' => ((array) ($c->step_times ?? []))[$i] ?? null,
                    'today' => $perStep[$c->kind . '|' . ($i + 1)] ?? ['built' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0],
                ], (array) $c->days, array_keys((array) $c->days)),
            ])->values()->all(),
            'messages' => $messages->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'versions' => $m->versions])->values()->all(),
            'overlaps' => $this->overlaps($campaigns->where('active', true)->values()->all()),
            'statuses' => $this->jntStatuses(),
            'pages'    => $this->settings->pagesWith('botcake', Chatblast::REQUIRED),
        ]);
    }

    /**
     * "Flow at template" ng isang page na may chatblast settings (naka-cache nang 10 minuto; refresh=1 para kunin
     * ulit). Walang token sa sagot.
     */
    public function flow(Request $request, ChatblastFlowInfo $info)
    {
        $this->checkAccess();
        $page = $request->input('page');
        $pages = $this->settings->pagesWith('botcake', Chatblast::REQUIRED);
        $match = is_string($page) ? collect($pages)->first(fn ($p) => $this->settings->pageKey($p) === $this->settings->pageKey($page)) : null;
        if ($match === null) return response()->json(['error' => 'Walang chatblast settings ang page na ito.'], 422);
        @set_time_limit(55); // tatlong tawag sa Botcake, tig-10 s

        return response()->json($info->forPage($match, $request->boolean('refresh')));
    }

    /**
     * Bagong sequence: manual at naka-on, may isang step: ang napiling mensahe (message_id), o bagong mensahe
     * "<pangalan> · Day 1" mula sa text.
     */
    public function store(Request $request)
    {
        $this->checkAccess();
        [$data, $error] = $this->fields($request, null);
        $message = $request->filled('message_id') ? \App\Models\ChatblastMessage::find((int) $request->input('message_id')) : null;
        if ($error === null && $request->filled('message_id') && !$message) $error = 'Walang ganitong mensahe.';
        if ($error === null && !$message) $error = $this->chatblast->invalid([[$request->input('text')]]);
        if ($error !== null) return response()->json(['error' => $error], 422);

        $c = DB::transaction(function () use ($data, $request, $message) {
            if (!$message) {
                $message = new \App\Models\ChatblastMessage(['name' => $this->chatblast->freeName($data['name'] . ' · Day 1'), 'versions' => [trim((string) $request->input('text'))]]);
                Chatblast::log($message, Auth::id(), ['what' => 'create']);
                $message->save();
            }
            $c = new ChatblastCampaign($data + ['kind' => 'tmp-' . bin2hex(random_bytes(8)), 'mode' => 'manual', 'active' => true,
                'days' => [$message->versions], 'step_messages' => [$message->id]]);
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
            $before = clone $c;
            $c->fill($data);
            if ($changed) Chatblast::log($c, Auth::id(), ['what' => 'settings', 'fields' => $changed]);

            if ($mode !== $c->mode) {
                Chatblast::log($c, Auth::id(), ['what' => 'mode', 'old' => $c->mode, 'new' => $mode]);
                Log::info('chatblast: campaign mode', ['campaign' => $c->id, 'old' => $c->mode, 'new' => $mode, 'by' => Auth::id()]);
                $c->forceFill(['mode' => $mode, 'mode_changed_by' => Auth::id(), 'mode_changed_at' => now()]);
                // Mula ngayon lang: ang oras na lumipas na bago i-on ang auto ay hindi na hinahabol.
                if ($mode === 'auto') $c->auto_ran_at = now();
            }
            // Ang oras na dapat tumakbo ngayon sa bagong schedule pero hindi sa dati (bagong oras na lumipas na, o
            // idinagdag ang araw na ito) ay hindi tatakbo; ang dating oras na hindi pa tumatakbo ay hindi naaapektuhan.
            $this->schedule->stampNewPastTimes($before, $c);
            if ($active !== $c->active) {
                Chatblast::log($c, Auth::id(), ['what' => 'active', 'old' => $c->active, 'new' => $active]);
                $c->active = $active;
                if (!$active) {
                    // Binibitawan ang key: ang pag-on ulit ay gagawa ng bagong row para sa mga waybill na ito.
                    BotcakeMessage::where('kind', $c->kind)->where('status', 'queued')
                        ->update(['status' => 'skipped', 'reason' => ChatblastSender::OFF, 'dedupe_key' => null]);
                } elseif ($c->mode === 'auto') {
                    $c->auto_ran_at = now(); // gaya ng paglipat sa auto: ang lumipas na oras habang naka-off ay hindi hinahabol
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

    /** Bagong step pagkatapos ng Day N (after = 0: sa simula): ang napiling mensahe, o ang mensahe ng Day N. */
    public function addStep(Request $request, int $id)
    {
        $this->checkAccess();
        $after = $request->input('after');
        $after = is_numeric($after) ? (int) $after : -1;
        $message = $request->filled('message_id') ? (int) $request->input('message_id') : null;

        $seen = $this->seen($request);

        return $this->steps($id, function (array $ids, array $times) use ($after, $message, $seen) {
            $seen($ids, $after - 1);
            if ($after < 0 || $after > count($ids)) throw new InvalidArgumentException('Walang ganitong step.');
            $pick = $message ?? $ids[max(0, $after - 1)];
            array_splice($ids, $after, 0, [$pick]);
            array_splice($times, $after, 0, [null]);
            return [$ids, $times];
        }, ['what' => 'add_step', 'after' => $after]);
    }

    /** Alisin ang Day N (kahit isang step ang natitira); ang mga kasunod ay umuusad. */
    public function removeStep(Request $request, int $id)
    {
        $this->checkAccess();
        $d = $this->day($request);

        $seen = $this->seen($request);

        return $this->steps($id, function (array $ids, array $times) use ($d, $seen) {
            $seen($ids, $d);
            if (!isset($ids[$d])) throw new InvalidArgumentException('Walang ganitong step.');
            array_splice($ids, $d, 1);
            array_splice($times, $d, 1);
            return [$ids, $times];
        }, ['what' => 'remove_step', 'day' => $d + 1]);
    }

    /** Ilipat ang Day N pataas o pababa (kasama ang sariling oras nito). */
    public function move(Request $request, int $id)
    {
        $this->checkAccess();
        $d = $this->day($request);
        $dir = $request->input('dir');
        if (!in_array($dir, ['up', 'down'], true)) return response()->json(['error' => 'Direksyon: up o down.'], 422);

        $seen = $this->seen($request);

        return $this->steps($id, function (array $ids, array $times) use ($d, $dir, $seen) {
            $seen($ids, $d);
            $to = $dir === 'up' ? $d - 1 : $d + 1;
            if (!isset($ids[$d]) || !isset($ids[$to])) throw new InvalidArgumentException('Hindi maililipat ang step na ito.');
            [$ids[$d], $ids[$to]] = [$ids[$to], $ids[$d]];
            [$times[$d], $times[$to]] = [$times[$to], $times[$d]];
            return [$ids, $times];
        }, ['what' => 'move', 'day' => $d + 1, 'dir' => $dir]);
    }

    /** Ang mensahe ng Day N (mula sa /chatblast/campaigns). */
    public function stepMessage(Request $request, int $id)
    {
        $this->checkAccess();
        $d = $this->day($request);
        $message = (int) $request->input('message_id');

        $seen = $this->seen($request);

        return $this->steps($id, function (array $ids, array $times) use ($d, $message, $seen, &$old) {
            $seen($ids, $d);
            if (!isset($ids[$d])) throw new InvalidArgumentException('Walang ganitong step.');
            $old = $ids[$d];
            $ids[$d] = $message;
            return [$ids, $times];
        }, ['what' => 'step_message', 'day' => $d + 1, 'old' => &$old, 'new' => $message]);
    }

    /** Ang sariling oras ng Day N (walang laman = ang mga oras ng sequence). */
    public function stepTimes(Request $request, int $id)
    {
        $this->checkAccess();
        $d = $this->day($request);
        $new = $request->input('times') ?? [];

        $seen = $this->seen($request);

        return $this->steps($id, function (array $ids, array $times) use ($d, $new, $seen) {
            $seen($ids, $d);
            if (!isset($ids[$d])) throw new InvalidArgumentException('Walang ganitong step.');
            $times[$d] = $new ?: null;
            return [$ids, $times];
        }, ['what' => 'step_times', 'day' => $d + 1, 'new' => $new]);
    }

    private function steps(int $id, callable $change, array $entry)
    {
        ChatblastCampaign::findOrFail($id);
        try {
            $changed = $this->chatblast->editSteps($id, $change, Auth::id(), $entry);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $c = ChatblastCampaign::findOrFail($id);

        return response()->json(['changed' => $changed, 'step_messages' => $c->step_messages, 'step_times' => $c->step_times]);
    }

    /**
     * Ang nakita ng user sa page: ilang step (steps) at ang mensahe ng step na binabago (expect). Kapag iba na (hal. may
     * inalis na step sa ibang tab, kaya nausog ang mga araw), hindi itinutuloy ang pagbabago. Walang laman = walang bantay.
     */
    private function seen(Request $request): callable
    {
        $count = is_numeric($request->input('steps')) ? (int) $request->input('steps') : null;
        $expect = is_numeric($request->input('expect')) ? (int) $request->input('expect') : null;

        return function (array $ids, int $index) use ($count, $expect) {
            if ($count !== null && count($ids) !== $count) throw new InvalidArgumentException(self::STALE);
            if ($expect !== null && $index >= 0 && (int) ($ids[$index] ?? 0) !== $expect) throw new InvalidArgumentException(self::STALE);
        };
    }

    private function day(Request $request): int
    {
        $day = $request->input('day');
        return is_numeric($day) ? (int) $day - 1 : -1;
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
            if (($error = Chatblast::invalidTimes(is_array($times) ? array_values($times) : $times)) !== null) return [[], 'Oras ng padala: ' . $error];
            $times = array_values(array_unique($times));
            sort($times);
            $data['send_times'] = $times;
        }
        if ($request->has('weekdays')) {
            $days = $request->input('weekdays');
            if (!is_array($days) || !$days) return [[], 'Mga araw: pumili ng kahit isa (Lun hanggang Lin).'];
            foreach ($days as $d) {
                if (!is_int($d) && !(is_string($d) && ctype_digit($d)) || (int) $d < 1 || (int) $d > 7) return [[], 'Mga araw: 1 (Lun) hanggang 7 (Lin).'];
            }
            $days = array_values(array_unique(array_map('intval', $days)));
            sort($days);
            $data['weekdays'] = count($days) === 7 ? null : $days; // lahat = araw-araw
        }
        if ($request->has('daily_cap')) {
            $cap = $request->input('daily_cap');
            if ($cap !== null && $cap !== '' && (!is_numeric($cap) || (int) $cap != $cap || $cap < 1 || $cap > self::MAX_CAP)) {
                return [[], 'Cap: 1 hanggang ' . number_format(self::MAX_CAP) . ' kada page kada araw, o walang laman.'];
            }
            $data['daily_cap'] = $cap === null || $cap === '' ? null : (int) $cap;
        }
        if ($c === null || $request->has('requires_on_delivery')) {
            $data['requires_on_delivery'] = $request->boolean('requires_on_delivery');
        }

        $statuses = $data['statuses'] ?? (array) ($c?->statuses ?? []);
        if (($data['requires_on_delivery'] ?? $c?->requires_on_delivery) && $statuses !== ['Delivering']) {
            return [[], 'Ang "Requires On Delivery" ay para lang sa trigger na Delivering.'];
        }

        return [$data, null];
    }

    /**
     * Ang mga pares ng naka-on na campaign na may parehong status at page: ang waybill ay isang mensahe lang kada araw
     * para sa parehong status, kaya ang isa sa kanila ang lalaktawan. Hal. "\"A\" at \"B\": In Transit sa Page Uno".
     * @param  array<int, ChatblastCampaign> $campaigns
     * @return array<int, string>
     */
    private function overlaps(array $campaigns): array
    {
        $out = [];
        foreach ($campaigns as $i => $a) {
            foreach (array_slice($campaigns, $i + 1) as $b) {
                $statuses = array_values(array_intersect((array) $a->statuses, (array) $b->statuses));
                if (!$statuses) continue;
                if (!$a->pages && !$b->pages) $pages = ['lahat ng page'];
                elseif (!$a->pages || !$b->pages) $pages = (array) ($a->pages ?: $b->pages);
                else {
                    $keys = array_map(fn ($p) => $this->settings->pageKey((string) $p), $a->pages);
                    $pages = array_values(array_filter($b->pages, fn ($p) => in_array($this->settings->pageKey((string) $p), $keys, true)));
                }
                if ($pages) $out[] = '"' . $a->name . '" at "' . $b->name . '": ' . implode(', ', $statuses) . ' sa ' . implode(', ', $pages);
            }
        }

        return $out;
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
            'weekdays' => $c->weekdays, 'step_times' => $c->step_times, 'daily_cap' => $c->daily_cap,
            'mode_changed_at' => $c->mode_changed_at?->timezone('Asia/Manila')->format('Y-m-d H:i'),
        ];
    }
}
