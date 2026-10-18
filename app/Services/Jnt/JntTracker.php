<?php

namespace App\Services\Jnt;

use App\Support\BotcakeClock;
use App\Support\JntScanStatusMapper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * J&T TRACKQUERY para sa from_jnts: ginagamit ng manual na /jnt/track-sync (job) at ng kusang track-sync kada oras
 * (JntAutoTrackSync). 20 waybill kada tawag (ang lampas ay tahimik na nilalaglag ng J&T: ang hindi bumalik ay
 * isa-isang sinusubukan), hanggang 2 tawag kada segundo. Ang sagot ay isinusulat sa bawat from_jnts row ng waybill ayon
 * sa id: status at status_logs (kapag nagbago), signingtime, rider_name at rider_phone; ang kusang track-sync ay
 * isinusulat din ang pinakabagong scan sa jnt_latest_scans.
 */
class JntTracker
{
    public const CHUNK = 20;
    public const CALL_TIMEOUT = 10;   // segundo ng HTTP kada tawag ng kusang track-sync
    private const FINAL = ['Delivered', 'Returned']; // tapos na: hindi na ginagalaw (gaya ng manual na tool at ng upload)
    private const MIN_INTERVAL = 0.5; // segundo sa pagitan ng mga tawag = max 2 kada segundo
    private const PROGRESS_EVERY = 25; // ilang tawag (ng 20) bago isulat ang progreso ng run

    private ?float $lastCallAt = null;

    public function __construct(private BotcakeClock $clock)
    {
    }

    /** Ang client mula sa config, o null kapag walang J&T credentials (hal. sa ibang server): isang warning kada oras. */
    public function client(): ?JntClient
    {
        try {
            return JntClient::fromConfig();
        } catch (RuntimeException $e) {
            if (Cache::add('jnt-auto-track:no-config-warned', 1, 3600)) {
                Log::warning('J&T auto track: walang J&T config (JNT_*); hindi ina-update ang status at rider.');
            }
            return null;
        }
    }

    /**
     * Track ng isang set ng waybill: isang tawag para sa hanggang 20, saka isa-isa ang hindi bumalik.
     * $deadline (orasan ng BotcakeClock): wala nang isa-isang subok pagkalipas nito.
     * @param  string[] $waybills
     * @return array<string, array<int, array<string, mixed>>> waybill => details (pinakabago muna), ang may laman lang
     */
    public function trackSet(JntClient $client, array $waybills, ?float $deadline = null): array
    {
        $found = [];
        try {
            $found = JntScanStatusMapper::parseResponse($this->call($client, implode(',', $waybills)));
        } catch (Throwable $e) {
            $found = [];
        }

        if (count($waybills) === 1) return $found; // iisang waybill: ang tawag na iyon na ang isa-isang subok

        foreach (array_values(array_diff($waybills, array_keys($found))) as $wb) {
            if ($deadline !== null && $this->clock->now() >= $deadline) break; // ubos na ang oras: sa susunod na lang
            try {
                $p = JntScanStatusMapper::parseResponse($this->call($client, $wb));
                if (!empty($p[$wb])) $found[$wb] = $p[$wb];
            } catch (Throwable $e) {
                // walang sagot: mananatiling hindi na-track
            }
        }

        return $found;
    }

    private function call(JntClient $client, string $billcodes): array
    {
        if ($this->lastCallAt !== null) {
            $wait = $this->lastCallAt + self::MIN_INTERVAL - $this->clock->now();
            if ($wait > 0) $this->clock->sleep($wait);
        }
        $this->lastCallAt = $this->clock->now();

        return $client->trackForJson($billcodes, 'en');
    }

    /**
     * Ang isusulat sa isang from_jnts row mula sa details (walang isinusulat dito). Kapareho ng /jnt/track-sync: status at
     * status_logs kapag nagbago (hindi kapag Problematic o di-kilalang scan), signingtime, at rider kapag may laman at iba.
     * @return array{info: array, update: array<string, mixed>, changed: bool}
     */
    public function changesFor(object $row, array $details, string $src, ?int $runId): array
    {
        $info = JntScanStatusMapper::fromDetails($details);
        $upd = [];
        $changed = false;

        if (!$info['unmapped']) {
            $old = (string) $row->status;
            $new = (string) $info['status'];
            if ($new !== $old) {
                $changed = true;
                $logs = json_decode((string) ($row->status_logs ?? ''), true);
                if (!is_array($logs)) $logs = [];
                $logs[] = ['batch_at' => now('Asia/Manila')->toDateTimeString(), 'upload_log_id' => null, 'from' => $old ?: null, 'to' => $new, 'src' => $src, 'run_id' => $runId];
                $upd['status'] = $new;
                $upd['status_logs'] = json_encode($logs, JSON_UNESCAPED_UNICODE);
                if (!empty($info['signingtime'])) $upd['signingtime'] = $info['signingtime'];
            }
        }
        if (!empty($info['rider_phone']) && $info['rider_phone'] !== (string) ($row->rider_phone ?? '')) $upd['rider_phone'] = $info['rider_phone'];
        if (!empty($info['rider_name']) && $info['rider_name'] !== (string) ($row->rider_name ?? '')) $upd['rider_name'] = $info['rider_name'];

        return ['info' => $info, 'update' => $upd, 'changed' => $changed];
    }

