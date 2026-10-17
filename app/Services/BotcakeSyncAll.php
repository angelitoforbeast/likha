<?php

namespace App\Services;

use App\Jobs\SyncAllBotcakeCustomersJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * "Sync lahat" ng /page-settings: incremental sync ng bawat page na may botcake.page_id at token na hindi invalid,
 * isa-isa sa ayos ng grid. Isang job kada page; ang job ang nagpapadala ng susunod pagkatapos nito (tagumpay man o
 * hindi), kaya hindi nagsasabay ang dalawang page. Isang run lang sabay. Ang progreso ay nasa cache (nababasa ng poll
 * nang walang query).
 */
class BotcakeSyncAll
{
    public const STATE = 'botcake-sync-all';
    // Nire-refresh sa bawat hakbang; kapag naputol ang kadena (hal. namatay ang worker), mag-e-expire ang run.
    public const STATE_TTL = BotcakeCustomerSync::LOCK_TTL + 600;
    public const KEEP_FINISHED = 86400; // ang tapos na run ay nakikita pa nang isang araw
    public const WAIT_SECONDS = 30;     // kapag may ibang sync na may hawak ng lock: subok ulit pagkatapos nito

    public function __construct(private BotcakeCustomerSync $sync, private PageSettings $settings)
    {
    }

    /** Nagsisimula ng run; null kapag may tumatakbo na (walang bagong run). */
    public function start(): ?array
    {
        $lock = Cache::lock(self::STATE . ':start', 10);
        if (!$lock->get()) return null;

        try {
            if ($this->running()) return null;

            $state = [
                'id' => (string) Str::uuid(), 'pages' => $this->pages(), 'done' => 0, 'ok' => 0, 'failed' => 0,
                'current' => null, 'waiting' => false, 'started_at' => now()->toIso8601String(), 'finished_at' => null,
            ];
            $this->save($state); // kapag walang page, tapos na agad ang run
        } finally {
            $lock->release();
        }

        SyncAllBotcakeCustomersJob::dispatch($state['id'], 0);

        return $this->progress();
    }

    public function running(): bool
    {
        $state = Cache::get(self::STATE);
        return is_array($state) && $state['finished_at'] === null;
    }

    /** Para sa grid at sa poll: walang pangalan ng ibang page, walang token. Null = walang run. */
    public function progress(): ?array
    {
        $state = Cache::get(self::STATE);
        if (!is_array($state)) return null;

        $at = fn ($t) => $t ? \Carbon\Carbon::parse($t)->timezone('Asia/Manila')->format('Y-m-d H:i') : null;

        return [
            'total'       => count($state['pages']),
            'done'        => $state['done'],
            'ok'          => $state['ok'],
            'failed'      => $state['failed'],
            'current'     => $state['current'],
            'waiting'     => $state['waiting'],
            'started_at'  => $at($state['started_at']),
            'finished_at' => $at($state['finished_at']),
        ];
    }

    /** Ang state ng run na ito, o null kapag luma na ang job (ibang run, o tapos na). */
    public function state(string $runId): ?array
    {
        $state = Cache::get(self::STATE);
        return is_array($state) && $state['id'] === $runId && $state['finished_at'] === null ? $state : null;
    }

    public function save(array $state): void
    {
        if ($state['done'] >= count($state['pages'])) {
            $state['current'] = null;
            $state['waiting'] = false;
            $state['finished_at'] = now()->toIso8601String();
            Cache::put(self::STATE, $state, self::KEEP_FINISHED);
            return;
        }
        Cache::put(self::STATE, $state, self::STATE_TTL);
    }

    public const SUMMARY = [
        'window' => 'window', 'continue' => 'tuloy', 'incremental' => 'incremental',
        'fresh' => 'laktaw (sariwa)', 'missing' => 'walang ID/token', 'invalid' => 'token invalid',
    ];

    /**
     * Ano ang gagawin ng susunod na sync ng bawat row (grid row: key + cells + info.botcake), para sa Sync lahat: ang
     * plan() ng spec 044 at, sa ibabaw, ang laktaw kapag incremental at may matagumpay na api run sa huling N oras
     * (services.botcake.sync_all_fresh_hours). Ang window read at ang tuloy ay hindi nilalaktawan. Dalawang query lang.
     * @param array<int|string, array> $rows
     * @return array<string, array{kind:string, label:string}> pageKey => uri at label
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
            [$kind, $start] = $plans[$pk] ?? ['window', 1];
            if ($kind === 'window') {
                $out[$pk] = $start > 1 ? ['kind' => 'continue', 'label' => 'tuloy mula batch ' . $start]
                    : ['kind' => 'window', 'label' => 'window (' . $days . ' araw, buo)'];
                continue;
            }

            $fresh ??= $this->sync->lastDoneAt();
            $at = $fresh[$pk] ?? null;
            if ($at && $at->gt(now()->subMinutes((int) round($hours * 60)))) {
                $mins = (int) $at->diffInMinutes(now(), true);
                $out[$pk] = ['kind' => 'fresh', 'label' => 'laktaw (sariwa, ' . ($mins < 60 ? $mins . ' min' : intdiv($mins, 60) . ' oras') . ' na)'];
                continue;
            }
            $out[$pk] = ['kind' => 'incremental', 'label' => 'incremental'];
        }

        return $out;
    }

    /** Ang isang linya ng plano ng Sync lahat, hal. "12 window, 3 tuloy, 20 incremental, 6 laktaw (sariwa)". */
    public static function summary(array $next): string
    {
        $count = array_count_values(array_column($next, 'kind'));
        $parts = [];
        foreach (self::SUMMARY as $kind => $label) {
            if (!empty($count[$kind])) $parts[] = $count[$kind] . ' ' . $label;
        }

        return implode(', ', $parts);
    }

    /** @return array<int, string> ang mga page (baybay sa settings) sa ayos ng grid: may page_id at token, hindi invalid */
    private function pages(): array
    {
        $botcake = collect($this->sync->pages())->keyBy(fn ($p) => $this->settings->pageKey($p));

        // Ang grid ay kinukuha rito, hindi sa constructor: ginagamit din ng grid ang klaseng ito para sa progreso.
        return collect(app(PageSettingsGrid::class)->build()['rows'])
            ->filter(fn ($r) => isset($botcake[$r['key']]))
            ->reject(fn ($r) => str_starts_with((string) ($r['cells']['botcake.token_status']['value'] ?? ''), 'invalid'))
            ->map(fn ($r) => $botcake[$r['key']])
            ->values()->all();
    }
}
