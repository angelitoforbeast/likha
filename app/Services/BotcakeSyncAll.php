<?php

namespace App\Services;

use App\Jobs\SyncAllBotcakeCustomersJob;
use App\Models\BotcakePsidImportRun;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * "Sync lahat" ng /page-settings. Ang bawat page na may botcake.page_id at token na hindi invalid ay sine-sync, isa-isa sa
 * ayos ng grid, ayon sa plan() nito (window, tuloy o by date); ang by date na sariwa ay nilalaktawan (next()).
 *
 * Ang run ay isang row sa botcake_psid_import_runs (source sync_all), hindi sa cache: hindi ito nawawala sa cache:clear o
 * deploy, at may history. page_index = ang page na susunod o kasalukuyan. current_page = may job na may hawak ng page na
 * iyon (naka-queue o tumatakbo): ang nagpapadala ng job ay kumukuha muna nito sa isang UPDATE na may kondisyong walang
 * may hawak, kaya iisang job lang ang tumatakbo kada run kahit sabay ang Resume at ang pagtatapos ng page. Pause, Resume
 * at Stop ay status lang; binabasa ito ng job bago kunin ang susunod na page, kaya laging nananalo ang Pause at Stop.
 */
class BotcakeSyncAll
{
    public const SOURCE = 'sync_all';
    public const LEGACY_STATE = 'botcake-sync-all'; // cache key ng run ng lumang code (bago ang database state)
    public const WAIT_SECONDS = 30;                 // kapag may ibang sync na may hawak ng lock: subok ulit pagkatapos nito
    public const HISTORY = 10;

    /** Ilang segundo naghihintay ang pindot sa pag-ampon na ginagawa ng poll (hal. ng ibang tab). */
    public int $adoptWait = 10;

    private ?bool $ready = null;

    public function __construct(private BotcakeCustomerSync $sync, private PageSettings $settings)
    {
    }

    /**
     * Naka-migrate na ba ang mga column ng Sync lahat. Sa ilang segundo sa pagitan ng push at ng migrate, wala pa: ang
     * pindot ay sumasagot ng not_ready at ang job ay bumabalik mamaya, kaya walang 500 at hindi nawawala ang kadena.
     */
    public function ready(): bool
    {
        return $this->ready ??= Schema::hasTable('botcake_psid_import_runs') && Schema::hasColumn('botcake_psid_import_runs', 'run_key');
    }

    /**
     * Nagsisimula ng run, o wala kapag may tumatakbo o naka-pause na (kasama ang inampong run ng lumang code).
     * @return array{status:string, sync_all:?array} status started|running
     */
    public function start(?int $userId = null): array
    {
        if (!$this->ready()) return ['status' => 'not_ready', 'sync_all' => null];

        $lock = Cache::lock(self::LEGACY_STATE . ':start', 10);
        if (!$lock->get()) return ['status' => 'running', 'sync_all' => $this->panel()];

        try {
            // Hinihintay ang pag-ampon ng poll: kung hindi, makakapagsimula ng ikalawang run sa tabi ng inaampon.
            if (!$this->adoptLegacy(false, true)) return ['status' => 'running', 'sync_all' => $this->panel()];
            $active = $this->active();
            if ($active && !$this->closeIfOrphan($active)) return ['status' => 'running', 'sync_all' => $this->panel()];

            [$pages, $skipped] = $this->plannedPages();
            $run = BotcakePsidImportRun::create([
                'source' => self::SOURCE, 'status' => $pages ? 'running' : 'done', 'run_key' => (string) Str::uuid(),
                'page_index' => 0, 'current_page' => $pages[0] ?? null, 'started_by' => $userId,
                'sync_state' => ['pages' => $pages, 'ok' => 0, 'failed' => 0, 'skipped' => $skipped, 'waiting' => false],
                'finished_at' => $pages ? null : now(), 'last_message' => 'Sync lahat',
                'customers_new' => 0, 'customers_updated' => 0, 'matched_by_name' => 0, 'matched_by_phone' => 0, 'ad_filled' => 0,
            ]);
        } finally {
            $lock->release();
        }

        if ($pages) SyncAllBotcakeCustomersJob::dispatch($run->run_key, 0);

        return ['status' => 'started', 'sync_all' => $this->panel()];
    }

    /** Pause: ang tumatakbong page ay tinatapos; walang susunod na page hangga't walang Resume. */
    public function pause(): ?array
    {
        if ($run = $this->active()) {
            BotcakePsidImportRun::whereKey($run->id)->where('status', 'running')->update(['status' => 'paused']);
        }
        return $this->panel();
    }

