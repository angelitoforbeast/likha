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

    // AJAX: read the 3 live values for one group.
    //  - Likha       → TO ENCODER!L1   (=importrange(B,"TO ENCODER!L1"))
    //  - Macro       → LINKS!E2        (=importrange(C,"LINKS!e2"))      task count
    //  - After-macro → DATABASE!N1 - 1 (=importrange(D,"DATABASE!N1")-1) last row
    public function values($id)
    {
        $group = GsheetGroup::findOrFail($id);

        $service = $this->makeSheetsService();

        $likha = $this->readCell($service, $group->likha_url, "'TO ENCODER'!L1");
        $macro = $this->readCell($service, $group->macro_url, "'LINKS'!E2");
        $after = $this->readCell($service, $group->after_url, "'DATABASE'!N1");

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

    private function makeSheetsService(): \Google\Service\Sheets
    {
        $client = new \Google_Client();
        $client->setApplicationName('Laravel GSheet');
        $client->setScopes([\Google\Service\Sheets::SPREADSHEETS_READONLY]);
        $client->setAuthConfig(storage_path('app/credentials.json'));
        $client->setAccessType('offline');

        return new \Google\Service\Sheets($client);
    }

    private function readCell(\Google\Service\Sheets $service, ?string $url, string $range): array
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
            $val = $values[0][0] ?? null;
            return ['value' => $val, 'error' => null];
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
        if (str_contains($m, 'permission') || str_contains($m, '403') || str_contains($m, 'forbidden')) {
            return 'Walang access ang service account — i-share ang sheet';
        }
        if (str_contains($m, 'unable to parse range') || str_contains($m, 'not found') || str_contains($m, '400')) {
            return 'Hindi makita ang sheet/cell (tama ba ang tab name?)';
        }
        return 'Error sa pagkuha ng value';
    }
}
