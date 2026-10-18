<?php

namespace App\Services\Jnt;

use App\Models\AppSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ang kusang J&T track-sync: kada oras sa :20 Manila (mga 15 minuto pagkatapos ng hourly J&T upload), 06:20–23:20 at
 * 02:20. Lahat ng bukas na from_jnts waybill (hindi Delivered/Returned) na may submission_time sa huling 60 araw,
 * pinakabago muna, sa JntTracker (20 kada tawag, 2 tawag kada segundo): ang status at rider sa from_jnts, ang
 * pinakabagong scan sa jnt_latest_scans. Hanggang 40 minuto kada takbo; ang hindi naabot ang una sa susunod na takbo.
 * Isang takbo kada oras (claim sa cache, gaya ng refresh ng chatblast) at hindi kailanman sabay (lock). Itinatala sa
 * jnt_track_sync_runs bilang kind = auto.
 */
class JntAutoTrackSync
{
    public const SETTING = 'jnt_track_sync_auto';  // app_settings: '1' / '0' (ang toggle ng CEO); wala = ang config
    public const SLOT = 'jnt-auto-track-slot';      // + ":Y-m-d H" (Manila): ang oras na kinuha na
    public const LOCK = 'jnt-auto-track';
    public const KIND = 'auto';
    public const SRC = 'auto_track';                // src ng status_logs
    public const MINUTE = 20;
    public const HOURS = [2, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23];
    public const CAP = 40 * 60;                     // segundo kada takbo
    public const DAYS = 60;
    private const LOCK_SECONDS = 50 * 60;           // higit sa CAP: ang patay na proseso ay hindi humaharang nang matagal
    private const FINAL = ['Delivered', 'Returned'];

    public function __construct(private JntTracker $tracker)
    {
    }

    /** Naka-on ba: ang toggle ng CEO (app_settings) kapag naitakda na, kung hindi ang JNT_TRACK_SYNC_AUTO. */
    public static function enabled(): bool
    {
        try {
            if (Schema::hasTable('app_settings') && ($v = AppSetting::get(self::SETTING)) !== null) return $v === '1';
        } catch (Throwable $e) {
            // walang DB: ang config ang batayan
        }

        return (bool) config('services.jnt.track_sync_auto');
    }

    /** Ang susi ng oras ngayon kapag oras na ng takbo (:20 o lampas, sa HOURS), o null. */
    public static function dueSlot(?Carbon $now = null): ?string
    {
        $now ??= Carbon::now('Asia/Manila');
        if ($now->minute < self::MINUTE || !in_array($now->hour, self::HOURS, true)) return null;

        return self::SLOT . ':' . $now->format('Y-m-d H');
    }

    /** Para sa chatblast:auto: naka-on, oras na, at wala pang kumuha ng oras na ito. */
    public static function wanted(): bool
    {
        return self::enabled() && ($slot = self::dueSlot()) !== null && !Cache::has($slot);
    }

    /** Ang susunod na oras ng takbo (Manila). */
    public static function nextRun(?Carbon $now = null): Carbon
    {
        $now ??= Carbon::now('Asia/Manila');
        $at = $now->copy()->setTime($now->hour, self::MINUTE);
        if ($at->lte($now)) $at->addHour();
        while (!in_array($at->hour, self::HOURS, true)) $at->addHour();

        return $at;
    }

    /**
     * Isang takbo kapag oras na: kinukuha ang oras BAGO magsimula (atomic), kaya ang pangalawang simula sa parehong oras
     * (scheduler at chatblast:auto) ay walang ginagawa. Ang kinuhang oras ay hindi ibinabalik kahit pumalya, para walang
     * paulit-ulit na subok sa loob ng oras. Null kapag naka-off o hindi pa oras; busy kapag tumatakbo pa ang nauna.
     */
    public function runIfDue(): ?array
    {
        if (!self::enabled() || !($slot = self::dueSlot())) return null;
        if (!Cache::add($slot, true, 7200)) return null;

        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);
        if (!$lock->get()) return ['busy' => true];

        try {
            return $this->run();
        } finally {
            $lock->release();
        }
    }

    private function run(): array
    {
        [$list, $pos] = $this->waybills();
        $now = now('Asia/Manila');
        $runId = DB::table('jnt_track_sync_runs')->insertGetId([
            'kind' => self::KIND, 'date_from' => null, 'date_to' => null, 'dry_run' => false, 'status' => 'running',
            'total' => count($list), 'started_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        try {
            $out = $list ? $this->tracker->refresh($list, self::CAP, self::SRC, $runId) : null;
        } catch (Throwable $e) {
            $out = ['error' => mb_substr($e->getMessage(), 0, 500)];
        }
        $out ??= ['error' => $list ? 'J&T is not configured on this server' : null];

        $pending = $out['pending'] ?? [];
        if (!empty($out['checked'])) $this->tracker->progress($runId, $out);
        DB::table('jnt_track_sync_runs')->where('id', $runId)->update([
            'status' => $out['error'] ? 'failed' : 'done', 'last_error' => $out['error'],
            'result_sample' => json_encode([
                'source' => self::KIND, 'rider_found' => $out['rider'] ?? 0, 'pending' => count($pending), 'capped' => $pending !== [],
                'resume' => $pending ? $pos[$pending[0]] : null, // ang unang hindi naabot: dito magsisimula ang susunod
            ]),
            'finished_at' => now('Asia/Manila'), 'updated_at' => now('Asia/Manila'),
        ]);

        return ['run_id' => $runId, 'total' => count($list), 'pending' => count($pending)] + array_intersect_key($out, array_flip(['checked', 'updated', 'unmapped', 'errors', 'error']));
    }

    /**
     * Ang mga waybill, pinakabago muna (submission_time, saka id), isang beses bawat isa. Kapag naputol ng cap ang huling
     * takbo, ang listahan ay nagsisimula sa unang hindi naabot nito (ang mga sumunod pa, pinakabago muna) at saka ang
     * pinakabago hanggang doon.
     * @return array{0: array<int,string>, 1: array<string, array{0:string, 1:int}>} [waybills, waybill => [submission_time, id]]
     */
    private function waybills(): array
    {
        $since = Carbon::now('Asia/Manila')->subDays(self::DAYS)->startOfDay()->format('Y-m-d H:i:s');
        $list = $pos = [];
        foreach (DB::table('from_jnts')
            ->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', self::FINAL))
            ->whereNotNull('waybill_number')->where('waybill_number', '<>', '')
            ->where('submission_time', '>=', $since)
            ->orderByDesc('submission_time')->orderByDesc('id')
            ->select('id', 'waybill_number', 'submission_time')->cursor() as $r) {
            $wb = trim((string) $r->waybill_number);
            if ($wb === '' || isset($pos[$wb])) continue;
            $pos[$wb] = [(string) $r->submission_time, (int) $r->id];
            $list[] = $wb;
        }

        $last = DB::table('jnt_track_sync_runs')->where('kind', self::KIND)->orderByDesc('id')->value('result_sample');
        $resume = $last ? (json_decode($last, true)['resume'] ?? null) : null;
        if (is_array($resume) && count($resume) === 2) {
            [$t, $id] = $resume;
            foreach ($list as $i => $wb) {
                [$wt, $wid] = $pos[$wb];
                if ($wt < $t || ($wt === $t && $wid <= $id)) {
                    $list = array_merge(array_slice($list, $i), array_slice($list, 0, $i));
                    break;
                }
            }
        }

        return [$list, $pos];
    }
}
