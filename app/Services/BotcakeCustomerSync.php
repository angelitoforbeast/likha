<?php

namespace App\Services;

use App\Models\BotcakePsid;
use App\Models\BotcakePsidImportRun;
use App\Support\BotcakeClock;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Botcake customer list → botcake_psids (source api, status available; isang row kada page + psid), saka matching.
 * Full: hanggang walang laman; pagkatapos ng malinis na basa, ang hindi nakita nang 30 araw ay inactive (hindi binubura).
 * Window: lahat ng sync ay humihinto sa unang batch na may customer na naka-subscribe bago ang huling N araw
 * (services.botcake.sync_max_age_days, default 45; stop reason age). Pinakabagong subscriber muna ang listahan, kaya
 * mas luma na ang lahat ng kasunod. Ang inactive ay para lang sa mga naka-subscribe sa loob ng window.
 * Window read (walang cap at walang known-batch stop): kapag ang page ay wala pang run na umabot sa age o end, kapag cap
 * ang huling run, o kapag ang huling run ay window read na hindi natapos (oras, error, o pinatay): ang huli ay itinutuloy
 * mula sa naabot nitong batch, minus RESUME_OVERLAP (kapag may nawala sa unahan, umaakyat ang listahan at malalaktawan sana
 * ang ilan; ang bagong subscriber naman ay nagtutulak pababa, kaya inuulit lang). Humihinto ito pagkatapos ng
 * WINDOW_SECONDS (stop reason time). Dalawang sunod na window read na walang nabasa mula sa parehong simula: mula batch 1
 * ulit, para hindi maipit sa batch na laging pumapalya.
 * By date (kapalit ng incremental, kapag tapos na ang window): mula batch 1 hanggang sa unang batch na lahat ng customer ay
 * naka-subscribe bago ang stop date (stop reason date), o age / end. Stop date = ang pinakabagong petsang nabasa ng huling
 * malinis na run mula batch 1 (by date, window o full; date / age / end, walang error), minus 1 araw: petsa lang ang
 * date_subscribed_at, at sa loob ng isang petsa ay ayon sa huling chat ang ayos, kaya inuulit ang huling araw. Hindi ito
 * humihinto sa batch na kilala na lahat: sa ilalim nito ay maaaring may bagong subscriber. Hindi lalampas sa window.
 * Walang malinis na run na may petsa: window read. Ang failed na run ay hindi gumagalaw sa stop date (catch-up).
 * Humihinto rin ito pagkatapos ng WINDOW_SECONDS (time) at itinutuloy gaya ng window read, may parehong stop date.
 * Chain: ang mga run mula batch 1 hanggang sa huling tuloy. Bawat tuloy ay may dalang pinakabagong petsa ng unang run, kaya
 * ang chain na natapos nang malinis (date / age / end) ay malinis, at iyon ang petsa nito. Ang lumang run (bago naitala ang
 * petsa) na malinis na window o full mula batch 1: ang petsa ng created_at nito (Manila) ang ginagamit; hindi ito isinusulat.
 * All time (spec 262, --all-time): mula batch 1 hanggang dulo (end), walang age stop at walang cap. Humihinto pagkatapos
 * ng ALL_TIME_SECONDS (time) para hindi lumampas sa LOCK_TTL ang hawak, at agad itong itinutuloy ng command. Ang
 * hindi natapos (time, error, pinatay) ay itinutuloy mula sa naabot, minus RESUME_OVERLAP; hindi bumabalik sa batch 1
 * habang hindi tapos ang chain. Walang all-time run pa: itinutuloy ang huling --full mula batch 1 na hindi natapos (hal.
 * ang one-off na pinatay). Tapos = ang huling all-time run ay done / end. Inactive lang kapag ang chain ay mula batch 1
 * hanggang dulo: ang hindi nakita mula nang magsimula ang chain at nang 30 araw. Hindi ito nagpapasya ng plan() ng
 * ibang basa.
 * Isang lock kada page: sabay ang magkaibang page, hindi kailanman ang parehong page. Bawat sync ay isang run na may
 * stop_reason, read_kind, start_batch at mga petsa.
 */
