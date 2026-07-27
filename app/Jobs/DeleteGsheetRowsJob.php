<?php

namespace App\Jobs;

use App\Models\GsheetGroup;
use App\Models\GsheetDeletionRun;
use Google_Client;
use Google_Service_Sheets;
use Google_Service_Sheets_Request;
use Google_Service_Sheets_BatchUpdateSpreadsheetRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeleteGsheetRowsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $runId;
    public $timeout = 600;
    public $tries = 1;

    public function __construct(int $runId)
    {
        $this->runId = $runId;
    }

    public function handle(): void
    {
        $run = GsheetDeletionRun::find($this->runId);
        if (!$run) return;

        $run->update(['status' => 'running', 'started_at' => now()]);

        $group = GsheetGroup::find($run->group_id);
        if (!$group) {
            $run->update(['status' => 'failed', 'message' => 'Group not found.', 'finished_at' => now()]);
            return;
        }

        $endRow = (int) $run->end_row;

        try {
            $service = $this->service();

            // BEFORE snapshot (yung mga displayed values)
            $run->before = $this->snapshot($service, $group);
            $run->result = [];
            $run->save();

            // Likha: All Orders (rows), TO WEBSITE!I, TO ENCODER!J
            $this->deleteTabs($service, $run, $this->extractId($group->likha_url), 'Likha', $endRow, [
                ['All Orders', 'rows', null],
                ['TO WEBSITE', 'col', 8],   // column I
                ['TO ENCODER', 'col', 9],   // column J
            ]);

            // After-macro: DATABASE (rows), DATABASE - MIRRORED!Q
            $this->deleteTabs($service, $run, $this->extractId($group->after_url), 'After-macro', $endRow, [
                ['DATABASE', 'rows', null],
                ['DATABASE - MIRRORED', 'col', 16],  // column Q
            ]);

            // AFTER snapshot
            $run->after = $this->snapshot($service, $group);

            $result = $run->result ?? [];
            $anyOk  = collect($result)->contains(fn ($e) => ($e['status'] ?? '') === 'ok');
            $anyErr = collect($result)->contains(fn ($e) => ($e['status'] ?? '') === 'error');

            $run->deleted_total = max(0, $endRow - 2); // rows 3..end (requested span)
            $run->status  = $anyErr ? ($anyOk ? 'done_with_errors' : 'failed') : 'done';
            $run->message = $this->summary($result);
            $run->finished_at = now();
            $run->save();
        } catch (\Throwable $e) {
            $run->update([
                'status'      => 'failed',
                'message'     => 'Fatal: ' . $e->getMessage(),
                'finished_at' => now(),
            ]);
        }
    }

    // ── per-tab delete (isolated; nag-aappend ng result kada tab para sa live polling) ──
    private function deleteTabs(Google_Service_Sheets $service, GsheetDeletionRun $run, ?string $sheetId, string $label, int $endRow, array $targets): void
    {
        if (!$sheetId) {
            $this->append($run, ['spreadsheet' => $label, 'tab' => '(all)', 'status' => 'error', 'error' => 'walang/maling link', 'deleted' => 0, 'rows_before' => null]);
            return;
        }

        try {
            $meta = $service->spreadsheets->get($sheetId, [
                'fields' => 'sheets.properties(sheetId,title,gridProperties.rowCount)',
            ]);
        } catch (\Throwable $e) {
            $this->append($run, ['spreadsheet' => $label, 'tab' => '(metadata)', 'status' => 'error', 'error' => $e->getMessage(), 'deleted' => 0, 'rows_before' => null]);
            return;
        }

        // Case-INSENSITIVE map (key = lower/trimmed title) — hindi na apektado ng casing/spacing.
        $map = [];
        foreach ($meta->getSheets() as $s) {
            $p = $s->getProperties();
            $map[strtolower(trim($p->getTitle()))] = ['gid' => $p->getSheetId(), 'rows' => $p->getGridProperties()->getRowCount()];
        }

        foreach ($targets as [$tab, $mode, $col]) {
            $entry = ['spreadsheet' => $label, 'tab' => $tab, 'mode' => $mode, 'rows_before' => null, 'deleted' => 0, 'status' => null, 'error' => null];

            $key = strtolower(trim($tab));
            if (!isset($map[$key])) {
                $entry['status'] = 'not_found';
                $this->append($run, $entry);
                continue;
            }

            $gid = $map[$key]['gid'];
            $entry['rows_before'] = $map[$key]['rows'];
            $end = min($endRow, $map[$key]['rows']); // clamp sa grid

            if ($end < 3) {
                $entry['status'] = 'empty';
                $this->append($run, $entry);
                continue;
            }

            if ($mode === 'rows') {
                $req = new Google_Service_Sheets_Request([
                    'deleteDimension' => [
                        'range' => ['sheetId' => $gid, 'dimension' => 'ROWS', 'startIndex' => 2, 'endIndex' => $end],
                    ],
                ]);
            } else {
                $req = new Google_Service_Sheets_Request([
                    'deleteRange' => [
                        'range' => ['sheetId' => $gid, 'startRowIndex' => 2, 'endRowIndex' => $end, 'startColumnIndex' => $col, 'endColumnIndex' => $col + 1],
                        'shiftDimension' => 'ROWS',
                    ],
                ]);
            }

            try {
                $batch = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => [$req]]);
                $service->spreadsheets->batchUpdate($sheetId, $batch);
                $entry['status']  = 'ok';
                $entry['deleted'] = $end - 2;
            } catch (\Throwable $e) {
                $entry['status'] = 'error';
                $entry['error']  = $e->getMessage();
            }
            $this->append($run, $entry);
        }
    }

    private function append(GsheetDeletionRun $run, array $entry): void
    {
        $r = $run->result ?? [];
        $r[] = $entry;
        $run->result = $r;
        $run->save();
    }

    private function snapshot(Google_Service_Sheets $service, GsheetGroup $group): array
    {
        $afterN1 = $this->readVal($service, $this->extractId($group->after_url), "'DATABASE'!N1");
        return [
            'likha_l1'      => $this->readVal($service, $this->extractId($group->likha_url), "'TO ENCODER'!L1"),
            'macro_e2'      => $this->readVal($service, $this->extractId($group->macro_url), "'LINKS'!E2"),
            'after_lastrow' => is_numeric($afterN1) ? ($afterN1 - 1) : null,
        ];
    }

    private function readVal(Google_Service_Sheets $service, ?string $sheetId, string $range)
    {
        if (!$sheetId) return null;
        try {
            $v = $service->spreadsheets_values->get($sheetId, $range, ['valueRenderOption' => 'UNFORMATTED_VALUE'])->getValues();
            return $v[0][0] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function summary(array $result): string
    {
        $parts = [];
        foreach ($result as $e) {
            $icon = match ($e['status'] ?? '') {
                'ok'        => '✅',
                'error'     => '⚠️ ' . ($e['error'] ?? ''),
                'not_found' => 'not found',
                'empty'     => 'walang data',
                default     => ($e['status'] ?? '?'),
            };
            $parts[] = ($e['spreadsheet'] ?? '') . '·' . ($e['tab'] ?? '') . ': ' . $icon;
        }
        return implode(' | ', $parts);
    }

    private function service(): Google_Service_Sheets
    {
        $client = new Google_Client();
        $client->setApplicationName('Laravel GSheet');
        $client->addScope(Google_Service_Sheets::SPREADSHEETS);
        $client->setAuthConfig(storage_path('app/credentials.json'));
        $client->setAccessType('offline');
        return new Google_Service_Sheets($client);
    }

    private function extractId(?string $url): ?string
    {
        if (!$url) return null;
        preg_match('/\/d\/([a-zA-Z0-9-_]+)/', $url, $m);
        return $m[1] ?? null;
    }
}
