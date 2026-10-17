<?php

namespace App\Services;

use App\Models\BotcakePsid;
use App\Models\BotcakePsidImportRun;
use App\Support\BotcakeClock;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Botcake customer list → botcake_psids (source api, status available; isang row kada page + psid), saka matching.
 * Incremental: page 1, 2, … hanggang sa unang batch na naitala na lahat ang psid (pinakabago muna ang listahan),
 * walang laman, o ang cap (services.botcake.sync_max_batches); ang susunod na run ay mula page 1 ulit.
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
 * Isang page sa isang pagkakataon; bawat sync ay isang run na may stop_reason, read_kind at start_batch.
 */
class BotcakeCustomerSync
{
    public const LOCK = 'botcake-sync-customers';
    public const MAX_BATCHES = 20000; // pananggalang ng --full kung paulit-ulit ang sagot ng API
    public const INACTIVE_DAYS = 30;
    public const LOCK_TTL = 2 * 3600; // kada page; ang run na pinatay (hindi nakapag-release) ay hindi humaharang nang higit dito
    // Window read: titigil (time) pagkatapos nito. Sinusuri sa pagitan ng mga batch; ang isang batch ay hanggang ~5 min
    // (4 na subok × 30 s + backoff at Retry-After), saka ang matching: kasya pa rin sa 1800 s na --timeout ng worker.
    public const WINDOW_SECONDS = 22 * 60;
    public const RESUME_OVERLAP = 5;       // batch na inuulit kapag itinutuloy ang window read
    private const DECIDING_STOPS = ['cap', 'time', 'age', 'end']; // ang mga run na nagpapasya sa plan(), kasama ang window read

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

