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

        // After-macro = N1 - 1
        if ($after['error'] === null && is_numeric($after['value'])) {
            $after['value'] = $after['value'] - 1;
        }

        return response()->json([
            'likha' => $likha,
            'macro' => $macro,
            'after' => $after,
        ]);
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

    private function extractSpreadsheetId(?string $url): ?string
    {
        if (!$url) return null;
        preg_match('/\/d\/([a-zA-Z0-9-_]+)/', $url, $matches);
        return $matches[1] ?? null;
    }

    private function friendlyError(string $msg): string
    {
        $m = strtolower($msg);
        if (str_contains($m, 'permission') || str_contains($m, '403') || str_contains($m, 'forbidden')) {
            return 'Walang access ang service account — i-share ang sheet';
        }
        if (str_contains($m, 'unable to parse range') || str_contains($m, 'not found') || str_contains($m, '400')) {
            return 'Hindi makita ang sheet/cell (tama ba ang tab name?)';
        }
        return 'Error sa pagkuha ng value';
    }
}