    /**
     * Para sa kusang track-sync (JntAutoTrackSync): i-track ang mga waybill ayon sa ayos nila at isulat sa bawat from_jnts
     * row nila ayon sa id (hindi ang tapos na: Delivered, Returned), at ang pinakabagong scan sa jnt_latest_scans (kahit
     * Problematic o di-kilala). Walang bagong tawag kapag lampas na sa ($budget − CALL_TIMEOUT) segundo, kaya ang tawag na
     * tumatakbo pa ay natatapos sa loob ng $budget. Ang progreso ay isinusulat sa run na $runId kada PROGRESS_EVERY na tawag.
     * Ang error sa gitna ay ibinabalik (error) kasama ang naabot.
     * pending = ang hindi naabot dahil ubos ang oras, ayon sa ayos ng $waybills.
     * @param  string[] $waybills
     * @return array{pending: array<int,string>, checked:int, updated:int, unchanged:int, unmapped:int, rider:int, errors:int, error:?string}|null null kapag walang J&T config
     */
    public function refresh(array $waybills, float $budget, string $src, ?int $runId = null): ?array
    {
        $waybills = array_values(array_unique(array_filter(array_map(fn ($w) => trim((string) $w), $waybills))));
        if (!$waybills || !($client = $this->client())) return null;
        $client = $client->withTimeout(self::CALL_TIMEOUT);

        $started = $this->clock->now();
        $cutoff  = $started + max(0, $budget - self::CALL_TIMEOUT); // walang bagong tawag pagkalipas nito
        $out = ['pending' => [], 'checked' => 0, 'updated' => 0, 'unchanged' => 0, 'unmapped' => 0, 'rider' => 0, 'errors' => 0, 'error' => null];
        $scans = Schema::hasTable('jnt_latest_scans'); // hindi pa na-migrate: status at rider lang

        try {
            foreach (array_chunk($waybills, self::CHUNK) as $i => $chunk) {
                if ($i > 0 && $this->clock->now() >= $cutoff) {
                    array_push($out['pending'], ...$chunk); // sa susunod na takbo
                    continue;
                }
                $found = $this->trackSet($client, $chunk, $cutoff);
                $rows  = DB::table('from_jnts')->whereIn('waybill_number', $chunk)
                    ->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', self::FINAL)) // ang NULL ay hindi tapos
                    ->get(['id', 'waybill_number', 'status', 'status_logs', 'rider_name', 'rider_phone'])->groupBy('waybill_number');
                $latest = [];

                foreach ($chunk as $wb) {
                    if (empty($found[$wb]) && count($chunk) > 1 && $this->clock->now() >= $cutoff) {
                        $out['pending'][] = $wb; // hindi na nasubukan nang isa-isa: ubos na ang oras
                        continue;
                    }
                    $out['checked']++;
                    if (empty($found[$wb])) { $out['errors']++; continue; } // walang sagot: ang lumang scan ay hindi ginagalaw
                    $info = JntScanStatusMapper::fromDetails($found[$wb]);
                    if (!empty($info['rider_name'])) $out['rider']++;
                    $latest[] = $this->latestScan($wb, $found[$wb], $info);

                    $changed = false;
                    foreach ($rows[$wb] ?? [] as $row) {
                        $c = $this->changesFor($row, $found[$wb], $src, $runId);
                        if ($c['update']) {
                            DB::table('from_jnts')->where('id', $row->id)->update($c['update'] + ['updated_at' => now('Asia/Manila')]);
                        }
                        $changed = $changed || $c['changed'];
                    }
                    $out[$info['unmapped'] ? 'unmapped' : ($changed ? 'updated' : 'unchanged')]++;
                }

                if ($scans && $latest) {
                    DB::table('jnt_latest_scans')->upsert($latest, ['waybill'], ['scan_type', 'scan_time', 'scan_place', 'rider_name', 'rider_phone', 'checked_at']);
                }
                if ($runId && ($i + 1) % self::PROGRESS_EVERY === 0) $this->progress($runId, $out);
            }
        } catch (Throwable $e) {
            report($e);
            $out['error'] = mb_substr($e->getMessage(), 0, 500);
        }

        return $out;
    }

    /** Ang bilang ng run habang tumatakbo (para sa /jnt/track-sync). */
    public function progress(int $runId, array $out): void
    {
        DB::table('jnt_track_sync_runs')->where('id', $runId)->update([
            'processed' => $out['checked'], 'updated' => $out['updated'], 'unchanged' => $out['unchanged'],
            'unmapped' => $out['unmapped'], 'skipped' => $out['errors'], 'updated_at' => now('Asia/Manila'),
        ]);
    }

    /**
     * Ang row ng jnt_latest_scans: ang pinakabagong scan ng sagot (details[0]) at ang rider ng pinakabagong On Delivery
     * scan nito (null kapag wala sa sagot na ito: hindi iniiwan ang rider ng ibang araw).
     */
    private function latestScan(string $waybill, array $details, array $info): array
    {
        $top  = (array) ($details[0] ?? []);
        $time = trim((string) ($top['scantime'] ?? ''));
        $place = implode(', ', array_filter([trim((string) ($top['city'] ?? '')), trim((string) ($top['state'] ?? ''))], fn ($p) => $p !== ''));

        return [
            'waybill'     => $waybill,
            'scan_type'   => mb_substr(trim((string) ($top['scantype'] ?? '')), 0, 64) ?: null,
            'scan_time'   => preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $time) ? $time : null,
            'scan_place'  => mb_substr($place, 0, 191) ?: null,
            'rider_name'  => $info['rider_name'] ? mb_substr($info['rider_name'], 0, 191) : null,
            'rider_phone' => $info['rider_phone'] ?: null,
            'checked_at'  => now(),
        ];
    }
}
