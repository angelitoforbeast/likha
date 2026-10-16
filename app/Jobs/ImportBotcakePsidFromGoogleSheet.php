<?php

namespace App\Jobs;

use App\Models\BotcakePsidSetting;
use App\Models\BotcakePsidImportRun;
use App\Models\BotcakePsidImportEvent;
use App\Services\BotcakePsidMatcher;
use App\Services\BotcakePsidSheet;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ImportBotcakePsidFromGoogleSheet implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Isang import lang sa isang pagkakataon (button, oras-oras at 03:00), para walang dobleng sulat sa J.
    public const LOCK_KEY = 'botcake-psid-import';
    private const LOCK_SECONDS = 1800;

    public int $timeout = 1200; // 20 minutes

    protected ?string $cutoffDateTime;
    protected ?int $runId;
    protected bool $cleanSheet;

    public function __construct(?string $cutoffDateTime = null, ?int $runId = null, bool $cleanSheet = false)
    {
        $this->cutoffDateTime = $cutoffDateTime;
        $this->runId = $runId;
        $this->cleanSheet = $cleanSheet;
    }

    public function handle(): void
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);
        if (!$lock->get()) {
            $this->updateRun([
                'status'       => 'done',
                'last_message' => 'Skipped: may ibang PSID import na tumatakbo.',
            ]);
            return;
        }

        try {
            $this->runImport();
        } finally {
            $lock->release();
        }
    }

    protected function runImport(): void
    {
        $run = $this->getRun();

        try {
            if ($run) {
                $run->update([
                    'status'       => 'running',
                    'last_message' => 'Starting...',
                    'last_error'   => null,
                ]);
            }

            $sheet    = app(BotcakePsidSheet::class);
            $matcher  = app(BotcakePsidMatcher::class);
            $settings = BotcakePsidSetting::all();

            // Ang pagbura ay bago ang import: ang mga row na naitala na lang ang nawawala, ang bago ay iimport pa.
            if ($this->cleanSheet) {
                $deleted = 0;
                foreach ($settings as $setting) {
                    $target = $this->target($setting);
                    if (!$target) continue;
                    try {
                        $deleted += $matcher->cleanSheet($sheet, $setting, $target[0], $target[1]);
                    } catch (\Throwable $e) {
                        Log::error('ImportBotcakePsid: clean failed for setting ID ' . $setting->id . ' - ' . $e->getMessage());
                        $this->addEvent([
                            'type'       => 'error',
                            'setting_id' => $setting->id,
                            'gsheet_name'=> $setting->gsheet_name ?? null,
                            'message'    => 'Clean failed: ' . $e->getMessage(),
                        ]);
                    }
                }
                Log::info('ImportBotcakePsid: daily clean deleted ' . $deleted . ' sheet rows.');
                $this->updateRun(['total_deleted_rows' => $deleted, 'last_message' => "Deleted {$deleted} processed sheet rows"]);
                $this->addEvent(['type' => 'clean_done', 'message' => "Deleted {$deleted} processed sheet rows"]);
            }

            // Bawat run (kahit walang bagong sheet row): subukan ulit ang mga naghihintay na PSID.
            $retry = $matcher->retryWaiting();
            $this->updateRun([
                'total_matched_from_waiting' => $retry['matched'],
                'total_expired'              => $retry['expired'],
                'total_conflicts'            => $retry['conflicts'],
                'last_message'               => 'Waiting PSIDs retried',
            ]);

            if ($settings->isEmpty()) {
                Log::warning('ImportBotcakePsid: no settings found.');
            }

            foreach ($settings as $setting) {
                try {
                    $this->updateRun([
                        'current_setting_id'  => $setting->id,
                        'current_gsheet_name' => $setting->gsheet_name ?? null,
                        'last_message'        => 'Processing setting #' . $setting->id,
                    ]);

                    $this->addEvent([
                        'type'       => 'setting_start',
                        'setting_id' => $setting->id,
                        'gsheet_name'=> $setting->gsheet_name ?? null,
                        'message'    => 'Setting started',
                    ]);

                    $this->processSetting($sheet, $matcher, $setting);

                    $this->addEvent([
                        'type'       => 'setting_done',
                        'setting_id' => $setting->id,
                        'gsheet_name'=> $setting->gsheet_name ?? null,
                        'message'    => 'Setting finished',
                    ]);
                } catch (\Throwable $e) {
                    Log::error(
                        'ImportBotcakePsid: error processing setting ID ' . $setting->id . ' - ' . $e->getMessage(),
                        ['trace' => $e->getTraceAsString()]
                    );

                    $this->updateRun([
                        'last_error'   => 'Setting ID ' . $setting->id . ': ' . $e->getMessage(),
                        'last_message' => 'Error in one setting. Continuing...',
                    ]);

                    $this->addEvent([
                        'type'       => 'error',
                        'setting_id' => $setting->id,
                        'gsheet_name'=> $setting->gsheet_name ?? null,
                        'message'    => $e->getMessage(),
                    ]);

                    // continue to next setting
                }
            }

            $copies = $matcher->copyReorders();
            $this->updateRun(['total_reorder_copies' => $copies]);

            $this->updateRun([
                'status'       => 'done',
                'last_message' => $settings->isEmpty() ? 'No settings found.' : 'All settings processed.',
            ]);

            $this->addEvent([
                'type'    => 'run_done',
                'message' => 'Import run finished',
            ]);
        } catch (\Throwable $e) {
            Log::error('ImportBotcakePsid: fatal - ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

            $this->updateRun([
                'status'     => 'failed',
                'last_error' => $e->getMessage(),
                'last_message' => 'Run failed.',
            ]);

            $this->addEvent([
                'type'    => 'error',
                'message' => 'Fatal: ' . $e->getMessage(),
            ]);
        }
    }

    protected function getRun(): ?BotcakePsidImportRun
    {
        if (!$this->runId) return null;
        return BotcakePsidImportRun::find($this->runId);
    }

    protected function updateRun(array $data): void
    {
        try {
            $run = $this->getRun();
            if (!$run) return;
            $run->update($data);
        } catch (\Throwable $e) {
            // never break the job due to UI updates
        }
    }

    protected function addEvent(array $data): void
    {
        try {
            if (!$this->runId) return;

            $payload = array_merge([
                'run_id' => $this->runId,
            ], $data);

            BotcakePsidImportEvent::create($payload);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /** @return array{0:string,1:string}|null [spreadsheetId, sheet name] */
    protected function target(BotcakePsidSetting $setting): ?array
    {
        $target = BotcakePsidSheet::target($setting);
        if (!$target) {
            Log::error('ImportBotcakePsid: invalid sheet URL', ['setting_id' => $setting->id]);
            $this->updateRun(['last_message' => 'Invalid sheet URL for setting #' . $setting->id]);
        }

        return $target;
    }

    protected function cutoff(): ?Carbon
    {
        if (empty($this->cutoffDateTime)) return null;

        try {
            return Carbon::parse($this->cutoffDateTime, 'Asia/Manila');
        } catch (\Throwable $e) {
            Log::warning('ImportBotcakePsid: invalid cutoff datetime, ignoring.', [
                'cutoff' => $this->cutoffDateTime,
                'error'  => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Binabasa ang buong sheet at pinoproseso ang bawat row na blank ang J. Walang K1 seed o cursor:
     * maliit ang sheet dahil binubura araw-araw ang naitala na, at ang pagbura ay nagpapalipat ng row numbers,
     * kaya ang row number ay galing mismo sa pagbasang ito, ilang sandali bago isulat ang J.
     */
    protected function processSetting(BotcakePsidSheet $sheet, BotcakePsidMatcher $matcher, BotcakePsidSetting $setting): void
    {
        $target = $this->target($setting);
        if (!$target) return;
        [$spreadsheetId, $sheetName] = $target;

        $this->updateRun([
            'current_sheet_name' => $sheetName,
            'seed_row'           => 2,
        ]);

        $cutoff = $this->cutoff();
        $rows   = $sheet->readRows($spreadsheetId, $sheetName);

        $statuses = [];
        $imported = $waiting = $conflicts = $notExisting = $skipped = 0;

        foreach ($rows as $rowNo => $row) {
            if ($rowNo < 2) continue; // header

            $row = array_pad($row, 10, '');

            // ONLY BLANK J gets edited
            if (trim((string) $row[9]) !== '') continue;

            // totally blank row -> keep J
            $hasAny = false;
            for ($i = 0; $i <= 8; $i++) {
                if ((string) $row[$i] !== '') { $hasAny = true; break; }
            }
            if (!$hasAny) continue;

            // cutoff: if date exists but > cutoff or invalid -> skip (leave blank)
            $dateStr = trim((string) $row[3]); // D
            if ($cutoff && $dateStr !== '') {
                $cellDate = $this->parseCellDateTime($dateStr);
                if (!$cellDate || $cellDate->gt($cutoff)) {
                    $skipped++;
                    continue;
                }
            }

            $pageName = $matcher->norm($row[0]);
            $fullName = $matcher->norm($row[1]);
            $psid     = $matcher->norm($row[2]);

            if ($pageName === '' || $fullName === '' || $psid === '') {
                $statuses[$rowNo] = 'not existing';
                $notExisting++;
                continue;
            }

            $status = $matcher->recordSheetRow($setting, $pageName, $fullName, $psid, $dateStr);
            $statuses[$rowNo] = BotcakePsidMatcher::SHEET_LABELS[$status];

            if ($status === BotcakePsidMatcher::MATCHED) $imported++;
            elseif ($status === BotcakePsidMatcher::WAITING) $waiting++;
            elseif ($status === BotcakePsidMatcher::CONFLICT) $conflicts++;
        }

        if (!empty($statuses)) {
            try {
                $sheet->writeStatuses($spreadsheetId, $sheetName, $statuses);
            } catch (\Throwable $e) {
                $this->updateRun([
                    'last_error'   => $e->getMessage(),
                    'last_message' => 'batchUpdate failed',
                ]);
                throw $e;
            }
        }

        $run = $this->getRun();
        if ($run) {
            $this->updateRun([
                'total_imported'     => (int) $run->total_imported + $imported,
                'total_not_existing' => (int) $run->total_not_existing + $notExisting,
                'total_skipped'      => (int) $run->total_skipped + $skipped,
                'total_waiting'      => (int) $run->total_waiting + $waiting,
                'total_conflicts'    => (int) $run->total_conflicts + $conflicts,
                'last_message'       => 'Setting #' . $setting->id . ': ' . count($statuses) . ' rows written',
            ]);
        }

        $rowNos = array_keys($statuses);
        $this->addEvent([
            'type'         => 'batch_done',
            'setting_id'   => $setting->id,
            'gsheet_name'  => $setting->gsheet_name ?? null,
            'sheet_name'   => $sheetName,
            'batch_no'     => 1,
            'start_row'    => $rowNos ? min($rowNos) : null,
            'end_row'      => $rowNos ? max($rowNos) : null,
            'rows_in_batch'=> count($statuses),
            'imported'     => $imported,
            'not_existing' => $notExisting,
            'skipped'      => $skipped,
            'message'      => "Wrote " . count($statuses) . " statuses to J; waiting={$waiting}, conflict={$conflicts}",
        ]);
    }

    protected function parseCellDateTime(string $dateStr): ?Carbon
    {
        $dateStr = trim($dateStr);
        if ($dateStr === '') return null;

        if (is_numeric($dateStr)) {
            $n = (float)$dateStr;
            if ($n > 20000 && $n < 80000) {
                $unixSeconds = (int)round(($n - 25569) * 86400);
                return Carbon::createFromTimestamp($unixSeconds, 'Asia/Manila');
            }
        }

        $formats = [
            'H:i:s d-m-Y',
            'H:i d-m-Y',
            'G:i d-m-Y',
            'G:i:s d-m-Y',

            'Y-m-d',
            'Y-m-d H:i',
            'Y-m-d G:i',
            'Y-m-d H:i:s',
            'Y-m-d G:i:s',
        ];

        foreach ($formats as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $dateStr);
            if ($dt !== false) {
                return Carbon::instance($dt)->setTimezone('Asia/Manila');
            }
        }

        return null;
    }
}
