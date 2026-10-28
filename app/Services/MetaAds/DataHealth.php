<?php

namespace App\Services\MetaAds;

use App\Models\UploadLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang kalusugan ng data ng Ads Manager report (ads_manager_reports): ang mga row na tinanggihan dahil bumaba ang numero.
 * Ang bawat upload o Sync run na may tinanggihan ay may isang upload_logs row (type ads_manager_refused, path "log:<id>"
 * ng pinanggalingan, error_rows = ilan, protected_details = source, log_id, count, rows). Walang bagong table.
 */
class DataHealth
{
    public const REFUSED_TYPE = 'ads_manager_refused';
    /** Ilang tinanggihang row ang itinatabi ang detalye kada upload o run (lahat ay binibilang pa rin). */
    public const LIST_MAX = 200;

    /**
     * @param string $kind upload | sync
     * @param list<array{day:string, ad_id:string, account:?string, metrics:array}> $rows ang refused ng importRows
     */
    public static function recordRefusals(UploadLog $source, string $kind, array $rows): void
    {
        if (!$rows) return;

        // Ang account na wala sa row (upload na walang Account ID): mula sa ad_campaign_creatives ng ad na iyon.
        $missing = array_values(array_unique(array_column(array_filter($rows, fn ($r) => ($r['account'] ?? null) === null), 'ad_id')));
        $known = [];
        if ($missing && Schema::hasTable('ad_campaign_creatives')) {
            foreach (array_chunk($missing, 500) as $chunk) {
                $known += DB::table('ad_campaign_creatives')->whereIn('ad_id', $chunk)->whereNotNull('account_id')->pluck('account_id', 'ad_id')->all();
            }
        }
        foreach ($rows as $i => $r) $rows[$i]['account'] = $r['account'] ?? (isset($known[$r['ad_id']]) ? (string) $known[$r['ad_id']] : null);

        $n = count($rows);
        UploadLog::create([
            'type' => self::REFUSED_TYPE, 'disk' => $kind === 'sync' ? 'api' : 'upload', 'path' => 'log:' . $source->id,
            'original_name' => mb_substr(self::label($n) . ' · #' . $source->id . ' ' . $source->original_name, 0, 250),
            'status' => 'done', 'user_id' => $source->user_id, 'processed_rows' => $n, 'error_rows' => $n,
            'started_at' => now(), 'finished_at' => now(),
            'protected_details' => ['source' => $kind, 'log_id' => $source->id, 'count' => $n, 'rows' => array_slice($rows, 0, self::LIST_MAX)],
        ]);
    }

    /** @return array<int, array{count:int, rows:list<array>}> source log id => ang mga tinanggihan (wala = walang key) */
    public static function refusals(array $logIds): array
    {
        $logIds = array_values(array_filter(array_map('intval', $logIds)));
        if (!$logIds) return [];

        $out = [];
        foreach (UploadLog::where('type', self::REFUSED_TYPE)->whereIn('path', array_map(fn ($id) => 'log:' . $id, $logIds))->get() as $r) {
            $d = (array) $r->protected_details;
            $out[(int) ($d['log_id'] ?? 0)] = ['count' => (int) $r->error_rows, 'rows' => (array) ($d['rows'] ?? [])];
        }

        return $out;
    }

    /** "1 row refused (lower values)" / "3 rows refused (lower values)" */
    public static function label(int $n): string
    {
        return $n . ' ' . ($n === 1 ? 'row' : 'rows') . ' refused (lower values)';
    }

    /** 1500 → "1,500"; 99.5 → "99.50" */
    public static function num(int|float $n): string
    {
        return number_format($n, floor($n) == $n ? 0 : 2);
    }
}
