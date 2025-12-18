<?php

namespace App\Http\Controllers;

use App\Models\JntChatblastGsheetSetting;
use Google\Client as GoogleClient;
use Google\Service\Sheets;
use Illuminate\Http\Request;

class JntChatblastGsheetController extends Controller
{
    public function settings()
    {
        $settings = JntChatblastGsheetSetting::latest('id')->get();

        // Active setting = latest
        $active = JntChatblastGsheetSetting::latest('id')->first();

        return view('jnt.chatblast.gsheet_settings', compact('settings', 'active'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'sheet_url'   => ['required', 'url'],
            'sheet_range' => ['required', 'string'], // ex: Export!A:K
        ]);

        $sheetId = $this->extractSpreadsheetId($request->sheet_url);
        if (!$sheetId) return back()->with('error', 'Invalid Google Sheet URL.');

        // optional: fetch gsheet title
        $title = null;
        try {
            $client = new GoogleClient();
            $client->setApplicationName('Laravel JNT Chatblast Settings');
            $client->setScopes([Sheets::SPREADSHEETS_READONLY]);
            $client->setAuthConfig(storage_path('app/credentials.json'));
            $client->setAccessType('offline');

            $service   = new Sheets($client);
            $sheetMeta = $service->spreadsheets->get($sheetId);
            $title     = $sheetMeta->getProperties()->getTitle();
        } catch (\Throwable $e) {
            // ok lang kahit di ma-fetch
        }

        JntChatblastGsheetSetting::create([
            'gsheet_name' => $title,
            'sheet_url'   => trim($request->sheet_url),
            'sheet_range' => trim($request->sheet_range),
        ]);

        return back()->with('success', '✅ Setting saved. Active = latest (highest ID).');
    }

    public function destroy($id)
    {
        JntChatblastGsheetSetting::findOrFail($id)->delete();
        return back()->with('success', '✅ Setting deleted.');
    }

    private function extractSpreadsheetId(string $url): ?string
    {
        if (preg_match('/\/d\/([a-zA-Z0-9-_]+)/', $url, $m)) return $m[1];
        return null;
    }
}
