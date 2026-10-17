<?php

namespace App\Services\Jnt;

use App\Support\BotcakeClock;
use App\Support\JntScanStatusMapper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * J&T TRACKQUERY para sa from_jnts: ginagamit ng /jnt/track-sync (job) at ng chatblast (kapag kumukuha ng listahan at
 * bago ipadala ang delivering). 20 waybill kada tawag (ang lampas ay tahimik na nilalaglag ng J&T: ang hindi bumalik ay
 * isa-isang sinusubukan), hanggang 2 tawag kada segundo. Ang sagot ay isinusulat sa bawat from_jnts row ng waybill ayon
 * sa id: status at status_logs (kapag nagbago), signingtime, rider_name at rider_phone. Walang bagong column.
 */
class JntTracker
{
    public const CHUNK = 20;
    public const CALL_TIMEOUT = 10;   // segundo ng HTTP kada tawag ng chatblast (build at bago ipadala)
    private const FINAL = ['Delivered', 'Returned']; // tapos na: hindi na ginagalaw (gaya ng manual na tool at ng upload)
    private const MIN_INTERVAL = 0.5; // segundo sa pagitan ng mga tawag = max 2 kada segundo

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
     * Para sa chatblast: i-track ang mga waybill at isulat sa bawat from_jnts row nila ayon sa id (hindi ang tapos na:
     * Delivered, Returned). Walang bagong tawag kapag lampas na sa ($budget − CALL_TIMEOUT) segundo, kaya ang tawag na
     * tumatakbo pa ay natatapos sa loob ng $budget. $record: itinatala bilang awtomatikong run (ang build; hindi ang bawat
     * padala). Ang error sa gitna ay itinatala sa run (failed, last_error) at ibinabalik ang naabot.
     * @param  string[] $waybills
     * tracked = ang mga sinagot ng J&T; latest = waybill => pinakabagong scantype (kahit Problematic o di-kilala);
     * riders = waybill => [pangalan, phone] mula sa sagot na ito lang (walang laman kapag hindi mabasa).
     * @return array{tracked: array<int,string>, latest: array<string,string>, riders: array<string,array{0:string,1:string}>, pending: array<int,string>, checked:int, updated:int, rider:int, errors:int}|null null kapag walang J&T config
     */
    public function refresh(array $waybills, float $budget, string $src = 'chatblast', bool $record = true): ?array
    {
        $waybills = array_values(array_unique(array_filter(array_map(fn ($w) => trim((string) $w), $waybills))));
        if (!$waybills || !($client = $this->client())) return null;
        $client = $client->withTimeout(self::CALL_TIMEOUT);

        $started = $this->clock->now();
        $cutoff  = $started + max(0, $budget - self::CALL_TIMEOUT); // walang bagong tawag pagkalipas nito
        $out = ['tracked' => [], 'latest' => [], 'riders' => [], 'pending' => [], 'checked' => 0, 'updated' => 0, 'rider' => 0, 'errors' => 0];
        $runId = !$record ? null : DB::table('jnt_track_sync_runs')->insertGetId([
            'date_from' => null, 'date_to' => null, 'dry_run' => false, 'status' => 'running', 'total' => count($waybills),
            'started_at' => now('Asia/Manila'), 'created_at' => now('Asia/Manila'), 'updated_at' => now('Asia/Manila'),
        ]);
        $error = null;

        try {
            foreach (array_chunk($waybills, self::CHUNK) as $i => $chunk) {
                if ($i > 0 && $this->clock->now() >= $cutoff) {
                    array_push($out['pending'], ...$chunk); // susunod na kuha o bago ipadala
                    continue;
                }
                $found = $this->trackSet($client, $chunk, $cutoff);
                $rows  = DB::table('from_jnts')->whereIn('waybill_number', $chunk)
                    ->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', self::FINAL)) // ang NULL ay hindi tapos
                    ->get(['id', 'waybill_number', 'status', 'status_logs', 'rider_name', 'rider_phone'])->groupBy('waybill_number');

                foreach ($chunk as $wb) {
                    if (empty($found[$wb]) && count($chunk) > 1 && $this->clock->now() >= $cutoff) {
                        $out['pending'][] = $wb; // hindi na nasubukan nang isa-isa: ubos na ang oras
                        continue;
                    }
                    $out['checked']++;
                    if (empty($found[$wb])) { $out['errors']++; continue; } // walang sagot ang J&T: hindi "na-track"
                    $info = JntScanStatusMapper::fromDetails($found[$wb]);
                    $out['tracked'][] = $wb;
                    $out['latest'][$wb] = trim((string) ($found[$wb][0]['scantype'] ?? ''));
                    $out['riders'][$wb] = [(string) ($info['rider_name'] ?? ''), (string) ($info['rider_phone'] ?? '')];
                    if (!empty($info['rider_name'])) $out['rider']++;
                    foreach ($rows[$wb] ?? [] as $row) {
                        $c = $this->changesFor($row, $found[$wb], $src, $runId);
                        if ($c['update']) {
                            DB::table('from_jnts')->where('id', $row->id)->update($c['update'] + ['updated_at' => now('Asia/Manila')]);
                        }
                        if ($c['changed']) $out['updated']++;
                    }
                }
            }
        } catch (Throwable $e) {
            report($e);
            $error = mb_substr($e->getMessage(), 0, 500);
        }

        if ($runId) {
            DB::table('jnt_track_sync_runs')->where('id', $runId)->update([
                'status' => $error ? 'failed' : 'done', 'last_error' => $error,
                'processed' => $out['checked'], 'updated' => $out['updated'], 'skipped' => $out['errors'],
                'result_sample' => json_encode(['source' => $src, 'rider_found' => $out['rider'], 'pending' => count($out['pending'])]),
                'finished_at' => now('Asia/Manila'), 'updated_at' => now('Asia/Manila'),
            ]);
        }

        return $out;
    }
}