class BotcakeCustomerSync
{
    public const LOCK = 'botcake-sync-customers'; // + ':' + pageKey: isang lock kada page
    public const MAX_BATCHES = 20000; // pananggalang ng --full kung paulit-ulit ang sagot ng API
    public const ALL_TIME = 'all_time';
    // All-time run: titigil (time) pagkatapos nito at itutuloy agad, para ang hawak ng lock ay laging kulang sa LOCK_TTL.
    public const ALL_TIME_SECONDS = 60 * 60;
    public const INACTIVE_DAYS = 30;
    public const LOCK_TTL = 2 * 3600; // kada page; ang run na pinatay (hindi nakapag-release) ay hindi humaharang nang higit dito
    // Window read: titigil (time) pagkatapos nito. Sinusuri sa pagitan ng mga batch; ang isang batch ay hanggang ~5 min
    // (4 na subok × 30 s + backoff at Retry-After), saka ang matching: kasya pa rin sa 1800 s na --timeout ng worker.
    public const WINDOW_SECONDS = 22 * 60;
    public const RESUME_OVERLAP = 5;       // batch na inuulit kapag itinutuloy ang window read
    private const DECIDING_STOPS = ['cap', 'time', 'age', 'end', 'date']; // ang mga run na nagpapasya sa plan(), kasama ang window read

    private ?bool $hasAdFilled = null;

    /** Ang cap ng ibang basa (hindi ng all time); napapalitan sa tests. */
    public int $maxBatches = self::MAX_BATCHES;

    public function __construct(
        private PageSettings $settings,
        private BotcakeCustomerMatcher $matcher,
        private BotcakeClock $clock,
    ) {
    }

    /** @return array<int, string> mga page na may page id at token */
    public function pages(): array
    {
        return $this->settings->pagesWith('botcake', ['page_id', 'token']);
    }

    /** Ang lock ng isang page (ayon sa pageKey, kaya iisa ang "Page Uno" at " page uno "). */
    public function lockKey(string $page): string
    {
        return self::LOCK . ':' . $this->settings->pageKey($page);
    }

    /**
     * Patakbuhin habang hawak ang lock ng page (isa lang sabay kada page: schedule, command, button at Sync lahat; ang
     * magkaibang page ay sabay). Null = may tumatakbo na sa page na ito.
     */
    public function withLock(string $page, callable $fn): mixed
    {
        $key  = $this->lockKey($page);
        $lock = Cache::lock($key, self::LOCK_TTL);
        if (!$lock->get()) return null;

        Cache::put($key . ':since', now()->toIso8601String(), self::LOCK_TTL);
        $this->held($key, true);
        try {
            return $fn();
        } finally {
            $lock->release();
            Cache::forget($key . ':since');
            $this->held($key, false);
        }
    }

    /**
     * Kailan nagsimula ang sync na may hawak ng lock ng page, o null kapag walang tumatakbo. Walang page: ang
     * pinakamaagang hawak sa lahat ng page (para sa grid), mula sa listahan ng mga hawak (walang database query).
     */
    public function lockedSince(?string $page = null): ?Carbon
    {
        $keys = $page === null ? array_keys((array) Cache::get(self::LOCK . ':held', [])) : [$this->lockKey($page)];
        if (!$keys) return null;

        // Ang ":since" ang totoo (may TTL gaya ng lock); ang listahan ay mga kandidato lang (hal. pinatay na proseso).
        $since = array_filter(Cache::many(array_map(fn ($k) => $k . ':since', $keys)), 'is_string');
        if (!$since) return null;

        return collect($since)->map(fn ($s) => Carbon::parse($s))->min()->setTimezone(config('app.timezone'));
    }

    /** Para sa SIGTERM / SIGINT ng command: bitawan ang lock ng page kahit hindi natapos ang run. */
    public function forceRelease(string $page): void
    {
        $key = $this->lockKey($page);
        Cache::lock($key)->forceRelease();
        Cache::forget($key . ':since');
        $this->held($key, false);
    }

