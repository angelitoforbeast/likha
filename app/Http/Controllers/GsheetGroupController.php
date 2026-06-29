<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\GsheetGroup;

class GsheetGroupController extends Controller
{
    public function index()
    {
        $groups = GsheetGroup::orderBy('sort_order')->orderBy('id')->get();
        return view('gsheet_groups.index', compact('groups'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'likha_url' => 'nullable|url',
            'macro_url' => 'nullable|url',
            'after_url' => 'nullable|url',
        ]);

        GsheetGroup::create($data);

        return back()->with('success', '✅ Group added.');
    }

    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'likha_url' => 'nullable|url',
            'macro_url' => 'nullable|url',
            'after_url' => 'nullable|url',
        ]);

        $group = GsheetGroup::findOrFail($id);
        $group->update($data);

        return back()->with('success', '✅ Group updated.');
    }

    public function destroy($id)
    {
        GsheetGroup::findOrFail($id)->delete();
        return back()->with('success', '🗑️ Group deleted.');
    }

    // Write "STOP" to the flag cells:
    //  - Macro       → LINKS!G1
    //  - After-macro → API KEY!C1
    public function stop($id)
    {
        $res = $this->writeFlag($id, 'STOP');
        $ok = ($res['macro']['ok'] ?? false) && ($res['after']['ok'] ?? false);
        return back()->with($ok ? 'success' : 'error', $this->summarizeFlag('🛑 STOP sent', $res));
    }

    // Clear the flag cells (resume).
    public function resume($id)
    {
        $res = $this->writeFlag($id, null);
        $ok = ($res['macro']['ok'] ?? false) && ($res['after']['ok'] ?? false);
        return back()->with($ok ? 'success' : 'error', $this->summarizeFlag('▶️ Resume (flags cleared)', $res));
    }

    // Delete rows (row 2 → bottom, keep header) sa log sheets ng AFTER-MACRO (3rd) sheet:
    //  GPT_VERIFY, GPT_DEBUG, GPT_NAMEADDR  — katumbas ng CLEAR_LOGS() App Script.
    public function clearLogs($id)
    {
        $group = GsheetGroup::findOrFail($id);
        $sheetId = $this->extractSpreadsheetId($group->after_url);
        if (!$sheetId) {
            return back()->with('error', '⚠️ After-macro: walang/maling link.');
        }

        $targets = ['GPT_VERIFY', 'GPT_DEBUG', 'GPT_NAMEADDR'];

        try {
            $service = $this->makeSheetsService(true); // write scope

            $meta = $service->spreadsheets->get($sheetId, [
                'fields' => 'sheets.properties(sheetId,title,gridProperties.rowCount)',
            ]);

            $requests = [];
            $report   = [];
            $found    = [];

            foreach ($meta->getSheets() as $s) {
                $props = $s->getProperties();
                $title = $props->getTitle();
                if (!in_array($title, $targets, true)) continue;

                $found[] = $title;
                $rowCount = $props->getGridProperties()->getRowCount();

                if ($rowCount > 1) {
                    $requests[] = new \Google_Service_Sheets_Request([
                        'deleteDimension' => [
                            'range' => [
                                'sheetId'    => $props->getSheetId(),
                                'dimension'  => 'ROWS',
                                'startIndex' => 1,          // row 2 (0-based) — keep header
                                'endIndex'   => $rowCount,  // hanggang dulo
                            ],
                        ],
                    ]);
                    $report[] = $title . ': 🗑️';
                } else {
                    $report[] = $title . ': walang data';
                }
            }

            foreach ($targets as $t) {
                if (!in_array($t, $found, true)) $report[] = $t . ': ⚠️ not found';
            }

            if (!empty($requests)) {
                $batch = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => $requests]);
                $service->spreadsheets->batchUpdate($sheetId, $batch);
            }

            return back()->with('success', '🧹 Clear logs (after-macro) — ' . implode(' · ', $report));
        } catch (\Throwable $e) {
            return back()->with('error', '⚠️ Clear logs failed: ' . $this->friendlyError($e->getMessage()));
        }
    }

    // AJAX: read the 3 live values for one group.
    //  - Likha       → TO ENCODER!L1   (=importrange(B,"TO ENCODER!L1"))
    //  - Macro       → LINKS!E2        (=importrange(C,"LINKS!e2"))      task count
    //  - After-macro → DATABASE!N1 - 1 (=importrange(D,"DATABASE!N1")-1) last row
    public function values($id)
    {
        $group = GsheetGroup::findOrFail($id);

        $service = $this->makeSheetsService();

        $likha = $this->readSheet($service, $group->likha_url, "'TO ENCODER'!L1");
        $macro = $this->readSheet($service, $group->macro_url, "'LINKS'!E2");
        $after = $this->readSheet($service, $group->after_url, "'DATABASE'!N1");

        // After-macro last row = DATABASE!N1 - 1
        $lastRow = null;
        if ($after['error'] === null && is_numeric($after['value'])) {
            $after['value'] = $after['value'] - 1;
            $lastRow = (int) $after['value'];
        }

        // Last-row peek + alignment check (same row sa magkabila):
        //  - After-macro: DATABASE!B{lastRow}
        //  - Likha:       All Orders!C{lastRow}
        $afterB = ['value' => null, 'error' => null];
        $likhaC = ['value' => null, 'error' => null];
        if ($lastRow && $lastRow >= 2) {
            // After-macro: DATABASE!K{lastRow} → FB NAME lang ang kukunin
            $afterB = $this->readValue($service, $group->after_url, "'DATABASE'!K{$lastRow}");
            if ($afterB['error'] === null) {
                $afterB['value'] = $this->extractFbName($afterB['value']);
            }
            // Likha: All Orders!C{lastRow} (raw)
            $likhaC = $this->readValue($service, $group->likha_url, "'All Orders'!C{$lastRow}");
        }

        return response()->json([
            'likha'    => $likha,
            'macro'    => $macro,
            'after'    => $after,
            'last_row' => $lastRow,
            'after_b'  => $afterB,
            'likha_c'  => $likhaC,
        ]);
    }

    // Delete rows 3 → end_row (shift up) across the 5 targets:
    //  Likha:       All Orders (whole rows) · TO WEBSITE!I (col) · TO ENCODER!J (col)
    //  After-macro: DATABASE (whole rows)   · DATABASE - MIRRORED!Q (col)
    // NOTE: walang auto-stop — i-Stop muna ng user ang scripts.
    public function deleteRows(Request $request, $id)
    {
        $data = $request->validate([
            'end_row' => 'required|integer|min:3',
        ]);
        $endRow = (int) $data['end_row'];

        $group = GsheetGroup::findOrFail($id);
        $service = $this->makeSheetsService(true); // write scope

        $report = [];

        // Likha (All Orders rows, TO WEBSITE!I, TO ENCODER!J)
        $likhaId = $this->extractSpreadsheetId($group->likha_url);
        $report[] = $likhaId
            ? $this->deleteInSpreadsheet($service, $likhaId, $endRow, 'Likha', [
                ['All Orders', 'rows', null],
                ['TO WEBSITE', 'col', 8],   // column I (0-based)
                ['TO ENCODER', 'col', 9],   // column J
            ])
            : 'Likha: ⚠️ walang/maling link';

        // After-macro (DATABASE rows, DATABASE - MIRRORED!Q)
        $afterId = $this->extractSpreadsheetId($group->after_url);
        $report[] = $afterId
            ? $this->deleteInSpreadsheet($service, $afterId, $endRow, 'After-macro', [
                ['DATABASE', 'rows', null],
                ['DATABASE - MIRRORED', 'col', 16],  // column Q
            ])
            : 'After-macro: ⚠️ walang/maling link';

        return back()->with('success', "🗑️ Delete rows 3–{$endRow} — " . implode(' | ', $report));
    }

    // $targets: array of [tabName, 'rows'|'col', colIndex|null]
    private function deleteInSpreadsheet(\Google\Service\Sheets $service, string $sheetId, int $endRow, string $label, array $targets): string
    {
        try {
            $meta = $service->spreadsheets->get($sheetId, [
                'fields' => 'sheets.properties(sheetId,title,gridProperties.rowCount)',
            ]);
        } catch (\Throwable $e) {
            return "{$label}: ⚠️ " . $this->friendlyError($e->getMessage());
        }

        $map = [];
        foreach ($meta->getSheets() as $s) {
            $p = $s->getProperties();
            $map[$p->getTitle()] = [
                'gid'  => $p->getSheetId(),
                'rows' => $p->getGridProperties()->getRowCount(),
            ];
        }

        $parts = [];

        foreach ($targets as [$tab, $mode, $col]) {
            if (!isset($map[$tab])) { $parts[] = "{$tab} ⚠️ not found"; continue; }

            $gid = $map[$tab]['gid'];
            $end = min($endRow, $map[$tab]['rows']); // clamp sa grid
            if ($end < 3) { $parts[] = "{$tab} (walang ide-delete)"; continue; }

            if ($mode === 'rows') {
                $req = new \Google_Service_Sheets_Request([
                    'deleteDimension' => [
                        'range' => [
                            'sheetId'    => $gid,
                            'dimension'  => 'ROWS',
                            'startIndex' => 2,      // row 3 (0-based)
                            'endIndex'   => $end,   // inclusive ng row $end
                        ],
                    ],
                ]);
            } else { // col — delete cells sa isang column, shift ROWS up
                $req = new \Google_Service_Sheets_Request([
                    'deleteRange' => [
                        'range' => [
                            'sheetId'          => $gid,
                            'startRowIndex'    => 2,
                            'endRowIndex'      => $end,
                            'startColumnIndex' => $col,
                            'endColumnIndex'   => $col + 1,
                        ],
                        'shiftDimension' => 'ROWS',
                    ],
                ]);
            }

            // ISOLATED per-tab batchUpdate — hindi atomic across tabs, kaya
            // ang pagpalpak ng isang tab ay HINDI nakaka-apekto sa iba.
            try {
                $batch = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => [$req]]);
                $service->spreadsheets->batchUpdate($sheetId, $batch);
                $parts[] = "{$tab} ✅";
            } catch (\Throwable $e) {
                $parts[] = "{$tab} ⚠️ " . $this->friendlyError($e->getMessage());
            }
        }

        return "{$label}: " . implode(' · ', $parts);
    }

    // ── helpers ───────────────────────────────────────────────

    private function makeSheetsService(bool $write = false): \Google\Service\Sheets
    {
        $client = new \Google_Client();
        $client->setApplicationName('Laravel GSheet');
        $client->setScopes([$write
            ? \Google\Service\Sheets::SPREADSHEETS
            : \Google\Service\Sheets::SPREADSHEETS_READONLY]);
        $client->setAuthConfig(storage_path('app/credentials.json'));
        $client->setAccessType('offline');

        return new \Google\Service\Sheets($client);
    }

    // Reads the spreadsheet TITLE (para ma-verify ang link) + the cell value.
    private function readSheet(\Google\Service\Sheets $service, ?string $url, string $range): array
    {
        $sheetId = $this->extractSpreadsheetId($url);
        if (!$sheetId) {
            return ['title' => null, 'value' => null, 'error' => 'Walang/maling link'];
        }

        // 1) spreadsheet title (also surfaces access errors early)
        $title = null;
        try {
            $meta = $service->spreadsheets->get($sheetId, ['fields' => 'properties.title']);
            $title = $meta->getProperties()->getTitle();
        } catch (\Throwable $e) {
            return ['title' => null, 'value' => null, 'error' => $this->friendlyError($e->getMessage())];
        }

        // 2) cell value
        try {
            $resp = $service->spreadsheets_values->get($sheetId, $range, [
                'valueRenderOption' => 'UNFORMATTED_VALUE',
            ]);
            $values = $resp->getValues();
            $val = $values[0][0] ?? null;
            return ['title' => $title, 'value' => $val, 'error' => null];
        } catch (\Throwable $e) {
            return ['title' => $title, 'value' => null, 'error' => $this->friendlyError($e->getMessage())];
        }
    }

    // Write (or clear if $value === null) the flag cells for one group.
    private function writeFlag($id, ?string $value): array
    {
        GsheetGroup::findOrFail($id); // 404 if missing
        $group = GsheetGroup::find($id);

        $service = $this->makeSheetsService(true); // write scope

        return [
            'macro' => $this->writeCell($service, $group->macro_url, "'LINKS'!G1", $value),
            'after' => $this->writeCell($service, $group->after_url, "'API KEY'!C1", $value),
        ];
    }

    private function writeCell(\Google\Service\Sheets $service, ?string $url, string $range, ?string $value): array
    {
        $sheetId = $this->extractSpreadsheetId($url);
        if (!$sheetId) {
            return ['ok' => false, 'error' => 'Walang/maling link'];
        }

        try {
            if ($value === null || $value === '') {
                $service->spreadsheets_values->clear($sheetId, $range, new \Google_Service_Sheets_ClearValuesRequest());
            } else {
                $body = new \Google_Service_Sheets_ValueRange(['values' => [[$value]]]);
                $service->spreadsheets_values->update($sheetId, $range, $body, ['valueInputOption' => 'RAW']);
            }
            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $this->friendlyError($e->getMessage())];
        }
    }

    private function summarizeFlag(string $prefix, array $res): string
    {
        $labels = ['macro' => 'Macro (LINKS!G1)', 'after' => 'After-macro (API KEY!C1)'];
        $parts = [];
        foreach ($labels as $k => $label) {
            $r = $res[$k] ?? ['ok' => false, 'error' => '?'];
            $parts[] = $label . ': ' . ($r['ok'] ? '✅' : '⚠️ ' . $r['error']);
        }
        return $prefix . ' — ' . implode(' · ', $parts);
    }

    // Extract "FB NAME: <name>" galing sa Column K chat blob.
    private function extractFbName(?string $text): ?string
    {
        if ($text === null || $text === '') return null;
        if (preg_match('/FB\s*NAME:\s*([^\r\n]+)/i', (string) $text, $m)) {
            return rtrim(trim($m[1]), ', ');
        }
        return null;
    }

    // Value-only read (walang title fetch) — para sa per-row peeks.
    private function readValue(\Google\Service\Sheets $service, ?string $url, string $range): array
    {
        $sheetId = $this->extractSpreadsheetId($url);
        if (!$sheetId) {
            return ['value' => null, 'error' => 'Walang/maling link'];
        }
        try {
            $resp = $service->spreadsheets_values->get($sheetId, $range, [
                'valueRenderOption' => 'UNFORMATTED_VALUE',
            ]);
            $values = $resp->getValues();
            return ['value' => $values[0][0] ?? null, 'error' => null];
        } catch (\Throwable $e) {
            return ['value' => null, 'error' => $this->friendlyError($e->getMessage())];
        }
    }

    private function extractSpreadsheetId(?string $url): ?string
    {
        if (!$url) return null;
        preg_match('/\/d\/([a-zA-Z0-9-_]+)/', $url, $matches);
        return $matches[1] ?? null;
    }

    private function friendlyError(string $msg): string
    {
        $m = strtolower($msg);
        if (str_contains($m, 'permission') || str_contains($m, '403') || str_contains($m, 'forbidden') || str_contains($m, 'caller does not have permission')) {
            return 'Walang Editor access ang service account — i-share (Editor) ang sheet';
        }
        if (str_contains($m, 'unable to parse range') || str_contains($m, 'not found') || str_contains($m, '404')) {
            return 'Hindi makita ang sheet/cell (tama ba ang tab name?)';
        }
        // Surface a short snippet ng totoong error para sa diagnosis (hal. array/protected range)
        $short = trim(preg_replace('/\s+/', ' ', $msg));
        if (strlen($short) > 160) $short = substr($short, 0, 160) . '…';
        return $short;
    }
}