    /** Patakbuhin habang hawak ang lock (isa lang sabay: schedule, command at button). Null = may tumatakbo na. */
    public function withLock(callable $fn): mixed
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_TTL);
        if (!$lock->get()) return null;

        Cache::put(self::LOCK . ':since', now()->toIso8601String(), self::LOCK_TTL);
        try {
            return $fn();
        } finally {
            $lock->release();
            Cache::forget(self::LOCK . ':since');
        }
    }

    /** Kailan nagsimula ang sync na may hawak ng lock, o null kapag walang tumatakbo. */
    public function lockedSince(): ?Carbon
    {
        $since = Cache::get(self::LOCK . ':since');
        return is_string($since) ? Carbon::parse($since)->setTimezone(config('app.timezone')) : null;
    }

    /** Para sa SIGTERM / SIGINT ng command: bitawan ang lock kahit hindi natapos ang run. */
    public function forceRelease(): void
    {
        Cache::lock(self::LOCK)->forceRelease();
        Cache::forget(self::LOCK . ':since');
    }

    /**
     * Anong basa ang susunod na sync ng page, at mula saang batch. Mula sa mga naunang api run ng page.
     * @return array{0:string, 1:int} [incremental|window|full, unang batch]
     */
    public function plan(string $page, bool $full = false): array
    {
        if ($full) return ['full', 1];

        // Ang nagpapasya: ang pinakabagong run na cap / time / age / end, o window read na hindi natapos (failed, pinatay,
        // rate limit). Ang ibang run sa pagitan (known_batch, failed na --full, error ng incremental) ay hindi binibilang,
        // para hindi maging incremental habambuhay ang page na may customer pa sa window na hindi nababasa.
        [$last, $before] = array_pad($this->runsOf($page)
            ->where(fn ($w) => $w->whereIn('stop_reason', self::DECIDING_STOPS)->orWhere('read_kind', 'window'))
            ->orderByDesc('id')->limit(2)->get()->all(), 2, null);

        return $this->decide($last, $before);
    }

    /**
     * Ang plano ng bawat page na may api run, sa isang query (ROW_NUMBER kada page: ang dalawang pinakabagong run na
     * nagpapasya). Ang page na wala rito ay wala pang ganoong run: window read mula batch 1.
     * @return array<string, array{0:string, 1:int}> pageKey => [incremental|window, unang batch]
     */
    public function plans(): array
    {
        $trim  = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        $inner = DB::table('botcake_psid_import_runs')->where('source', 'api')->whereNotNull('page_name')
            ->where(fn ($w) => $w->whereIn('stop_reason', self::DECIDING_STOPS)->orWhere('read_kind', 'window'))
            ->selectRaw("id, page_name, status, stop_reason, read_kind, start_batch, batches_read, ROW_NUMBER() OVER (PARTITION BY LOWER($trim(page_name)) ORDER BY id DESC) AS rn");
        $byPage = DB::query()->fromSub($inner, 'r')->where('rn', '<=', 2)->orderByDesc('id')->get()
            ->groupBy(fn ($r) => $this->settings->pageKey((string) $r->page_name));

        return $byPage->map(fn ($runs) => $this->decide($runs->get(0), $runs->get(1)))->all();
    }

    /**
     * Kailan natapos ang huling matagumpay (done) na api run ng bawat page, sa isang grouped query.
     * @return array<string, Carbon> pageKey => oras
     */
    public function lastDoneAt(): array
    {
        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        $out  = [];
        BotcakePsidImportRun::where('source', 'api')->where('status', 'done')->whereNotNull('page_name')
            ->selectRaw("LOWER($trim(page_name)) AS pk, MAX(updated_at) AS at")->groupByRaw("LOWER($trim(page_name))")->get()
            ->each(function ($r) use (&$out) {
                $pk = $this->settings->pageKey((string) $r->pk);
                $at = Carbon::parse($r->at);
                if (!isset($out[$pk]) || $at->gt($out[$pk])) $out[$pk] = $at;
            });

        return $out;
    }

    /** @return array{0:string, 1:int} mula sa nagpapasyang run at sa nauna rito (Eloquent o plain row) */
    private function decide(?object $last, ?object $before): array
    {
        if (!$last) return ['window', 1]; // hindi pa nakababasa ng buong window
        if (in_array($last->stop_reason, ['age', 'end'], true)) return ['incremental', 1];
        if ($last->read_kind !== 'window') return ['window', 1]; // cap ng incremental (o lumang run na walang kind)

        // Hindi natapos na window read: ituloy mula sa naabot nito, minus ang overlap.
        $start = (int) ($last->start_batch ?: 1);
        if ((int) $last->batches_read > 0) return ['window', max(1, $start + (int) $last->batches_read - 1 - self::RESUME_OVERLAP)];

        // Walang nabasa: parehong simula ulit. Dalawang sunod na walang nabasa mula sa parehong simula: mula batch 1, para
        // hindi maipit sa batch na laging pumapalya.
        $stuck = $before && $before->read_kind === 'window' && (int) $before->batches_read === 0
            && (int) ($before->start_batch ?: 1) === $start && $start > 1;
        return ['window', $stuck ? 1 : $start];
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
            'status' => 'failed', 'stop_reason' => 'killed', 'last_error' => 'Pinatay ang sync bago natapos (hal. timeout ng worker)',
        ]);
    }

    /** Para sa run message at sa "Huling sync" ng grid: incremental / window / window, tuloy mula batch N / full. */
    public static function kindLabel(?string $kind, ?int $start): ?string
    {
        if ($kind === 'window' && $start > 1) return 'window, tuloy mula batch ' . $start;
        return $kind;
    }

    public function syncPage(string $page, bool $full = false): BotcakePsidImportRun
    {
        $started = $this->clock->now();
        [$kind, $start] = $this->plan($page, $full);
        $run = BotcakePsidImportRun::create([
            'status' => 'running', 'source' => 'api', 'page_name' => $page, 'read_kind' => $kind, 'start_batch' => $start,
            'last_message' => ucfirst(self::kindLabel($kind, $start)) . ' sync',
        ]);

        $pageId = $this->settings->get($page, 'botcake', 'page_id');
        $token  = $this->settings->get($page, 'botcake', 'token');
        $error  = null;
        $reason = null;
        $inactive = 0;
        $limit  = $kind === 'incremental' ? max(1, (int) config('services.botcake.sync_max_batches', 300)) : self::MAX_BATCHES;
        $oldest = now()->subDays(max(1, (int) config('services.botcake.sync_max_age_days', 45)));

        if (!$pageId || !$token) {
            $error = 'Walang botcake.page_id o botcake.token ang page';
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

                    $allKnown = $this->store($page, $batch, $run);
                    $run->save();
                    if ($allKnown && $kind === 'incremental') $reason = 'known_batch';
                    elseif ($this->reachesBefore($batch, $oldest)) $reason = 'age';
                    elseif ($kind === 'window' && $this->clock->now() - $started >= self::WINDOW_SECONDS) $reason = 'time';
                    elseif ($run->batches_read >= $limit) $reason = 'cap';
                }
                $this->tokenStatus($page, 'ok');

                // Buong basa mula batch 1 hanggang dulo (o hanggang sa window) nang walang error, sa iisang run: nakita ang
                // lahat ng kasalukuyang customer na naka-subscribe sa loob ng window. Ang mas luma ay hindi binabasa, kaya
                // hindi ginagalaw. Ang itinuloy o putol na basa ay hindi nakakita sa lahat: walang inactive.
                if ($kind !== 'incremental' && $start === 1 && in_array($reason, ['end', 'age'], true)) {
                    $inactive = BotcakePsid::where('source', 'api')->where('page', $page)->where('status', 'available')
                        ->where('subscribed_at', '>=', $oldest)
                        ->where(fn ($w) => $w->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subDays(self::INACTIVE_DAYS)))
                        ->update(['status' => 'inactive', 'last_checked_at' => now()]);
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
            'ad_filled'        => $m['ad_filled'],
            'last_message'     => sprintf('%s: %s, %d batch (%s), %d bago, %d na-update, %d inactive; match %d pangalan, %d phone, %d ambiguous, %d wala, %d ad id',
                $page, self::kindLabel($kind, $start), $run->batches_read, $reason, $run->customers_new, $run->customers_updated, $inactive, $m['by_name'], $m['by_phone'], $m['ambiguous'], $m['no_match'], $m['ad_filled']),
        ])->save();

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

    /** @return bool true kapag naitala na dati ang lahat ng psid ng batch */
    private function store(string $page, array $batch, BotcakePsidImportRun $run): bool
    {
        $psids = [];
        foreach ($batch as $c) {
            $psid = trim((string) ($c['psid'] ?? ''));
            if ($psid !== '' && strlen($psid) <= 64) $psids[$psid] = $c;
        }
        if (!$psids) return false;

        $existing = BotcakePsid::where('source', 'api')->where('page', $page)
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
                BotcakePsid::create($attrs + $seen + [
                    'page' => $page, 'psid' => $psid, 'source' => 'api', 'status' => 'available', 'first_seen_at' => now(),
                ]);
                $run->customers_new++;
                continue;
            }

            $rec->fill($attrs + $seen + ['status' => 'available']); // inactive na nakita ulit → available
            if ($rec->isDirty(['fb_name', 'phone_numbers', 'status'])) {
                $run->customers_updated++;
            }
            $rec->save();
        }

        return $existing->count() === count($psids);
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
