<?php

namespace App\Services;

use App\Models\BotcakePsid;
use App\Models\BotcakePsidImportRun;
use App\Support\BotcakeClock;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Botcake customer list → botcake_psids (source api, status available; isang row kada page + psid), saka matching.
 * Incremental: page 1, 2, … hanggang sa unang batch na naitala na lahat ang psid (pinakabago muna ang listahan),
 * walang laman, o ang cap (services.botcake.sync_max_batches); ang susunod na run ay mula page 1 ulit.
 * Full: hanggang walang laman; pagkatapos ng malinis na basa, ang hindi nakita nang 30 araw ay inactive (hindi binubura).
 * Window: lahat ng sync ay humihinto sa unang batch na may customer na naka-subscribe bago ang huling N araw
 * (services.botcake.sync_max_age_days, default 45; stop reason age). Pinakabagong subscriber muna ang listahan, kaya
 * mas luma na ang lahat ng kasunod. Ang inactive ay para lang sa mga naka-subscribe sa loob ng window.
 * Isang page sa isang pagkakataon; bawat sync ay isang run na may stop_reason.
 */
class BotcakeCustomerSync
{
    public const LOCK = 'botcake-sync-customers';
    public const MAX_BATCHES = 20000; // pananggalang ng --full kung paulit-ulit ang sagot ng API
    public const INACTIVE_DAYS = 30;
    public const LOCK_TTL = 2 * 3600; // kada page; ang run na pinatay (hindi nakapag-release) ay hindi humaharang nang higit dito

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

    public function syncPage(string $page, bool $full = false): BotcakePsidImportRun
    {
        $started = $this->clock->now();
        $run = BotcakePsidImportRun::create([
            'status' => 'running', 'source' => 'api', 'page_name' => $page,
            'last_message' => $full ? 'Full sync' : 'Incremental sync',
        ]);

        $pageId = $this->settings->get($page, 'botcake', 'page_id');
        $token  = $this->settings->get($page, 'botcake', 'token');
        $error  = null;
        $reason = null;
        $inactive = 0;
        $limit  = $full ? self::MAX_BATCHES : max(1, (int) config('services.botcake.sync_max_batches', 300));
        $oldest = now()->subDays(max(1, (int) config('services.botcake.sync_max_age_days', 45)));

        if (!$pageId || !$token) {
            $error = 'Walang botcake.page_id o botcake.token ang page';
            $reason = 'error';
        } else {
            $client = app(BotcakeClient::class); // bago kada page: sariling rate at retry
            try {
                for ($n = 1; $reason === null; $n++) {
                    $batch = $client->customers($pageId, $token, $n);
                    $run->batches_read++;
                    if (!$batch) {
                        $reason = 'end';
                        break;
                    }

                    $allKnown = $this->store($page, $batch, $run);
                    $run->save();
                    if ($allKnown && !$full) $reason = 'known_batch';
                    elseif ($this->reachesBefore($batch, $oldest)) $reason = 'age';
                    elseif ($n >= $limit) $reason = 'cap';
                }
                $this->tokenStatus($page, 'ok');

                // Buong basa hanggang dulo (o hanggang sa window) nang walang error: nakita ang lahat ng kasalukuyang
                // customer na naka-subscribe sa loob ng window. Ang mas luma ay hindi na binabasa, kaya hindi ginagalaw.
                if ($full && in_array($reason, ['end', 'age'], true)) {
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
            'last_message'     => sprintf('%s: %d batch (%s), %d bago, %d na-update, %d inactive; match %d pangalan, %d phone, %d ambiguous, %d wala',
                $page, $run->batches_read, $reason, $run->customers_new, $run->customers_updated, $inactive, $m['by_name'], $m['by_phone'], $m['ambiguous'], $m['no_match']),
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
