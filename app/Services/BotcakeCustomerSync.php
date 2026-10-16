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
 * Incremental: page 1, 2, … hanggang sa unang batch na naitala na lahat ang psid (pinakabago muna ang listahan)
 * o walang laman. Full: hanggang walang laman. Isang page sa isang pagkakataon; bawat sync ay isang run.
 */
class BotcakeCustomerSync
{
    public const LOCK = 'botcake-sync-customers';
    public const MAX_BATCHES = 20000; // pananggalang kung paulit-ulit ang sagot ng API

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
        $lock = Cache::lock(self::LOCK, 3 * 3600);
        if (!$lock->get()) return null;

        try {
            return $fn();
        } finally {
            $lock->release();
        }
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

        if (!$pageId || !$token) {
            $error = 'Walang botcake.page_id o botcake.token ang page';
        } else {
            $client = app(BotcakeClient::class); // bago kada page: sariling rate at retry
            try {
                for ($n = 1; $n <= self::MAX_BATCHES; $n++) {
                    $batch = $client->customers($pageId, $token, $n);
                    $run->batches_read++;
                    if (!$batch) break;

                    $allKnown = $this->store($page, $batch, $run);
                    $run->save();
                    if ($allKnown && !$full) break;
                }
                $this->tokenStatus($page, 'ok');
            } catch (BotcakeException $e) {
                $error = $e->getMessage();
                if ($e->auth) $this->tokenStatus($page, 'invalid');
            }
        }

        $m = $this->matcher->matchPage($page);

        $run->fill([
            'status'           => $error ? 'failed' : 'done',
            'last_error'       => $error,
            'seconds'          => (int) round($this->clock->now() - $started),
            'matched_by_name'  => $m['by_name'],
            'matched_by_phone' => $m['by_phone'],
            'ambiguous'        => $m['ambiguous'],
            'no_match'         => $m['no_match'],
            'last_message'     => sprintf('%s: %d batch, %d bago, %d na-update; match %d pangalan, %d phone, %d ambiguous, %d wala',
                $page, $run->batches_read, $run->customers_new, $run->customers_updated, $m['by_name'], $m['by_phone'], $m['ambiguous'], $m['no_match']),
        ])->save();

        Log::info('BotcakeCustomerSync: ' . $run->last_message, ['run_id' => $run->id, 'error' => $error]);

        return $run;
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
                'subscribed_at'       => $this->time($c['subscribed_at'] ?? $c['inserted_at'] ?? null),
                'last_interactive_at' => $this->time($c['last_interactive_at'] ?? null),
            ];

            $rec = $existing[$psid] ?? null;
            if (!$rec) {
                BotcakePsid::create($attrs + [
                    'page' => $page, 'psid' => $psid, 'source' => 'api', 'status' => 'available', 'first_seen_at' => now(),
                ]);
                $run->customers_new++;
                continue;
            }

            $rec->fill($attrs + ['last_checked_at' => now()]);
            if ($rec->isDirty(['fb_name', 'phone_numbers'])) {
                $run->customers_updated++;
            }
            $rec->save();
        }

        return $existing->count() === count($psids);
    }

    private function time(mixed $v): ?Carbon
    {
        if (!is_string($v) || $v === '') return null;
        try {
            return Carbon::parse($v);
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