    /** Resume: tuloy ang parehong run mula sa susunod na page (kung walang job na may hawak na nito). */
    public function resume(): ?array
    {
        if ($run = $this->active()) {
            BotcakePsidImportRun::whereKey($run->id)->where('status', 'paused')->update(['status' => 'running']);
            $this->claimNext($run->fresh());
        }
        return $this->panel();
    }

    /** Stop: tapos na ang run (stopped); ang tumatakbong page ay tinatapos, walang susunod. Bagong pindot = bagong run. */
    public function stop(): ?array
    {
        if ($run = $this->active()) {
            BotcakePsidImportRun::whereKey($run->id)->whereIn('status', ['running', 'paused'])
                ->update(['status' => 'stopped', 'finished_at' => now()]);
        }
        return $this->panel();
    }

    public function active(): ?BotcakePsidImportRun
    {
        return BotcakePsidImportRun::where('source', self::SOURCE)->whereIn('status', ['running', 'paused'])->orderByDesc('id')->first();
    }

    /** Ang run ng job. Inaampon muna ang lumang run sa cache kung mayroon; ang bagong code na ang may hawak mula rito. */
    public function find(string $runKey): ?BotcakePsidImportRun
    {
        $this->adoptLegacy(true);
        return BotcakePsidImportRun::where('source', self::SOURCE)->where('run_key', $runKey)->first();
    }

    /**
     * Ang job ay dumating habang naka-pause o stopped ang run: bitawan ang page, para ang Resume ang magpadala ulit.
     * False = nabitawan. True = na-Resume na bago pa nabitawan (nakita ng Resume na may hawak pa, kaya wala itong
     * ipinadala): ang job pa rin ang may hawak at itutuloy nito ang page, kaya walang naiipit na run.
     */
    public function release(BotcakePsidImportRun $run, int $index): bool
    {
        $released = BotcakePsidImportRun::whereKey($run->id)->where('page_index', $index)->where('status', '<>', 'running')
            ->update(['current_page' => null]);
        $this->setWaiting($run, false);
        if ($released) return false;

        $now = $run->fresh();
        return $now->status === 'running' && (int) $now->page_index === $index && $now->current_page !== null;
    }

    public function setWaiting(BotcakePsidImportRun $run, bool $waiting): void
    {
        $state = $run->fresh()->sync_state ?? [];
        $state['waiting'] = $waiting;
        BotcakePsidImportRun::whereKey($run->id)->update(['sync_state' => json_encode($state)]);
    }

    /**
     * Tapos ang page sa $index (ok o hindi). Isang beses lang binibilang (kondisyong page_index), idinadagdag ang bilang ng
     * api run, saka kinukuha ang susunod na page kung running pa. True kapag ito ang nagbilang.
     */
    public function advance(BotcakePsidImportRun $run, int $index, ?BotcakePsidImportRun $apiRun, bool $ok): bool
    {
        $state = $run->fresh()->sync_state ?? [];
        $key = $ok ? 'ok' : 'failed';
        $state[$key] = (int) ($state[$key] ?? 0) + 1;
        $state['waiting'] = false;
        $add = fn (string $col) => DB::raw('COALESCE(' . $col . ', 0) + ' . (int) ($apiRun?->{$col} ?? 0));

        $counted = BotcakePsidImportRun::whereKey($run->id)->where('page_index', $index)->update([
            'page_index' => $index + 1, 'current_page' => null, 'sync_state' => json_encode($state),
            'customers_new' => $add('customers_new'), 'customers_updated' => $add('customers_updated'),
            'matched_by_name' => $add('matched_by_name'), 'matched_by_phone' => $add('matched_by_phone'),
            'ad_filled' => $add('ad_filled'), 'batches_read' => $add('batches_read'),
        ]);
        if (!$counted) return false;

        $this->claimNext($run->fresh());
        return true;
    }

    /**
     * Kunin ang susunod na page para sa bagong job, kung running at walang may hawak; tapusin ang run kapag wala nang page.
     * Isang UPDATE na may kondisyon: kapag sabay ang Resume at ang pagtatapos ng page, isa lang ang nagpapadala ng job.
     */
    private function claimNext(BotcakePsidImportRun $run): void
    {
        $pages = $run->sync_state['pages'] ?? [];
        $index = (int) $run->page_index;

        if ($index >= count($pages)) {
            BotcakePsidImportRun::whereKey($run->id)->whereIn('status', ['running', 'paused'])->update(['status' => 'done', 'finished_at' => now()]);
            return;
        }

        $claimed = BotcakePsidImportRun::whereKey($run->id)->where('status', 'running')->where('page_index', $index)
            ->whereNull('current_page')->update(['current_page' => $pages[$index]]);
        if ($claimed) SyncAllBotcakeCustomersJob::dispatch($run->run_key, $index);
    }