    /** Ang listahan ng mga hawak na lock (para sa lockedSince() ng grid). Pang-display lang: hindi pumapalya ang sync dito. */
    private function held(string $key, bool $on): void
    {
        try {
            Cache::lock(self::LOCK . ':held-lock', 10)->block(5, function () use ($key, $on) {
                $held = (array) Cache::get(self::LOCK . ':held', []);
                if ($on) $held[$key] = true;
                else unset($held[$key]);
                $held ? Cache::put(self::LOCK . ':held', $held, self::LOCK_TTL) : Cache::forget(self::LOCK . ':held');
            });
        } catch (\Throwable $e) {
            Log::warning('BotcakeCustomerSync: hindi naisulat ang listahan ng lock', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Anong basa ang susunod na sync ng page, mula saang batch, at ang stop date ng by date. Mula sa mga naunang api run.
     * @return array{0:string, 1:int, 2?:?string, 3?:?string} [by_date|window|full, unang batch, stop date (Y-m-d) ng by_date,
     *   pinakabagong petsa ng chain kapag tuloy]
     */
    public function plan(string $page, bool $full = false): array
    {
        if ($full) return ['full', 1];

        // Ang nagpapasya: ang pinakabagong run na cap / time / age / end / date, o window / by date read na hindi natapos
        // (failed, pinatay, rate limit). Ang ibang run sa pagitan (known_batch, failed na --full) ay hindi binibilang,
        // para hindi maging by date habambuhay ang page na may customer pa sa window na hindi nababasa.
        [$last, $before] = array_pad($this->runsOf($page)->where(fn ($w) => $this->notAllTime($w))
            ->where(fn ($w) => $w->whereIn('stop_reason', self::DECIDING_STOPS)->orWhereIn('read_kind', ['window', 'by_date']))
            ->orderByDesc('id')->limit(2)->get()->all(), 2, null);

        return $this->byDate($this->decide($last, $before),
            $this->cleanDate($this->runsOf($page)->where(fn ($w) => $this->clean($w))->orderByDesc('id')->first(['subscribed_newest', 'created_at'])));
    }

    /**
     * Ang plano ng bawat page na may api run, sa isang query (ROW_NUMBER kada page: ang dalawang pinakabagong run na
     * nagpapasya). Ang page na wala rito ay wala pang ganoong run: window read mula batch 1.
     * Ang huling malinis na petsa ng bawat page ay isa pang query (ROW_NUMBER din).
     * @return array<string, array{0:string, 1:int, 2?:string}> pageKey => [by_date|window, unang batch, stop date]
     */
    public function plans(): array
    {
        $trim  = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        $inner = DB::table('botcake_psid_import_runs')->where('source', 'api')->whereNotNull('page_name')
            ->where(fn ($w) => $this->notAllTime($w))
            ->where(fn ($w) => $w->whereIn('stop_reason', self::DECIDING_STOPS)->orWhereIn('read_kind', ['window', 'by_date']))
            ->selectRaw("id, page_name, status, stop_reason, read_kind, start_batch, batches_read, stop_date, subscribed_newest, ROW_NUMBER() OVER (PARTITION BY LOWER($trim(page_name)) ORDER BY id DESC) AS rn");
        $byPage = DB::query()->fromSub($inner, 'r')->where('rn', '<=', 2)->orderByDesc('id')->get()
            ->groupBy(fn ($r) => $this->settings->pageKey((string) $r->page_name));

        $cleanInner = DB::table('botcake_psid_import_runs')->where('source', 'api')->whereNotNull('page_name')
            ->where(fn ($w) => $this->clean($w))
            ->selectRaw("page_name, subscribed_newest, created_at, ROW_NUMBER() OVER (PARTITION BY LOWER($trim(page_name)) ORDER BY id DESC) AS rn");
        $clean = DB::query()->fromSub($cleanInner, 'c')->where('rn', 1)->get()
            ->mapWithKeys(fn ($r) => [$this->settings->pageKey((string) $r->page_name) => $this->cleanDate($r)]);

        return $byPage->map(fn ($runs, $pk) => $this->byDate($this->decide($runs->get(0), $runs->get(1)), $clean[$pk] ?? null))->all();
    }

    /**
     * Kailan natapos ang huling malinis na api run (o dulo ng malinis na chain) ng bawat page, sa isang grouped query.
     * Ang done na putol (time, cap, known_batch) ay hindi binibilang: hindi pa nito nababasa ang lahat hanggang stop date.
     * @return array<string, Carbon> pageKey => oras
     */
    public function lastDoneAt(): array
    {
        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        $out  = [];
        BotcakePsidImportRun::where('source', 'api')->where(fn ($w) => $this->clean($w))->whereNotNull('page_name')
            ->selectRaw("LOWER($trim(page_name)) AS pk, MAX(updated_at) AS at")->groupByRaw("LOWER($trim(page_name))")->get()
            ->each(function ($r) use (&$out) {
                $pk = $this->settings->pageKey((string) $r->pk);
                $at = Carbon::parse($r->at);
                if (!isset($out[$pk]) || $at->gt($out[$pk])) $out[$pk] = $at;
            });

        return $out;
    }

    /**
     * Mula sa nagpapasyang run at sa nauna rito (Eloquent o plain row). Ang tuloy ay may dalang stop date (by date) at ang
     * pinakabagong petsa ng chain.
     * @return array{0:string, 1:int, 2?:?string, 3?:?string}
     */
    private function decide(?object $last, ?object $before): array
    {
        if (!$last) return ['window', 1]; // hindi pa nakababasa ng buong window
        if (in_array($last->stop_reason, ['age', 'end', 'date'], true)) return ['incremental', 1]; // byDate() ang nagpapasya
        $kind = $last->read_kind;
        if (!in_array($kind, ['window', 'by_date'], true)) return ['window', 1]; // cap ng incremental (o lumang run na walang kind)

        // Hindi natapos na window / by date read: ituloy mula sa naabot nito, minus ang overlap.
        $start = (int) ($last->start_batch ?: 1);
        if ((int) $last->batches_read > 0) {
            $next = max(1, $start + (int) $last->batches_read - 1 - self::RESUME_OVERLAP);
        } else {
            // Walang nabasa: parehong simula ulit. Dalawang sunod na walang nabasa mula sa parehong simula: mula batch 1, para
            // hindi maipit sa batch na laging pumapalya.
            $stuck = $before && $before->read_kind === $kind && (int) $before->batches_read === 0
                && (int) ($before->start_batch ?: 1) === $start && $start > 1;
            $next = $stuck ? 1 : $start;
        }

        // Mula batch 1: bagong chain (ang by date ay may bagong stop date mula sa byDate()).
        if ($next === 1) return [$kind === 'by_date' ? 'incremental' : 'window', 1];
        return [$kind, $next, $kind === 'by_date' ? $this->ymd($last->stop_date) : null, $this->ymd($last->subscribed_newest)];
    }

    /** Ang all-time run ay hindi nagpapasya ng ibang basa (ang lumang run na walang read_kind ay kasama pa rin). */
    private function notAllTime($q)
    {
        return $q->whereNull('read_kind')->orWhere('read_kind', '!=', self::ALL_TIME);
    }

    /** Tapos na ba ang all-time read ng page: ang huling all-time run nito ay done / end. */
    public function allTimeFinished(string $page): bool
    {
        $last = $this->runsOf($page)->where('read_kind', self::ALL_TIME)->orderByDesc('id')->first(['status', 'stop_reason']);
        return $last && $last->status === 'done' && $last->stop_reason === 'end';
    }

    /**
     * Saang batch magsisimula ang all-time run. Tapos na ang chain (o wala pa): mula 1 (ang command ang lumalaktaw sa tapos
     * na, maliban sa --again). Hindi tapos: mula sa naabot ng huling run, minus RESUME_OVERLAP; walang nabasa = parehong
     * simula (hindi bumabalik sa 1). Ang tuloy pagkatapos ng time ay laging umuusad nang kahit isang batch. Wala pang
     * all-time run: ang huling --full mula batch 1 na hindi natapos (hindi end / age) ay itinutuloy sa parehong paraan.
     */
    private function planAllTime(string $page): array
    {
        $last = $this->runsOf($page)->where('read_kind', self::ALL_TIME)->orderByDesc('id')->first();
        if ($last && !($last->status === 'done' && $last->stop_reason === 'end')) {
            $start = (int) ($last->start_batch ?: 1);
            $read  = (int) $last->batches_read;
            if ($read === 0) return [self::ALL_TIME, $start];
            $next = $start + $read - 1 - self::RESUME_OVERLAP;
            return [self::ALL_TIME, max($last->stop_reason === 'time' ? $start + 1 : 1, $next)];
        }
        if ($last) return [self::ALL_TIME, 1];

        $full = $this->runsOf($page)->where('read_kind', 'full')->orderByDesc('id')->first();
        if ($full && (int) $full->start_batch === 1 && (int) $full->batches_read > 0
            && !($full->status === 'done' && in_array($full->stop_reason, ['end', 'age'], true))) {
            return [self::ALL_TIME, max(1, (int) $full->batches_read - self::RESUME_OVERLAP)];
        }

        return [self::ALL_TIME, 1];
    }

    /**
     * Ang chain ng all-time run: ang mga all-time run ng page mula sa huling natapos (done / end) bago nito. Mula batch 1
     * ba ito, at kailan ito nagsimula (para sa inactive).
     * @return array{0:bool, 1:Carbon}
     */
    private function allTimeChain(string $page, BotcakePsidImportRun $run): array
    {
        $runs = $this->runsOf($page)->where('read_kind', self::ALL_TIME);
        $prevEnd = (clone $runs)->where('id', '<', $run->id)->where('status', 'done')->where('stop_reason', 'end')->max('id') ?? 0;
        $first = (clone $runs)->where('id', '>', $prevEnd)->orderBy('id')->first(['start_batch', 'created_at']);

        return [(int) $first->start_batch === 1, $first->created_at];
    }

    /**
     * Malinis: natapos hanggang sa stop date nito (date / age / end), walang error, may petsa (dala ng chain kapag tuloy).
     * Ang lumang malinis na window o full mula batch 1 na walang petsa ay kasama rin (tingnan ang cleanDate()).
     */
    private function clean($q)
    {
        return $q->where('status', 'done')->whereIn('stop_reason', ['date', 'age', 'end'])->whereIn('read_kind', ['by_date', 'window', 'full'])
            ->where(fn ($w) => $w->whereNotNull('subscribed_newest')
                ->orWhere(fn ($old) => $old->where('start_batch', 1)->whereIn('stop_reason', ['age', 'end'])->whereIn('read_kind', ['window', 'full'])));
    }

    /** Huling malinis na petsa mula sa malinis na run: ang naitalang petsa, o (lumang run) ang petsa ng created_at sa Manila. */
    private function cleanDate(?object $run): ?string
    {
        if (!$run) return null;
        return $this->ymd($run->subscribed_newest) ?? Carbon::parse($run->created_at)->timezone('Asia/Manila')->toDateString();
    }

    private function ymd(mixed $date): ?string
    {
        return $date ? substr((string) $date, 0, 10) : null;
    }

    /**
     * Ang incremental ng decide() ay by date na: stop date = huling malinis na petsa minus 1 araw, hindi lalampas sa window.
     * Walang malinis na petsa (hal. mga run bago naitala ang petsa): window read mula batch 1.
     */
    private function byDate(array $plan, mixed $cleanDate): array
    {
        if ($plan[0] !== 'incremental') return $plan;
        if (!$cleanDate) return ['window', 1];

        $days  = max(1, (int) config('services.botcake.sync_max_age_days', 45));
        $stop  = Carbon::parse(substr((string) $cleanDate, 0, 10), 'Asia/Manila')->subDay()->toDateString();
        $floor = now('Asia/Manila')->subDays($days)->toDateString();

        return ['by_date', 1, max($stop, $floor)];
    }

    private function runsOf(string $page)
    {
        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        return BotcakePsidImportRun::where('source', 'api')->whereRaw("LOWER($trim(page_name)) = ?", [$this->settings->pageKey($page)]);
    }

    /**
     * Para sa failed() ng mga sync job (hal. pinatay ng worker sa timeout): ang run na "running" pa ng page ay failed na,
     * stop reason killed, para hindi ito "tumatakbo" habambuhay sa grid. Itinutuloy pa rin ng plan() mula sa naabot nito.
     */
    public function markKilled(string $page): void
    {
        $this->runsOf($page)->where('status', 'running')->update([
            'status' => 'failed', 'stop_reason' => 'killed', 'last_error' => 'The sync was killed before it finished (e.g. a worker timeout)',
        ]);
    }

    /** Para sa run message at sa "Huling sync" ng grid: by date / window / window, tuloy mula batch N / full. */
    public static function kindLabel(?string $kind, ?int $start): ?string
    {
        if ($kind === 'by_date') return $start > 1 ? 'by date, resumed from batch ' . $start : 'by date';
        if ($kind === 'window' && $start > 1) return 'window, resumed from batch ' . $start;
        if ($kind === self::ALL_TIME) return $start > 1 ? 'all time, resumed from batch ' . $start : 'all time';
        return $kind;
    }

    /** Petsa (Y-m-d) bilang "Okt 17", para sa grid at sa Sync lahat. Null kapag walang petsa. */
    public static function shortDate(mixed $date): ?string
    {
        if (!$date) return null;
        $d = Carbon::parse(substr((string) $date, 0, 10));
        return ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][$d->month - 1] . ' ' . $d->day;
    }

    /**
     * $allTime: ang all-time read (spec 262); tawagin habang hawak ang lock ng page, kaya ang naiwang "running" na run ng
     * page ay patay na (killed). $sleepMs: hinihintay sa pagitan ng mga batch.
     */
    public function syncPage(string $page, bool $full = false, bool $allTime = false, int $sleepMs = 0): BotcakePsidImportRun
    {
        $started = $this->clock->now();
        if ($allTime) $this->markKilled($page);
        [$kind, $start, $stopDate, $chainNewest] = ($allTime ? $this->planAllTime($page) : $this->plan($page, $full)) + [2 => null, 3 => null];
        $run = BotcakePsidImportRun::create([
            'status' => 'running', 'source' => 'api', 'page_name' => $page, 'read_kind' => $kind, 'start_batch' => $start,
            'stop_date' => $stopDate, 'subscribed_newest' => $chainNewest, // tuloy: dala ang pinakabagong petsa ng chain
            'last_message' => ucfirst(self::kindLabel($kind, $start)) . ' sync',
        ]);

        $pageId = $this->settings->get($page, 'botcake', 'page_id');
        $token  = $this->settings->get($page, 'botcake', 'token');
        $error  = null;
        $reason = null;
        $inactive = 0;
        $oldest = now()->subDays(max(1, (int) config('services.botcake.sync_max_age_days', 45)));

        if (!$pageId || !$token) {
            $error = 'The page has no botcake.page_id or botcake.token';
            $reason = 'error';
        } else {
            $client = app(BotcakeClient::class); // bago kada page: sariling rate at retry
            try {
                for ($n = $start; $reason === null; $n++) {
                    $batch = $client->customers($pageId, $token, $n);
                    $run->batches_read++;
                    if (!$batch) {
                        $reason = 'end';
                        break;
                    }

                    $this->store($page, $batch, $run, (string) $pageId);
                    $run->save();
                    if ($kind === self::ALL_TIME) {
                        // Walang age stop at walang cap: hanggang dulo, hinahati lang sa oras.
                        if ($this->clock->now() - $started >= self::ALL_TIME_SECONDS) $reason = 'time';
                    }
                    elseif ($this->reachesBefore($batch, $oldest)) $reason = 'age';
                    elseif ($kind === 'by_date' && $this->allBefore($batch, $stopDate)) $reason = 'date';
                    elseif (in_array($kind, ['window', 'by_date'], true) && $this->clock->now() - $started >= self::WINDOW_SECONDS) $reason = 'time';
                    elseif ($run->batches_read >= $this->maxBatches) $reason = 'cap';
                    if ($reason === null && $sleepMs > 0) $this->clock->sleep($sleepMs / 1000);
                }
                $this->tokenStatus($page, 'ok');

                // Buong basa mula batch 1 hanggang dulo (o hanggang sa window) nang walang error, sa iisang run: nakita ang
                // lahat ng kasalukuyang customer na naka-subscribe sa loob ng window. Ang mas luma ay hindi binabasa, kaya
                // hindi ginagalaw. Ang itinuloy o putol na basa ay hindi nakakita sa lahat: walang inactive.
                if (in_array($kind, ['window', 'full'], true) && $start === 1 && in_array($reason, ['end', 'age'], true)) {
                    $inactive = BotcakePsid::updateOfPage(fn () => BotcakePsid::where('source', 'api')->where('status', 'available')
                        ->where('subscribed_at', '>=', $oldest)
                        ->where(fn ($w) => $w->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subDays(self::INACTIVE_DAYS))),
                        (string) $pageId, fn ($q) => $q->where('page', $page), ['status' => 'inactive', 'last_checked_at' => now()]);
                }

                // All time: ang chain mula batch 1 na umabot sa dulo ay nakita ang lahat ng customer (anumang petsa, kahit
                // walang petsa). Inactive ang hindi nakita mula nang magsimula ang chain at nang 30 araw.
                if ($kind === self::ALL_TIME && $reason === 'end') {
                    [$fromOne, $chainStart] = $this->allTimeChain($page, $run);
                    if ($fromOne) {
                        $cutoff = now()->subDays(self::INACTIVE_DAYS)->min($chainStart);
                        $inactive = BotcakePsid::updateOfPage(fn () => BotcakePsid::where('source', 'api')->where('status', 'available')
                            ->where(fn ($w) => $w->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $cutoff)),
                            (string) $pageId, fn ($q) => $q->where('page', $page), ['status' => 'inactive', 'last_checked_at' => now()]);
                    }
                }
            } catch (BotcakeException $e) {
                $error = $e->getMessage();
                $reason = $e->reason;
                if ($e->auth) $this->tokenStatus($page, 'invalid');
            }
        }

        $m = $this->matcher->matchPage($page);

        $run->fill([
            'status'           => $error ? 'failed' : 'done',
            'last_error'       => $error,
            'stop_reason'      => $reason,
            'seconds'          => (int) round($this->clock->now() - $started),
            'matched_by_name'  => $m['by_name'],
            'matched_by_phone' => $m['by_phone'],
            'ambiguous'        => $m['ambiguous'],
            'no_match'         => $m['no_match'],
            'last_message'     => sprintf('%s: %s, %d batch (%s), %d bago, %d na-update, %d inactive; match %d pangalan, %d phone, %d ambiguous, %d wala, %d ad id',
                $page, self::kindLabel($kind, $start), $run->batches_read, $reason, $run->customers_new, $run->customers_updated, $inactive, $m['by_name'], $m['by_phone'], $m['ambiguous'], $m['no_match'], $m['ad_filled']),
        ]);
        // ad_filled: bago ang migrate ng spec 045 ay wala pa ang column; ang sync ay hindi dapat pumalya dahil dito.
        if ($this->hasAdFilled ??= Schema::hasColumn('botcake_psid_import_runs', 'ad_filled')) $run->ad_filled = $m['ad_filled'];
        $run->save();

        Log::info('BotcakeCustomerSync: ' . $run->last_message, ['run_id' => $run->id, 'error' => $error]);

        return $run;
    }