    /**
     * Ang run ng lumang code (cache, isang job kada page) ay inaampon bilang row na may parehong id, mula sa page na naabot
     * nito; may hawak na ang page na iyon (ang job ng lumang kadena), kaya walang bagong job na ipinapadala rito. Kapag ang
     * bagong code na ang nagpapatakbo ng job ($owner), binubura ang cache: ang database na ang may hawak.
     */
    /** False kapag may ibang umaampon pa (hindi nakuha ang lock sa loob ng paghihintay); true kung hindi. */
    private function adoptLegacy(bool $owner, bool $wait = false): bool
    {
        $state = Cache::get(self::LEGACY_STATE);
        if (!is_array($state) || !isset($state['id'], $state['pages'])) return true;

        // Isang pag-ampon lang sabay (ang poll, ang pindot at ang job): walang dalawang row para sa iisang lumang run.
        // Ang job at ang pindot ay naghihintay sa lock; ang poll ay lumalaktaw na lang.
        $lock = Cache::lock(self::LEGACY_STATE . ':adopt', 10);
        try {
            if (!($owner ? $lock->block(10) : ($wait ? $lock->block($this->adoptWait) : $lock->get()))) return false;
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            return false;
        }

        try {
            $this->adoptLegacyState($state, $owner);
        } catch (QueryException) {
            // Wala pa ang mga column (bago ang migrate): hindi pa maaampon; ang cache ay naiiwan para sa susunod.
        } finally {
            $lock->release();
        }

        return true;
    }

    /**
     * Ang inampong lumang run na running pa, pero wala na ang cache key nito at walang job na may hawak (hal. tapos na ang
     * huling page sa lumang code, saka nabura ang cache sa deploy): isinasara. Done kapag nasa huling page na ito; kung
     * hindi, stopped (naputol ang lumang kadena). Ang naka-pause ay binibitawan ang page, para gumana ang Resume.
     * True kapag isinara. Ang "walang job" ay nakikita lang sa database queue; sa iba, hindi ginagalaw.
     */
    private function closeIfOrphan(BotcakePsidImportRun $run): bool
    {
        if (empty($run->sync_state['legacy']) || $run->current_page === null || Cache::has(self::LEGACY_STATE)) return false;
        if (!in_array($run->status, ['running', 'paused'], true) || $this->jobOwns((string) $run->run_key)) return false;

        if ($run->status === 'paused') {
            BotcakePsidImportRun::whereKey($run->id)->where('status', 'paused')->update(['current_page' => null]);
            return false;
        }

        $total = count($run->sync_state['pages'] ?? []);
        $last  = (int) $run->page_index >= $total - 1;

        return (bool) BotcakePsidImportRun::whereKey($run->id)->where('status', 'running')->update($last
            ? ['status' => 'done', 'page_index' => $total, 'current_page' => null, 'finished_at' => now()]
            : ['status' => 'stopped', 'current_page' => null, 'finished_at' => now(), 'last_error' => 'Naputol ang lumang run: walang job at walang state sa cache']);
    }

    /** May naka-queue o tumatakbong job ba ang run (database queue lang). True kapag hindi masabi. */
    private function jobOwns(string $runKey): bool
    {
        $queue = config('queue.default');
        if (config('queue.connections.' . $queue . '.driver') !== 'database') return true;

        $table = config('queue.connections.' . $queue . '.table', 'jobs');
        if (!Schema::hasTable($table)) return true;

        return DB::table($table)->where('payload', 'like', '%' . $runKey . '%')->exists();
    }