    /** May customer ba sa batch na naka-subscribe bago ang $oldest (walang petsa = hindi alam, hindi binibilang). */
    private function reachesBefore(array $batch, Carbon $oldest): bool
    {
        foreach ($batch as $c) {
            $at = $this->time($c['date_subscribed_at'] ?? $c['subscribed_at'] ?? $c['inserted_at'] ?? null);
            if ($at && $at->lt($oldest)) return true;
        }
        return false;
    }

    /** Lahat ba ng customer ng batch ay naka-subscribe bago ang $date (Y-m-d). Walang petsa = hindi alam: hindi humihinto. */
    private function allBefore(array $batch, ?string $date): bool
    {
        if (!$date) return false;
        foreach ($batch as $c) {
            $day = $this->day($c);
            if (!$day || $day >= $date) return false;
        }
        return true;
    }

    /** Petsa ng pag-subscribe ng customer (Y-m-d, oras ng app), o null. */
    private function day(array $c): ?string
    {
        return $this->time($c['date_subscribed_at'] ?? $c['subscribed_at'] ?? $c['inserted_at'] ?? null)?->toDateString();
    }

    private function store(string $page, array $batch, BotcakePsidImportRun $run, string $pageId): void
    {
        $psids = [];
        foreach ($batch as $c) {
            // Pinakabago at pinakalumang petsang nabasa ng run: dito kinukuha ang stop date ng susunod na by date read.
            if ($day = $this->day($c)) {
                if (!$run->subscribed_newest || $day > $run->subscribed_newest->toDateString()) $run->subscribed_newest = $day;
                if (!$run->subscribed_oldest || $day < $run->subscribed_oldest->toDateString()) $run->subscribed_oldest = $day;
            }
            $psid = trim((string) ($c['psid'] ?? ''));
            if ($psid !== '' && strlen($psid) <= 64) $psids[$psid] = $c;
        }
        if (!$psids) return;

        // Ayon sa page id muna: ang page na pinalitan ng pangalan ay nag-a-update ng lumang row nito, hindi nagdodoble.
        $existing = BotcakePsid::where('source', 'api')->ofPage($pageId, fn ($q) => $q->where('page', $page))
            ->whereIn('psid', array_keys($psids))->get()->keyBy('psid');

        foreach ($psids as $psid => $c) {
            $attrs = [
                'fb_name'             => mb_substr(preg_replace('/\s+/u', ' ', trim((string) ($c['full_name'] ?? ''))), 0, 255),
                'phone_numbers'       => array_values(array_filter(array_map(fn ($p) => is_scalar($p) ? (string) $p : '', (array) ($c['phone_numbers'] ?? $c['phone_number'] ?? [])))) ?: null, // walang phone = null, para hindi "nagbago" kada sync
                'botcake_customer_id' => isset($c['id']) && is_scalar($c['id']) ? mb_substr((string) $c['id'], 0, 64) : null,
                'subscribed_at'       => $this->time($c['date_subscribed_at'] ?? $c['subscribed_at'] ?? $c['inserted_at'] ?? null),
                'last_interactive_at' => $this->time($c['last_interactive_at'] ?? null, 'UTC'), // UTC sa Botcake
                // Ang ad na pinanggalingan; napapalitan kada sync (maaaring madagdagan ang listahan).
                'ad_id'               => isset($c['ad_id']) && is_scalar($c['ad_id']) && (string) $c['ad_id'] !== '' ? mb_substr((string) $c['ad_id'], 0, 64) : null,
                'ad_ids'              => is_array($c['ad_ids'] ?? null) ? array_values(array_map('strval', array_filter($c['ad_ids'], 'is_scalar'))) : null,
            ];
            $seen = ['last_seen_at' => now(), 'last_checked_at' => now()];

            $rec = $existing[$psid] ?? null;
            if (!$rec) {
                BotcakePsid::create($attrs + $seen + BotcakePsid::pageIdAttr($pageId) + [
                    'page' => $page, 'psid' => $psid, 'source' => 'api', 'status' => 'available', 'first_seen_at' => now(),
                ]);
                $run->customers_new++;
                continue;
            }

            $rec->fill($attrs + $seen + ['status' => 'available']); // inactive na nakita ulit → available
            if (trim((string) $rec->page_id) === '') $rec->fill(BotcakePsid::pageIdAttr($pageId)); // ang page ay hindi pinapalitan
            if ($rec->isDirty(['fb_name', 'phone_numbers', 'status'])) {
                $run->customers_updated++;
            }
            $rec->save();
        }

    }

    /** $tz = timezone ng text kapag walang offset; ibinabalik sa timezone ng app para tama ang naitatala. */
    private function time(mixed $v, ?string $tz = null): ?Carbon
    {
        if (!is_string($v) || $v === '') return null;
        try {
            return Carbon::parse($v, $tz)->setTimezone(config('app.timezone'));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** botcake.token_status: isinusulat lang kapag nagbago ang ok / invalid, para hindi dumami ang history kada oras. */
    public function tokenStatus(string $page, string $status, bool $always = false): void
    {
        $current = (string) $this->settings->get($page, 'botcake', 'token_status');
        if (!$always && str_starts_with($current, $status . ' ')) return;

        $this->settings->set($page, 'botcake', 'token_status', $status . ' ' . now('Asia/Manila')->format('Y-m-d H:i'), false, null, true);
    }
}