    private function adoptLegacyState(array $state, bool $owner): void
    {
        $pages = array_values((array) $state['pages']);
        $done  = (int) ($state['done'] ?? 0);
        $sync  = [
            'pages' => $pages, 'ok' => (int) ($state['ok'] ?? 0), 'failed' => (int) ($state['failed'] ?? 0), 'skipped' => 0,
            'waiting' => (bool) ($state['waiting'] ?? false), 'legacy' => true,
        ];

        $run = BotcakePsidImportRun::where('source', self::SOURCE)->where('run_key', (string) $state['id'])->first();

        // Natapos ng lumang code ang run (hal. ang huling page ay tumatakbo pa nang inampon): isara rin ang row, kung hindi
        // ay "tumatakbo" ito habambuhay. Ang tapos nang lumang run na hindi pa naampon ay hindi na idinadagdag.
        if (($state['finished_at'] ?? null) !== null) {
            if ($run && in_array($run->status, ['running', 'paused'], true)) {
                BotcakePsidImportRun::whereKey($run->id)->update([
                    'status' => 'done', 'page_index' => count($pages), 'current_page' => null, 'sync_state' => json_encode($sync),
                    'finished_at' => Carbon::parse($state['finished_at']),
                ]);
            }
            if ($owner) Cache::forget(self::LEGACY_STATE);
            return;
        }

        if (!$run) {
            $run = BotcakePsidImportRun::create([
                'source' => self::SOURCE, 'status' => 'running', 'run_key' => (string) $state['id'], 'page_index' => $done,
                'current_page' => $pages[$done] ?? null, 'sync_state' => $sync, 'last_message' => 'Sync lahat (mula sa lumang run)',
                'customers_new' => 0, 'customers_updated' => 0, 'matched_by_name' => 0, 'matched_by_phone' => 0, 'ad_filled' => 0,
            ]);
            if (!empty($state['started_at'])) {
                DB::table('botcake_psid_import_runs')->where('id', $run->id)->update(['created_at' => Carbon::parse($state['started_at'])]);
            }
        } elseif ($done > (int) $run->page_index) {
            BotcakePsidImportRun::whereKey($run->id)->update([
                'page_index' => $done, 'current_page' => $pages[$done] ?? null, 'sync_state' => json_encode($sync),
            ]);
        }

        if ($owner) Cache::forget(self::LEGACY_STATE);
    }

    /**
     * Ang panel ng Sync lahat (ang kasalukuyan o huling run, at ang huling 10 run), para sa grid at sa poll. Walang token.
     * Hanggang tatlong query: ang mga run, ang pangalan ng nagsimula, at ang api run ng kasalukuyang page.
     */
    public function panel(): ?array
    {
        try {
            return $this->buildPanel();
        } catch (QueryException) {
            return null; // wala pa ang mga column (bago ang migrate): walang panel, hindi 500
        }
    }

    private function buildPanel(): ?array
    {
        $this->adoptLegacy(false);
        $runs = BotcakePsidImportRun::where('source', self::SOURCE)->orderByDesc('id')->limit(self::HISTORY)->get();
        if ($runs->isEmpty()) return null;
        if ($this->closeIfOrphan($runs->first())) {
            $runs = BotcakePsidImportRun::where('source', self::SOURCE)->orderByDesc('id')->limit(self::HISTORY)->get();
        }

        $ids   = $runs->pluck('started_by')->filter()->unique()->values();
        $names = $ids->isEmpty() ? collect() : User::whereIn('id', $ids)->pluck('name', 'id');
        $at    = fn ($t) => $t ? Carbon::parse($t)->timezone('Asia/Manila')->format('Y-m-d H:i') : null;

        $format = function (BotcakePsidImportRun $r) use ($names, $at) {
            $s     = $r->sync_state ?? [];
            $total = count($s['pages'] ?? []);
            $done  = min($total, (int) $r->page_index);
            return [
                'key' => $r->run_key, 'status' => $r->status,
                'started_by' => $r->started_by ? ($names[$r->started_by] ?? 'user #' . $r->started_by) : null,
                'started_at' => $at($r->created_at), 'finished_at' => $at($r->finished_at),
                'total' => $total, 'done' => $done, 'ok' => (int) ($s['ok'] ?? 0), 'failed' => (int) ($s['failed'] ?? 0),
                'skipped' => (int) ($s['skipped'] ?? 0), 'remaining' => $total - $done,
                'current' => $r->current_page, 'waiting' => (bool) ($s['waiting'] ?? false),
                'totals' => [
                    'new' => (int) $r->customers_new, 'updated' => (int) $r->customers_updated,
                    'matched' => (int) $r->matched_by_name + (int) $r->matched_by_phone, 'ad_filled' => (int) $r->ad_filled,
                ],
                'seconds' => $r->created_at ? (int) $r->created_at->diffInSeconds($r->finished_at ?? now(), true) : 0,
            ];
        };

        $panel = $format($runs->first());
        $panel['current_kind'] = $panel['current_batches'] = null;
        if ($panel['current'] && in_array($panel['status'], ['running', 'paused'], true)) {
            $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
            $api = BotcakePsidImportRun::where('source', 'api')->where('status', 'running')
                ->whereRaw("LOWER($trim(page_name)) = ?", [$this->settings->pageKey($panel['current'])])->orderByDesc('id')->first();
            if ($api) {
                $panel['current_kind'] = BotcakeCustomerSync::kindLabel($api->read_kind, $api->start_batch);
                $panel['current_batches'] = (int) $api->batches_read;
            }
        }
        $panel['history'] = $runs->map($format)->all();

        return $panel;
    }

    public const SUMMARY = [
        'window' => 'window', 'continue' => 'tuloy', 'by_date' => 'by date',
        'fresh' => 'laktaw (sariwa)', 'missing' => 'walang ID/token', 'invalid' => 'token invalid',
    ];

    /**
     * Ano ang gagawin ng susunod na sync ng bawat row (grid row: key + cells + info.botcake), para sa Sync lahat: ang
     * plan() ng sync at, sa ibabaw, ang laktaw kapag by date at may matagumpay na api run sa huling N oras
     * (services.botcake.sync_all_fresh_hours). Ang window read at ang tuloy ay hindi nilalaktawan. Dalawang query lang.
     * from = stop date ng by date ("Okt 17"), kahit "laktaw" ang label.
     * @param array<int|string, array> $rows
     * @return array<string, array{kind:string, label:string, from?:?string}> pageKey => uri, label at stop date
     */
    public function next(array $rows): array
    {
        $plans = $fresh = null;
        $hours = max(0, (float) config('services.botcake.sync_all_fresh_hours', 6));
        $days  = max(1, (int) config('services.botcake.sync_max_age_days', 45));
        $out   = [];

        foreach ($rows as $row) {
            $pk = $row['key'];
            if (!($row['info']['botcake'] ?? false)) { $out[$pk] = ['kind' => 'missing', 'label' => 'walang ID/token']; continue; }
            if (str_starts_with((string) ($row['cells']['botcake.token_status']['value'] ?? ''), 'invalid')) {
                $out[$pk] = ['kind' => 'invalid', 'label' => 'token invalid'];
                continue;
            }

            $plans ??= $this->sync->plans();
            [$kind, $start, $stop] = ($plans[$pk] ?? ['window', 1]) + [2 => null];
            $from = BotcakeCustomerSync::shortDate($stop); // stop date ng by date, para sa "susunod: mula …"
            if ($start > 1) {
                $out[$pk] = $kind === 'by_date' ? ['kind' => 'continue', 'label' => 'tuloy mula batch ' . $start . ' (by date, mula ' . $from . ')', 'from' => $from]
                    : ['kind' => 'continue', 'label' => 'tuloy mula batch ' . $start];
                continue;
            }
            if ($kind === 'window') {
                $out[$pk] = ['kind' => 'window', 'label' => 'window (' . $days . ' araw, buo)'];
                continue;
            }

            $fresh ??= $this->sync->lastDoneAt();
            $at = $fresh[$pk] ?? null;
            if ($at && $at->gt(now()->subMinutes((int) round($hours * 60)))) {
                $mins = (int) $at->diffInMinutes(now(), true);
                $out[$pk] = ['kind' => 'fresh', 'label' => 'laktaw (sariwa, ' . ($mins < 60 ? $mins . ' min' : intdiv($mins, 60) . ' oras') . ' na)', 'from' => $from];
                continue;
            }
            $out[$pk] = ['kind' => 'by_date', 'label' => 'by date, mula ' . $from, 'from' => $from];
        }

        return $out;
    }

    /** Ang isang linya ng plano ng Sync lahat, hal. "12 window, 3 tuloy, 20 by date, 6 laktaw (sariwa)". */
    public static function summary(array $next): string
    {
        $count = array_count_values(array_column($next, 'kind'));
        $parts = [];
        foreach (self::SUMMARY as $kind => $label) {
            if (!empty($count[$kind])) $parts[] = $count[$kind] . ' ' . $label;
        }

        return implode(', ', $parts);
    }

    /**
     * Ang mga page na isi-sync ng bagong run (baybay sa settings, ayos ng grid) at ilan ang nilaktawan dahil sariwa.
     * @return array{0: array<int, string>, 1: int}
     */
    private function plannedPages(): array
    {
        $botcake = collect($this->sync->pages())->keyBy(fn ($p) => $this->settings->pageKey($p));
        // Ang grid ay kinukuha rito, hindi sa constructor: ginagamit din ng grid ang klaseng ito.
        $rows = collect(app(PageSettingsGrid::class)->build()['rows']);

        $pages = $rows->filter(fn ($r) => isset($botcake[$r['key']])
                && in_array($r['info']['next']['kind'] ?? null, ['window', 'continue', 'by_date'], true))
            ->map(fn ($r) => $botcake[$r['key']])->values()->all();

        return [$pages, $rows->where('info.next.kind', 'fresh')->count()];
    }
}
