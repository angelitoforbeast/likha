<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MacroOutput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Models\DownloadedMacroOutputLog;
use Illuminate\Support\Facades\Schema;
use App\Models\PhoneWhitelist;
use App\Models\FbnameBlacklist;
use App\Models\KeywordBlacklist;

class MacroOutputController extends Controller
{
    // ✅ Replace ENTIRE pancakeMore() with this (returns customers_chat ONLY)
    public function pancakeMore(Request $request)
    {
        $request->validate([
            'fb_name' => 'required|string|max:255',
        ]);

        $fbName = trim((string) $request->fb_name);
        if ($fbName === '') {
            return response()->json([
                'status'  => 'error',
                'message' => 'fb_name is empty',
            ], 422);
        }

        // table exists?
        if (!Schema::hasTable('pancake_conversations')) {
            return response()->json([
                'status' => 'success',
                'found'  => false,
                'text'   => '',
            ]);
        }

        // ✅ exact match: pancake_conversations.full_name = fb_name
        $row = DB::table('pancake_conversations')
            ->select('id', 'full_name', 'customers_chat', 'created_at')
            ->where('full_name', '=', $fbName)
            ->orderByDesc('id')
            ->first();

        if (!$row) {
            return response()->json([
                'status' => 'success',
                'found'  => false,
                'text'   => '',
            ]);
        }

        $chat = trim((string)($row->customers_chat ?? ''));

        // optional cap to protect UI
        $max = 8000;
        if ($chat !== '' && mb_strlen($chat, 'UTF-8') > $max) {
            $chat = mb_substr($chat, 0, $max, 'UTF-8') . "\n\n[TRUNCATED]";
        }

        return response()->json([
            'status' => 'success',
            'found'  => true,
            'text'   => $chat, // ✅ customers_chat ONLY
        ]);
    }

    public function download(Request $request)
    {
        // ✅ Only Marketing - OIC can actually enable "download_all"
        // Normalize role (spaces, case, at uri ng dash: -, –, —)
        $userRoleRaw = Auth::user()?->employeeProfile?->role ?? '';
        $roleNorm = preg_replace('/\s+/u', ' ', trim((string)$userRoleRaw));
        $isMarketingOIC = preg_match('/^marketing\s*[-–—]\s*oic$/iu', $roleNorm) === 1;

        // Tanggapin '1' / on / true / boolean
        $dlRaw = $request->input('download_all');
        $dlParam = $request->boolean('download_all') || in_array($dlRaw, ['1', 1, 'on', 'true'], true);

        // Final guard
        $downloadAll = $isMarketingOIC && $dlParam;

        DownloadedMacroOutputLog::create([
            'timestamp'     => $request->input('date'),
            'page'          => $request->input('PAGE'),
            'downloaded_by' => Auth::user()?->name ?? 'Unknown',
            'downloaded_at' => Carbon::now(),
        ]);

        $query = DB::table('macro_output');

        // Step 1: Apply filters
        if ($request->has('date') && $request->date) {
            $formatted = date('d-m-Y', strtotime($request->date));
            $query->where('TIMESTAMP', 'like', "%$formatted");
        }

        if ($request->has('PAGE') && $request->PAGE) {
            $query->where('PAGE', $request->PAGE);
        }

        // Step 2: Validation & status restriction (only when NOT download_all)
        if (!$downloadAll) {
            // Only allow download if all filtered rows (excluding CANNOT PROCEED) have valid fields
            $hasMissingFields = (clone $query)->where(function ($q) {
                $q->where(function ($subQ) {
                    // ✅ Kung hindi "CANNOT PROCEED", kailangan valid lahat ng fields
                    $subQ->whereNotIn('STATUS', ['CANNOT PROCEED'])
                        ->where(function ($innerQ) {
                            $innerQ->whereNull('STATUS')->orWhere('STATUS', '')
                                ->orWhereNull('ITEM_NAME')->orWhere('ITEM_NAME', '')
                                ->orWhereRaw('CHAR_LENGTH(ITEM_NAME) > 50')
                                ->orWhereNull('COD')->orWhere('COD', '');
                        });
                });
            })->exists();

            if ($hasMissingFields) {
                return back()->with('error', 'Download FAILED: Some entries are missing STATUS, ITEM NAME, or COD.');
            }

            // Step 3: Restrict to only rows marked as "PROCEED"
            $query->where('STATUS', 'PROCEED');
        }

        // ✅ Step 3.2: SORTING (ITEM_NAME A–Z; blanks last)
        $wrap = fn (string $col) => DB::getQueryGrammar()->wrap($col);
        $ITEM = $wrap('ITEM_NAME');
        $FULL = $wrap('FULL NAME');

        $query
            ->orderByRaw("CASE WHEN {$ITEM} IS NULL OR TRIM({$ITEM}) = '' THEN 1 ELSE 0 END ASC")
            ->orderByRaw("TRIM({$ITEM}) ASC")
            ->orderByRaw("TRIM({$FULL}) ASC")
            ->orderByDesc('id');

        // Step 3.5: Select fields (now including fb_name)
        $records = $query->select(
            'FULL NAME',
            'PHONE NUMBER',
            'ADDRESS',
            'PROVINCE',
            'CITY',
            'BARANGAY',
            'ITEM_NAME',
            'COD',
            'fb_name'
        )->get();

        if ($records->isEmpty()) {
            return back()->with('error', 'Download FAILED: No entries found for the selected filters.');
        }

        // Step 4: Generate filename
        $pagePart = $request->PAGE ? preg_replace('/[^a-zA-Z0-9_]/', '_', $request->PAGE) : 'AllPages';
        $datePart = $request->date ?? now()->format('Y-m-d');
        $timePart = now()->format('H-i-s');
        $filename = "{$pagePart}_{$datePart}_{$timePart}.csv";

        // Step 5: Prepare CSV content
        $handle = fopen('php://temp', 'w+');

        // Load first rows from Excel template (kept as-is)
        $templatePath = resource_path('templates/exptemplete.xls');
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();
        $templateData = $sheet->rangeToArray('A1:N8', null, true, true, false);

        foreach ($templateData as $row) {
            fputcsv($handle, $row);
        }

        // Step 6: Append actual data rows (ROW 9+)
        // ✅ Uppercase all string data starting row 9 and below
        $UP = function ($v) {
            $s = is_null($v) ? '' : (string) $v;
            $s = trim($s);
            return $s === '' ? '' : mb_strtoupper($s, 'UTF-8');
        };

        foreach ($records as $row) {
            // ✅ uppercase fields (strings)
            $fullName = $UP($row->{'FULL NAME'} ?? '');
            $address  = $UP($row->ADDRESS ?? '');
            $prov     = $UP($row->PROVINCE ?? '');
            $city     = $UP($row->CITY ?? '');
            $brgy     = $UP($row->BARANGAY ?? '');
            $fbName   = $UP($row->fb_name ?? '');

            // keep phone + COD as-is (usually numeric/text digits)
            $phone = trim((string) ($row->{'PHONE NUMBER'} ?? ''));
            $cod   = trim((string) ($row->COD ?? ''));

            // Column H (ITEM NAME) -> uppercase too
            $colH = $UP($row->{'ITEM_NAME'} ?? '');     // Column H (8)
            $colJ = $colH ? strtok($colH, ' ') : '';    // Column J (10) first word

            fputcsv($handle, [
                $fullName,   // 1  A ✅
                $phone,      // 2  B
                $address,    // 3  C ✅
                $prov,       // 4  D ✅
                $city,       // 5  E ✅
                $brgy,       // 6  F ✅
                'EZ',        // 7  G
                $colH,       // 8  H ✅
                '0.5',       // 9  I
                $colJ,       // 10 J ✅
                '549',       // 11 K
                $cod,        // 12 L
                $colH,       // 13 M ✅ same as H
                $fbName      // 14 N ✅
            ]);
        }

        // Step 7: Output CSV
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return response($content, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename={$filename}",
        ]);
    }

    public function edit($id)
    {
        $record = MacroOutput::findOrFail($id);
        return view('macro_output.edit', compact('record'));
    }

    public function validateItems(Request $request)
{
    $ids = $request->input('ids', []);
    if (!is_array($ids)) $ids = [];

    $records = MacroOutput::whereIn('id', $ids)->get([
        'id', 'ITEM_NAME', 'COD', 'all_user_input'
    ]);

    $validIds = [];
    $invalidIds = [];
    $results = [];

    // ✅ set mo kung ano yung current max mo (20 o 50) depende sa gusto mo
    $MAX_ITEM_LEN = 50;

    foreach ($records as $record) {
        $invalids = []; // 🔴 hard invalid
        $warns    = []; // 🟧 soft warning (shop details mismatch)

        $item = trim((string)($record->ITEM_NAME ?? ''));
        $cod  = trim((string)($record->COD ?? ''));

        // =========================
        // 🔴 HARD RULES (INVALID)
        // =========================
        if ($item === '' || mb_strlen($item, 'UTF-8') > $MAX_ITEM_LEN) {
            $invalids['ITEM_NAME'] = true;
        }
        if ($cod === '') {
            $invalids['COD'] = true;
        }

        // ==========================================
        // 🟧 SOFT RULES (WARNING: SHOP DETAILS ONLY)
        // ==========================================
        $detailsText = (string)($record->all_user_input ?? '');
        $details = $this->extractShopDetails($detailsText);

        $expectedItemRaw = trim((string)($details['item'] ?? ''));
        $expectedCodNum  = (int)($details['expected_cod'] ?? 0);

        // normalize actual item: remove "1 x " prefix kung meron
        $actualItemRaw  = trim((string)($record->ITEM_NAME ?? ''));
        $actualItemBase = preg_replace('/^\s*\d+\s*x\s*/iu', '', $actualItemRaw);

        $expectedItem = $this->normItem($expectedItemRaw);
        $actualItem   = $this->normItem($actualItemBase);

        $actualCodNum = $this->codToInt((string)($record->COD ?? ''));

        // Item mismatch -> ORANGE (warning) only
        if ($expectedItem !== '' && $actualItem !== '' && $expectedItem !== $actualItem) {
            $warns['ITEM_NAME'] = true;
        }

        // COD mismatch vs expected -> ORANGE (warning) only
        if ($expectedCodNum > 0 && $actualCodNum > 0 && $expectedCodNum !== $actualCodNum) {
            $warns['COD'] = true;
        }

        // ✅ item_checker should be based ONLY on invalids
        $isValidByHardRules = empty($invalids);

        if ($isValidByHardRules) $validIds[] = (int) $record->id;
        else $invalidIds[] = (int) $record->id;

        $results[] = [
            'id'            => $record->id,
            'invalid_fields'=> $invalids, // 🔴
            'warn_fields'   => $warns,    // 🟧
        ];
    }

    // ✅ persist item_checker based on HARD rules only
    if (!empty($validIds)) {
        MacroOutput::whereIn('id', $validIds)->update(['item_checker' => 1]);
    }
    if (!empty($invalidIds)) {
        MacroOutput::whereIn('id', $invalidIds)->update(['item_checker' => 0]);
    }

    return response()->json($results);
}


    public function validateAddresses(Request $request)
    {
        $filePath = resource_path('views/macro_output/jnt_address.txt');

        // ✅ Accept ids as array OR JSON string OR comma-separated string
        $ids = $request->input('ids', []);

        if (is_string($ids)) {
            $raw = trim($ids);

            if ($raw === '') {
                $ids = [];
            } else {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $ids = $decoded;
                } else {
                    $ids = preg_split('/\s*,\s*/', $raw);
                }
            }
        }

        if (!is_array($ids)) $ids = [];

        // sanitize ids
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));

        if (empty($ids)) {
            return response()->json([]); // nothing to validate
        }

        // ✅ Hierarchy maps
        $provCityMap = [];
        $provCityBrgyMap = [];

        if (file_exists($filePath)) {
            $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach ($lines as $line) {
                $parts = array_map('trim', explode('|', $line));
                if (count($parts) !== 3) continue;
                if (strtolower($parts[0] ?? '') === 'province') continue;

                [$provRaw, $cityRaw, $brgyRaw] = $parts;

                $prov = strtolower(trim((string)$provRaw));
                $city = strtolower(trim((string)$cityRaw));
                $brgy = strtolower(trim((string)$brgyRaw));

                if ($prov === '' || $city === '' || $brgy === '') continue;

                $provCityMap[$prov][$city] = true;
                $provCityBrgyMap["{$prov}|{$city}"][$brgy] = true;
            }
        }

        $records = MacroOutput::whereIn('id', $ids)->get([
            'id', 'FULL NAME', 'PROVINCE', 'CITY', 'BARANGAY', 'PHONE NUMBER',
            'fb_name', 'all_user_input'
        ]);

        // phone duplicate counts within batch
        $phoneCounts = [];
        foreach ($records as $record) {
            $phone = trim((string)($record->{'PHONE NUMBER'} ?? ''));
            if ($phone !== '') $phoneCounts[$phone] = ($phoneCounts[$phone] ?? 0) + 1;
        }

        // ✅ Load whitelisted phone numbers (skip duplicate check for these)
        $whitelistedPhones = PhoneWhitelist::phonesForHost($request->getHost());
        $whitelistSet = array_flip($whitelistedPhones);

        // ✅ Load FB name blacklist (case-insensitive)
        $hostScope = str_contains(strtolower((string) $request->getHost()), 'incepxion') ? 'incepxion' : 'likha';
        $fbnameBlacklist = FbnameBlacklist::where('host_scope', $hostScope)
            ->pluck('fb_name')
            ->map(fn($v) => mb_strtolower(trim($v)))
            ->toArray();

        // ✅ Load keyword blacklist (case-insensitive, partial match)
        $keywordBlacklist = KeywordBlacklist::where('host_scope', $hostScope)
            ->pluck('keyword')
            ->map(fn($v) => mb_strtolower(trim($v)))
            ->filter(fn($v) => $v !== '')
            ->toArray();

        $results = [];
        $validIds = [];
        $invalidIds = [];

        foreach ($records as $record) {
            $prov  = strtolower(trim((string)($record->PROVINCE ?? '')));
            $city  = strtolower(trim((string)($record->CITY ?? '')));
            $brgy  = strtolower(trim((string)($record->BARANGAY ?? '')));
            $phone = trim((string)($record->{'PHONE NUMBER'} ?? ''));

            // hierarchy checks
            $provOk = ($prov !== '') && isset($provCityMap[$prov]);
            $cityOk = $provOk && ($city !== '') && isset($provCityMap[$prov][$city]);
            $brgyOk = $cityOk && ($brgy !== '') && isset($provCityBrgyMap["{$prov}|{$city}"][$brgy]);

            // ✅ treat BLANK as invalid too (for validate)
            $provInvalid = !$provOk;
            $cityInvalid = $provOk && !$cityOk;
            $brgyInvalid = $cityOk && !$brgyOk;

            // FULL NAME validation
            $fullName = trim((string)($record->{'FULL NAME'} ?? ''));
            $fullNameInvalid = false;

            if ($fullName === '') {
                $fullNameInvalid = true;
            } else {
                if (!preg_match("/^[\\p{L}\\.,\\-\\' ]+$/u", $fullName)) {
                    $fullNameInvalid = true;
                } elseif (!preg_match('/[A-Za-zÑñ]/u', $fullName)) {
                    $fullNameInvalid = true;
                }
            }

            // PHONE validation
            $phoneInvalid = false;
            $isWhitelisted = isset($whitelistSet[$phone]);

            if ($isWhitelisted) {
                // ✅ Whitelisted phones skip ALL phone validation (duplicate + hardcoded checks)
                $phoneInvalid = false;
            } elseif ($phone === '') {
                $phoneInvalid = true;
            } elseif (!preg_match('/^9\d{9}$/', $phone)) {
                $phoneInvalid = true;
            } elseif ($phone === '9123456789') {
                $phoneInvalid = true;
            } elseif (($phoneCounts[$phone] ?? 0) > 1) {
                $phoneInvalid = true;
            }

            // ✅ FB Name blacklist check (case-insensitive)
            $fbName = mb_strtolower(trim((string)($record->fb_name ?? '')));
            $fbNameBlacklisted = false;
            if ($fbName !== '' && in_array($fbName, $fbnameBlacklist, true)) {
                $fbNameBlacklisted = true;
            }

            // ✅ Keyword blacklist check (case-insensitive, partial match on all_user_input)
            $allUserInput = mb_strtolower((string)($record->all_user_input ?? ''));
            $keywordBlacklisted = false;
            $matchedKeyword = null;
            foreach ($keywordBlacklist as $kw) {
                if (str_contains($allUserInput, $kw)) {
                    $keywordBlacklisted = true;
                    $matchedKeyword = $kw;
                    break;
                }
            }

            $invalidFields = array_filter([
                'FULL NAME'    => $fullNameInvalid || $fbNameBlacklisted,
                'PROVINCE'     => $provInvalid,
                'CITY'         => $cityInvalid,
                'BARANGAY'     => $brgyInvalid,
                'PHONE NUMBER' => $phoneInvalid,
            ]);

            // If keyword blacklisted, flag the all_user_input column
            if ($keywordBlacklisted) {
                $invalidFields['ALL_USER_INPUT'] = true;
            }

            $isValid = empty($invalidFields);

            if ($isValid) $validIds[] = (int)$record->id;
            else $invalidIds[] = (int)$record->id;

            $results[] = [
                'id' => $record->id,
                'invalid_fields' => $invalidFields,
                'validate_2' => $isValid ? 1 : 0,
                'phone_whitelisted' => $isWhitelisted,
                'fbname_blacklisted' => $fbNameBlacklisted,
                'keyword_blacklisted' => $keywordBlacklisted,
                'matched_keyword' => $matchedKeyword,
            ];
        }

        // ✅ Update DB flags
        if (!empty($validIds)) {
            MacroOutput::whereIn('id', $validIds)->update(['validate_2' => 1]);
        }
        if (!empty($invalidIds)) {
            MacroOutput::whereIn('id', $invalidIds)->update(['validate_2' => 0]);
        }

        return response()->json($results);
    }

    public function validateCheckerToFix(Request $request)
{
    $tz = 'Asia/Manila';

    // same default behavior as index(): yesterday
    $date = $request->filled('date') ? $request->input('date') : now($tz)->subDay()->toDateString();
    $page = trim((string) $request->input('PAGE', ''));

    $formattedDMY = Carbon::parse($date, $tz)->format('d-m-Y');

    // wrapper for cross-db quoting
    $wrap = fn (string $col) => DB::getQueryGrammar()->wrap($col);

    // detect ts_date type
    $tsType = null;
    try {
        $tsType = Schema::getColumnType('macro_output', 'ts_date');
    } catch (\Throwable $e) {
        $tsType = null;
    }

    // base scope = date + page (like index), NO checker filter (we’ll filter ✅ only later)
    $base = MacroOutput::query()
        ->where(function ($q) use ($date, $formattedDMY, $tsType, $tz) {
            $q->where(function ($qq) use ($date, $tsType, $tz) {
                $qq->whereNotNull('ts_date');

                if ($tsType === 'date') {
                    $qq->where('ts_date', '=', $date);
                } else {
                    $start = Carbon::parse($date, $tz)->startOfDay()->toDateTimeString();
                    $end   = Carbon::parse($date, $tz)->endOfDay()->toDateTimeString();
                    $qq->whereBetween('ts_date', [$start, $end]);
                }
            });

            $q->orWhere(function ($qq) use ($formattedDMY) {
                $qq->whereNull('ts_date')
                    ->whereNotNull('TIMESTAMP')
                    ->where('TIMESTAMP', 'LIKE', "%{$formattedDMY}%");
            });
        });

    if ($page !== '') {
        $base->where('PAGE', $page);
    }

    // optional: respect status_filter pills (same meaning as index)
    if ($request->filled('status_filter')) {
        if ($request->status_filter === 'BLANK') {
            $base->where(function ($q) {
                $q->whereNull('STATUS')->orWhere('STATUS', '');
            });
        } else {
            $base->where('STATUS', $request->status_filter);
        }
    }

    // EXCLUDE cannot proceed (same as Validate button)
    $base->where(function ($q) {
        $q->whereNull('STATUS')->orWhere('STATUS', '<>', 'CANNOT PROCEED');
    });

    // ✅ Mark validate_1 = 1 for all rows in scope (excluding cannot proceed)
    $marked = (clone $base)->update(['validate_1' => 1]);

    // phone duplicate counts across whole scope
    $allPhones = (clone $base)
        ->select('PHONE NUMBER')
        ->get()
        ->map(fn($r) => trim((string)($r->{'PHONE NUMBER'} ?? '')))
        ->filter(fn($p) => $p !== '')
        ->values()
        ->all();

    $phoneCounts = [];
    foreach ($allPhones as $p) {
        $phoneCounts[$p] = ($phoneCounts[$p] ?? 0) + 1;
    }

    // ✅ Load whitelisted phone numbers (skip duplicate check for these)
    $whitelistedPhones = PhoneWhitelist::phonesForHost($request->getHost());
    $whitelistSet = array_flip($whitelistedPhones);

    // ✅ Load FB name blacklist (case-insensitive)
    $hostScope = str_contains(strtolower((string) $request->getHost()), 'incepxion') ? 'incepxion' : 'likha';
    $fbnameBlacklist = FbnameBlacklist::where('host_scope', $hostScope)
        ->pluck('fb_name')
        ->map(fn($v) => mb_strtolower(trim($v)))
        ->toArray();

    // ✅ Load keyword blacklist (case-insensitive, partial match)
    $keywordBlacklist = KeywordBlacklist::where('host_scope', $hostScope)
        ->pluck('keyword')
        ->map(fn($v) => mb_strtolower(trim($v)))
        ->filter(fn($v) => $v !== '')
        ->toArray();

    // ✅ only rows with checker = ✅ (or blank/null) are candidates for update
    $CHECKER = $wrap('APP SCRIPT CHECKER');

    $candidates = (clone $base)
        ->where(function ($q) use ($CHECKER) {
            $q->whereNull('APP SCRIPT CHECKER')
              ->orWhereRaw("TRIM({$CHECKER}) = ''")
              ->orWhereRaw("TRIM({$CHECKER}) = ?", ['✅']);
        })
        ->get([
            'id', 'FULL NAME', 'PHONE NUMBER', 'PROVINCE', 'CITY', 'BARANGAY',
            'ITEM_NAME', 'COD', 'APP SCRIPT CHECKER',
            'SHOP DETAILS', 'all_user_input', 'fb_name',
        ]);

    if ($candidates->isEmpty()) {
        $params = array_filter([
            'date' => $date,
            'PAGE' => $page !== '' ? $page : null,
            'status_filter' => $request->input('status_filter') ?: null,
            'checker' => '__TO_FIX__',
        ], fn($v) => !is_null($v) && $v !== '');

        return response()->json([
            'status'         => 'success',
            'marked'         => (int) $marked,
            'updated'        => 0,
            'updated_to_fix' => 0,
            'updated_ok'     => 0,
            'redirect_url'   => route('macro_output.index', $params),
        ]);
    }

    // Load JNT address maps
    $filePath = resource_path('views/macro_output/jnt_address.txt');

    $provCityMap = [];
    $provCityBrgyMap = [];

    if (file_exists($filePath)) {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) !== 3) continue;
            if (strtolower($parts[0] ?? '') === 'province') continue;

            [$provRaw, $cityRaw, $brgyRaw] = $parts;

            $prov = strtolower(trim((string)$provRaw));
            $city = strtolower(trim((string)$cityRaw));
            $brgy = strtolower(trim((string)$brgyRaw));

            if ($prov === '' || $city === '' || $brgy === '') continue;

            $provCityMap[$prov][$city] = true;
            $provCityBrgyMap["{$prov}|{$city}"][$brgy] = true;
        }
    }

    $idsToFixHard = [];
    $idsToFixSoft = [];
    $idsOk        = [];

    foreach ($candidates as $r) {
        $prov  = strtolower(trim((string)($r->PROVINCE ?? '')));
        $city  = strtolower(trim((string)($r->CITY ?? '')));
        $brgy  = strtolower(trim((string)($r->BARANGAY ?? '')));
        $phone = trim((string)($r->{'PHONE NUMBER'} ?? ''));
        $fullName = trim((string)($r->{'FULL NAME'} ?? ''));

        // hierarchy checks (BLANK = invalid)
        $provOk = ($prov !== '') && isset($provCityMap[$prov]);
        $cityOk = $provOk && ($city !== '') && isset($provCityMap[$prov][$city]);
        $brgyOk = $cityOk && ($brgy !== '') && isset($provCityBrgyMap["{$prov}|{$city}"][$brgy]);

        $provInvalid = !$provOk;
        $cityInvalid = $provOk && !$cityOk;
        $brgyInvalid = $cityOk && !$brgyOk;

        // FULL NAME invalid
        $fullNameInvalid = false;
        if ($fullName === '') {
            $fullNameInvalid = true;
        } else {
            if (!preg_match("/^[\\p{L}\\.,\\-\\' ]+$/u", $fullName)) {
                $fullNameInvalid = true;
            } elseif (!preg_match('/[A-Za-zÑñ]/u', $fullName)) {
                $fullNameInvalid = true;
            }
        }

        // PHONE invalid
        $phoneInvalid = false;
        $isPhoneWhitelisted = isset($whitelistSet[$phone]);

        if ($isPhoneWhitelisted) {
            // ✅ Whitelisted phones skip ALL phone validation (duplicate + hardcoded checks)
            $phoneInvalid = false;
        } elseif ($phone === '') {
            $phoneInvalid = true;
        } elseif (!preg_match('/^9\d{9}$/', $phone)) {
            $phoneInvalid = true;
        } elseif ($phone === '9123456789') {
            $phoneInvalid = true;
        } elseif (($phoneCounts[$phone] ?? 0) > 1) {
            $phoneInvalid = true;
        }

        // ITEM_NAME + COD hard invalid
        $item = trim((string)($r->ITEM_NAME ?? ''));
        $cod  = trim((string)($r->COD ?? ''));

        $itemInvalid = ($item === '' || mb_strlen($item, 'UTF-8') > 50);
        $codInvalid  = ($cod === '');

        // ✅ FB Name blacklist check (case-insensitive)
        $fbNameVal = mb_strtolower(trim((string)($r->fb_name ?? '')));
        $fbNameBlacklisted = ($fbNameVal !== '' && in_array($fbNameVal, $fbnameBlacklist, true));

        // ✅ Keyword blacklist check (case-insensitive, partial match on all_user_input)
        $allUserInput = mb_strtolower((string)($r->all_user_input ?? ''));
        $keywordBlacklisted = false;
        foreach ($keywordBlacklist as $kw) {
            if (str_contains($allUserInput, $kw)) {
                $keywordBlacklisted = true;
                break;
            }
        }

        $hardIssue =
            $provInvalid || $cityInvalid || $brgyInvalid ||
            $fullNameInvalid || $phoneInvalid ||
            $itemInvalid || $codInvalid ||
            $fbNameBlacklisted || $keywordBlacklisted;

        // ✅ SOFT (SHOP DETAILS mismatch) -> checker TO FIX pero wag galawin flags
        $shopText = trim((string)($r->{'SHOP DETAILS'} ?? ''));
        if ($shopText === '') $shopText = (string)($r->all_user_input ?? '');

        $details = $this->extractShopDetails($shopText);

        $expectedItemRaw = trim((string)($details['item'] ?? ''));
        $expectedCodNum  = (int)($details['expected_cod'] ?? 0);

        $actualItemBase = preg_replace('/^\s*\d+\s*x\s*/iu', '', $item);
        $expectedItem = $this->normItem($expectedItemRaw);
        $actualItem   = $this->normItem((string)$actualItemBase);

        $actualCodNum = $this->codToInt((string)$cod);

        $shopItemMismatch = ($expectedItem !== '' && $actualItem !== '' && $expectedItem !== $actualItem);
        $shopCodMismatch  = ($expectedCodNum > 0 && $actualCodNum > 0 && $expectedCodNum !== $actualCodNum);

        $softIssue = ($shopItemMismatch || $shopCodMismatch);

        if ($hardIssue) {
            $idsToFixHard[] = (int)$r->id;
        } elseif ($softIssue) {
            $idsToFixSoft[] = (int)$r->id;
        } else {
            $idsOk[] = (int)$r->id;
        }
    }

    // ✅ HARD TO FIX: same behavior as before
    $updatedToFixHard = 0;
    if (!empty($idsToFixHard)) {
        $updatedToFixHard = MacroOutput::whereIn('id', $idsToFixHard)
            ->where(function ($q) use ($CHECKER) {
                $q->whereNull('APP SCRIPT CHECKER')
                  ->orWhereRaw("TRIM({$CHECKER}) = ''")
                  ->orWhereRaw("TRIM({$CHECKER}) = ?", ['✅']);
            })
            ->update([
                'APP SCRIPT CHECKER' => 'TO FIX',
                'validate_2'         => 0,
                'item_checker'       => 0,
            ]);
    }

    // ✅ SOFT TO FIX (SHOP DETAILS): change checker ONLY (no flag changes)
    $updatedToFixSoft = 0;
    if (!empty($idsToFixSoft)) {
        $updatedToFixSoft = MacroOutput::whereIn('id', $idsToFixSoft)
            ->where(function ($q) use ($CHECKER) {
                $q->whereNull('APP SCRIPT CHECKER')
                  ->orWhereRaw("TRIM({$CHECKER}) = ''")
                  ->orWhereRaw("TRIM({$CHECKER}) = ?", ['✅']);
            })
            ->update([
                'APP SCRIPT CHECKER' => 'TO FIX - SHOP DETAILS',
            ]);
    }

    // ✅ OK
    $updatedOk = 0;
    if (!empty($idsOk)) {
        $updatedOk = MacroOutput::whereIn('id', $idsOk)
            ->where(function ($q) use ($CHECKER) {
                $q->whereNull('APP SCRIPT CHECKER')
                  ->orWhereRaw("TRIM({$CHECKER}) = ''")
                  ->orWhereRaw("TRIM({$CHECKER}) = ?", ['✅']);
            })
            ->update([
                'APP SCRIPT CHECKER' => '✅',
                'validate_2'         => 1,
                'item_checker'       => 1,
            ]);
    }

    $updatedToFix = (int)($updatedToFixHard + $updatedToFixSoft);

    $params = array_filter([
        'date' => $date,
        'PAGE' => $page !== '' ? $page : null,
        'status_filter' => $request->input('status_filter') ?: null,
        'checker' => '__TO_FIX__',
    ], fn($v) => !is_null($v) && $v !== '');

    return response()->json([
        'status'         => 'success',
        'marked'         => (int)$marked,
        'updated'        => (int)($updatedToFix + $updatedOk),
        'updated_to_fix' => (int)$updatedToFix,
        'updated_ok'     => (int)$updatedOk,
        'redirect_url'   => route('macro_output.index', $params),
    ]);
}


    public function summary(Request $request)
    {
        $start = $request->start_date;
        $end   = $request->end_date;

        // ✅ Default: kung walang date range, today lang
        if (!$start && !$end) {
            $start = $end = now()->toDateString(); // 'Y-m-d'
        }

        // Convert to Carbon
        $startDate = Carbon::parse($start)->startOfDay();
        $endDate   = Carbon::parse($end)->endOfDay();

        // ✅ Safety: huwag payagan sobrang laking range (hal. > 60 days)
        if ($startDate->diffInDays($endDate) > 60) {
            return back()->with('error', 'Please select a date range of 60 days or less for the summary.');
        }

        // Gumawa ng list ng mga araw sa range
        $dateDMYList = []; // format: 'd-m-Y' (para sa TIMESTAMP)
        $dateMapDMYtoYMD = []; // '01-07-2025' => '2025-07-01'

        $cursor = $startDate->copy();
        while ($cursor->lte($endDate)) {
            $dmy = $cursor->format('d-m-Y');
            $ymd = $cursor->format('Y-m-d');
            $dateDMYList[] = $dmy;
            $dateMapDMYtoYMD[$dmy] = $ymd;
            $cursor->addDay();
        }

        // ✅ Query: MacroOutput rows lang na may PAGE at sakop ng piniling dates
        $query = MacroOutput::query()
            ->whereNotNull('PAGE')
            ->where(function ($q) use ($dateDMYList) {
                foreach ($dateDMYList as $dmy) {
                    // TIMESTAMP sample: "00:03 01-07-2025" → LIKE "%01-07-2025"
                    $q->orWhere('TIMESTAMP', 'like', "%{$dmy}");
                }
            });

        // (Optional) filter per PAGE:
        if ($request->filled('PAGE')) {
            $query->where('PAGE', $request->PAGE);
        }

        // Piliin lang yung kailangan sa summary
        $records = $query->select('TIMESTAMP', 'PAGE', 'STATUS', 'waybill')->get();

        $summary = [];
        $totalCounts = [
            'PROCEED'           => 0,
            'CANNOT PROCEED'    => 0,
            'ODZ'               => 0,
            'BLANK'             => 0,
            'TOTAL'             => 0,
            'MATCHED_WAYBILLS'  => 0,
            'SCANNED_WAYBILLS'  => 0,
        ];

        // ✅ I-track lahat ng waybills sa filtered records para i-limit yung from_jnts query
        $allWaybillSet = []; // associative: waybill => true

        foreach ($records as $record) {
            // TIMESTAMP example: "00:03 01-07-2025"
            $parts = explode(' ', $record->TIMESTAMP);
            $dateDMY = $parts[1] ?? null;
            if (!$dateDMY) continue;

            if (!isset($dateMapDMYtoYMD[$dateDMY])) continue;

            $formattedDate = $dateMapDMYtoYMD[$dateDMY]; // 'Y-m-d'
            $status = $record->STATUS ?: 'BLANK';
            $page   = $record->PAGE;

            if (!isset($summary[$formattedDate])) $summary[$formattedDate] = [];

            if (!isset($summary[$formattedDate][$page])) {
                $summary[$formattedDate][$page] = [
                    'PROCEED'          => 0,
                    'CANNOT PROCEED'   => 0,
                    'ODZ'              => 0,
                    'BLANK'            => 0,
                    'TOTAL'            => 0,
                    'WAYBILLS'         => [],
                    'downloaded_by'    => null,
                    'downloaded_at'    => null,
                    'SCANNED_WAYBILLS' => 0,
                    'MATCHED_WAYBILLS' => 0,
                ];
            }

            if (!isset($summary[$formattedDate][$page][$status])) {
                $summary[$formattedDate][$page][$status] = 0;
            }

            $summary[$formattedDate][$page][$status]++;
            $summary[$formattedDate][$page]['TOTAL']++;

            if (!isset($totalCounts[$status])) $totalCounts[$status] = 0;
            $totalCounts[$status]++;
            $totalCounts['TOTAL']++;

            if (!empty($record->waybill)) {
                $wb = (string) $record->waybill;
                $summary[$formattedDate][$page]['WAYBILLS'][] = $wb;
                $allWaybillSet[$wb] = true;
            }
        }

        // Sort page names alphabetically inside each date group
        foreach ($summary as &$pages) {
            ksort($pages);
        }
        unset($pages);

        // ✅ Fetch download logs grouped by date+page
        $logs = DownloadedMacroOutputLog::query()
            ->select('timestamp', 'page', 'downloaded_by', 'downloaded_at')
            ->get()
            ->groupBy(fn($log) => $log->timestamp . '|' . $log->page);

        // ✅ Limit from_jnts query to waybills lang na nasa summary date range
        $existingWaybillSet = [];

        if (!empty($allWaybillSet)) {
            $waybillList = array_keys($allWaybillSet);

            $existingWaybills = DB::table('from_jnts')
                ->whereIn('waybill_number', $waybillList)
                ->pluck('waybill_number')
                ->map('strval')
                ->toArray();

            $existingWaybillSet = array_flip($existingWaybills);
        }

        // Attach downloaded_by, downloaded_at, and waybill counts per (date,page)
        foreach ($summary as $date => &$pages) {
            foreach ($pages as $page => &$counts) {
                $key = $date . '|' . $page;
                $latestLog = $logs->has($key)
                    ? $logs[$key]->sortByDesc('downloaded_at')->first()
                    : null;

                $counts['downloaded_by'] = $latestLog->downloaded_by ?? null;
                $counts['downloaded_at'] = $latestLog->downloaded_at ?? null;

                $waybills = $counts['WAYBILLS'] ?? [];

                $matched = count($waybills);
                $counts['MATCHED_WAYBILLS'] = $matched;
                $totalCounts['MATCHED_WAYBILLS'] += $matched;

                $scanned = 0;
                foreach ($waybills as $wb) {
                    if (isset($existingWaybillSet[$wb])) $scanned++;
                }
                $counts['SCANNED_WAYBILLS'] = $scanned;
                $totalCounts['SCANNED_WAYBILLS'] += $scanned;
            }
        }
        unset($pages);

        return view('macro_output.summary', compact('summary', 'totalCounts', 'start', 'end'));
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'FULL NAME'    => 'required|string|max:255',
            'PHONE NUMBER' => 'required|string|max:100',
            'ADDRESS'      => 'required|string',
            'PROVINCE'     => 'required|string|max:255',
            'CITY'         => 'required|string|max:255',
            'BARANGAY'     => 'required|string|max:255',
            'STATUS'       => 'nullable|string|max:255',
        ]);

        $record = MacroOutput::findOrFail($id);
        $record->update($validated);

        return redirect()->back()->with('success', 'Record updated successfully.');
    }

    // ✅ MacroOutputController@index (FULL) — paginate ONLY when PAGE = All
    public function index(Request $request)
    {
        $tz = 'Asia/Manila';

        // ✅ Date (Y-m-d). Default: yesterday
        $date = $request->filled('date') ? $request->date : now($tz)->subDay()->toDateString();

        // legacy TIMESTAMP contains "d-m-Y"
        $formattedDMY = Carbon::parse($date, $tz)->format('d-m-Y');

        // ✅ Wrapper para cross-db: mysql uses ``, pgsql uses ""
        $wrap = fn (string $col) => DB::getQueryGrammar()->wrap($col);

        // Detect ts_date type (date vs datetime/timestamp)
        $tsType = null;
        try {
            $tsType = Schema::getColumnType('macro_output', 'ts_date');
        } catch (\Throwable $e) {
            $tsType = null;
        }

        // ✅ Build base query (NO whereDate() para di mabagal)
        $baseQuery = MacroOutput::query()
            ->where(function ($q) use ($date, $formattedDMY, $tsType, $tz) {

                // A) ✅ Preferred: ts_date not null
                $q->where(function ($qq) use ($date, $tsType, $tz) {
                    $qq->whereNotNull('ts_date');

                    if ($tsType === 'date') {
                        $qq->where('ts_date', '=', $date);
                    } else {
                        $start = Carbon::parse($date, $tz)->startOfDay()->toDateTimeString();
                        $end   = Carbon::parse($date, $tz)->endOfDay()->toDateTimeString();
                        $qq->whereBetween('ts_date', [$start, $end]);
                    }
                });

                // B) ✅ Legacy fallback: ts_date null -> use TIMESTAMP like "%d-m-Y"
                $q->orWhere(function ($qq) use ($formattedDMY) {
                    $qq->whereNull('ts_date')
                        ->whereNotNull('TIMESTAMP')
                        ->where('TIMESTAMP', 'LIKE', "%{$formattedDMY}%");
                });
            });

        // ✅ Page filter
        if ($request->filled('PAGE')) {
            $baseQuery->where('PAGE', $request->PAGE);
        }

        // ✅ Checker filter (APP SCRIPT CHECKER) - only 4 options in UI: All/Check/To Fix/Blank
        $CHECKER = $wrap('APP SCRIPT CHECKER');

        if ($request->filled('checker')) {
            $checker = $request->checker;

            if ($checker === '__CHECK__') {
                $baseQuery->whereRaw("TRIM({$CHECKER}) = ?", ['✅']);

            } elseif ($checker === '__BLANK__') {
                $baseQuery->where(function ($q) use ($CHECKER) {
                    $q->whereNull('APP SCRIPT CHECKER')
                        ->orWhereRaw("TRIM({$CHECKER}) = ''");
                });

            } elseif ($checker === '__TO_FIX__') {
                $baseQuery->whereNotNull('APP SCRIPT CHECKER')
                    ->whereRaw("TRIM({$CHECKER}) <> ''")
                    ->whereRaw("TRIM({$CHECKER}) <> ?", ['✅']);
            }
        }

        // ✅ Status counts (same filter as records)
        $STATUS = $wrap('STATUS');

        $c = (clone $baseQuery)->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN {$STATUS} = 'PROCEED' THEN 1 ELSE 0 END) as proceed,
            SUM(CASE WHEN {$STATUS} = 'CANNOT PROCEED' THEN 1 ELSE 0 END) as cannot_proceed,
            SUM(CASE WHEN {$STATUS} = 'ODZ' THEN 1 ELSE 0 END) as odz,
            SUM(CASE WHEN {$STATUS} IS NULL OR {$STATUS} = '' THEN 1 ELSE 0 END) as blank
        ")->first();

        $statusCounts = [
            'TOTAL'          => (int) ($c->total ?? 0),
            'PROCEED'        => (int) ($c->proceed ?? 0),
            'CANNOT PROCEED' => (int) ($c->cannot_proceed ?? 0),
            'ODZ'            => (int) ($c->odz ?? 0),
            'BLANK'          => (int) ($c->blank ?? 0),
        ];

        // ✅ Records query
        $recordQuery = (clone $baseQuery);

        if ($request->filled('status_filter')) {
            if ($request->status_filter === 'BLANK') {
                $recordQuery->where(function ($q) {
                    $q->whereNull('STATUS')->orWhere('STATUS', '');
                });
            } else {
                $recordQuery->where('STATUS', $request->status_filter);
            }
        }

        // ✅ Pancake exists flag (so button shows only when match exists)
        $hasPancakeTable = Schema::hasTable('pancake_conversations');
        $FB = $wrap('fb_name');

        $hasPancakeExpr = "CASE WHEN EXISTS (
            SELECT 1 FROM pancake_conversations pc
            WHERE pc.full_name = {$FB}
        ) THEN 1 ELSE 0 END";

        $selectCols = [
            'id', 'FULL NAME', 'PHONE NUMBER', 'ADDRESS',
            'PROVINCE', 'CITY', 'BARANGAY', 'STATUS',
            'PAGE', 'TIMESTAMP', 'all_user_input',
            'HISTORICAL LOGS', 'APP SCRIPT CHECKER',
            'edited_full_name', 'edited_phone_number', 'edited_address',
            'edited_province', 'edited_city', 'edited_barangay',
            'ITEM_NAME', 'COD', 'edited_item_name', 'edited_cod',
            'status_logs', 'ts_date', 'fb_name',
            'SHOP DETAILS', // ✅ avoid undefined property notice
        ];

        if ($hasPancakeTable) {
            $selectCols[] = DB::raw("{$hasPancakeExpr} as has_pancake");
        } else {
            $selectCols[] = DB::raw("0 as has_pancake");
        }

        // ✅ paginate ONLY when PAGE is All (i.e., PAGE not filled)
        $paginateOnlyWhenAll = !$request->filled('PAGE');

        if ($paginateOnlyWhenAll) {
            $records = $recordQuery
                ->select($selectCols)
                ->orderByDesc('id')
                ->paginate(100)
                ->withQueryString();

            $records->through(function ($r) {
                return $this->attachHighlightTokens($r);
            });
        } else {
            $records = $recordQuery
                ->select($selectCols)
                ->orderByDesc('id')
                ->get();

            $records->transform(function ($r) {
                return $this->attachHighlightTokens($r);
            });
        }

        // ✅ Pages dropdown (same filter)
        $pages = (clone $baseQuery)
            ->select('PAGE')
            ->whereNotNull('PAGE')
            ->distinct()
            ->orderBy('PAGE')
            ->pluck('PAGE');

        // ✅ Whitelist access: CEO, Marketing, Marketing OIC
        $canAccessWhitelist = false;
        $userRole = preg_replace('/\s+/u', ' ', trim((string)(Auth::user()?->employeeProfile?->role ?? '')));
        if (preg_match('/^(ceo|marketing|marketing\s*[-–—]\s*oic)$/iu', $userRole)) {
            $canAccessWhitelist = true;
        }

        return view('macro_output.index', compact(
            'records', 'pages', 'date', 'statusCounts', 'paginateOnlyWhenAll', 'canAccessWhitelist'
        ));
    }

    public function validatedSummary(Request $request)
    {
        $tz = 'Asia/Manila';

        $date = $request->filled('date')
            ? $request->input('date')
            : now($tz)->subDay()->toDateString();

        $page = trim((string) $request->input('PAGE', ''));

        $formattedDMY = Carbon::parse($date, $tz)->format('d-m-Y');

        $wrap = fn (string $col) => DB::getQueryGrammar()->wrap($col);

        $tsType = null;
        try {
            $tsType = Schema::getColumnType('macro_output', 'ts_date');
        } catch (\Throwable $e) {
            $tsType = null;
        }

        // Scope = DATE + PAGE only (NOT affected by checker/status_filter)
        $q = MacroOutput::query()
            ->where(function ($qq) use ($date, $formattedDMY, $tsType, $tz) {

                $qq->where(function ($q1) use ($date, $tsType, $tz) {
                    $q1->whereNotNull('ts_date');

                    if ($tsType === 'date') {
                        $q1->where('ts_date', '=', $date);
                    } else {
                        $start = Carbon::parse($date, $tz)->startOfDay()->toDateTimeString();
                        $end   = Carbon::parse($date, $tz)->endOfDay()->toDateTimeString();
                        $q1->whereBetween('ts_date', [$start, $end]);
                    }
                });

                $qq->orWhere(function ($q2) use ($formattedDMY) {
                    $q2->whereNull('ts_date')
                        ->whereNotNull('TIMESTAMP')
                        ->where('TIMESTAMP', 'LIKE', "%{$formattedDMY}%");
                });
            });

        if ($page !== '') {
            $q->where('PAGE', $page);
        }

        $STATUS = $wrap('STATUS');
        $V1     = $wrap('validate_1');
        $V2     = $wrap('validate_2');
        $IC     = $wrap('item_checker');

        $driver = DB::connection()->getDriverName();

        // boolean truth condition (cross-db)
        if ($driver === 'pgsql') {
            $okFlags = "({$V1} IS TRUE AND {$V2} IS TRUE AND {$IC} IS TRUE)";
        } else {
            $okFlags = "(COALESCE({$V1},0)=1 AND COALESCE({$V2},0)=1 AND COALESCE({$IC},0)=1)";
        }

        $row = (clone $q)->selectRaw("
            COUNT(*) as total_rows,
            SUM(CASE WHEN {$STATUS} IS NOT NULL AND TRIM({$STATUS}) <> '' THEN 1 ELSE 0 END) as status_filled_rows,
            SUM(CASE WHEN {$STATUS} = 'PROCEED' THEN 1 ELSE 0 END) as proceed_total,
            SUM(CASE WHEN {$STATUS} = 'PROCEED' AND {$okFlags} THEN 1 ELSE 0 END) as proceed_ok
        ")->first();

        $total        = (int)($row->total_rows ?? 0);
        $statusFilled = (int)($row->status_filled_rows ?? 0);
        $proceedTotal = (int)($row->proceed_total ?? 0);
        $proceedOk    = (int)($row->proceed_ok ?? 0);

        $validatedYes = (
            $total > 0 &&
            $statusFilled === $total &&
            $proceedTotal > 0 &&
            $proceedOk === $proceedTotal
        );

        return response()->json([
            'validated'      => $validatedYes ? 'YES' : 'NO',
            'proceed_ok'     => $proceedOk,
            'proceed_total'  => $proceedTotal,
            'status_filled'  => $statusFilled,
            'total_rows'     => $total,
        ]);
    }

    private function tokenizeLocation($raw, string $type): array
    {
        $s = trim((string) $raw);
        if ($s === '') return [];

        $s = str_replace("\xC2\xA0", ' ', $s); // nbsp
        $s = preg_replace('/[(){}\[\]"“”\'`]+/u', ' ', $s);

        if ($type === 'brgy') {
            $s = preg_replace('/^(brgy\.?|barangay|bgy|brg)\s*/iu', '', $s);
        } elseif ($type === 'city') {
            $s = preg_replace('/^(city\s+of|city|municipality\s+of|municipality|mun\.?)\s*/iu', '', $s);
        } elseif ($type === 'prov') {
            $s = preg_replace('/^(province\s+of|prov\.?)\s*/iu', '', $s);
        }

        $stop = [
            'brgy'=>true,'barangay'=>true,'bgy'=>true,'brg'=>true,
            'city'=>true,'of'=>true,'province'=>true,'prov'=>true,
            'municipality'=>true,'mun'=>true,
        ];

        $parts = preg_split('/[^\p{L}\p{N}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY);

        $uniq = [];
        foreach ($parts as $p) {
            $t = trim($p);
            if ($t === '') continue;

            $low = mb_strtolower($t, 'UTF-8');
            if (isset($stop[$low])) continue;

            if (mb_strlen($t, 'UTF-8') === 1 && preg_match('/^\d$/u', $t)) continue;

            if (mb_strlen($t, 'UTF-8') < 2 && !preg_match('/^\d{2,}$/u', $t)) continue;

            $uniq[$low] = $t;
        }

        $tokens = array_values($uniq);

        usort($tokens, fn($a,$b) => mb_strlen($b,'UTF-8') <=> mb_strlen($a,'UTF-8'));

        return $tokens;
    }

    private function attachHighlightTokens($r)
    {
        $r->brgy_tokens = $this->tokenizeLocation($r->{'BARANGAY'} ?? '', 'brgy');
        $r->city_tokens = $this->tokenizeLocation($r->{'CITY'} ?? '', 'city');
        $r->prov_tokens = $this->tokenizeLocation($r->{'PROVINCE'} ?? '', 'prov');

        // ✅ NEW: shop details mismatch flags (orange highlight only)
        $details = $this->extractShopDetails((string)($r->{'SHOP DETAILS'} ?? ''));

        $expectedItemRaw = (string)($details['item'] ?? '');
        $expectedCodNum  = (int)($details['expected_cod'] ?? 0);

        $actualItemRaw = (string)($r->ITEM_NAME ?? '');
        $actualItemBase = preg_replace('/^\s*\d+\s*x\s*/iu', '', trim($actualItemRaw)); // "1 x ..." -> "..."
        $actualCodNum = $this->codToInt((string)($r->COD ?? ''));

        $expectedItem = $this->normItem($expectedItemRaw);
        $actualItem   = $this->normItem($actualItemBase);

        $r->shop_item_expected = $expectedItemRaw;
        $r->shop_cod_expected  = $expectedCodNum;

        $r->item_mismatch = ($expectedItem !== '' && $actualItem !== '' && $expectedItem !== $actualItem);
        $r->cod_mismatch  = ($expectedCodNum > 0 && $actualCodNum > 0 && $expectedCodNum !== $actualCodNum);

        return $r;
    }

    public function updateField(Request $request)
    {
        $request->validate([
            'id'    => 'required|integer',
            'field' => 'required|string',
            'value' => 'nullable|string',
        ]);

        $record = MacroOutput::findOrFail($request->id);

        $field = $request->field;
        $newValue = (string)($request->value ?? '');
        $oldValue = (string)($record->{$field} ?? '');

        $safe = function ($v) {
            $v = (string)$v;
            $v = str_replace(["\r", "\n"], ' ', $v);
            $v = str_replace('|', '/', $v);
            return trim($v);
        };

        $changed = ($newValue !== $oldValue);

        if ($changed) {
            $user = auth()->user()?->name ?? 'Unknown User';
            $timestamp = now()->format('Y-m-d H:i:s');

            $userS  = $safe($user);
            $tsS    = $safe($timestamp);
            $fieldS = $safe($field);
            $oldS   = $safe($oldValue);
            $newS   = $safe($newValue);

            $editFlags = [
                'FULL NAME'     => 'edited_full_name',
                'PHONE NUMBER'  => 'edited_phone_number',
                'ADDRESS'       => 'edited_address',
                'PROVINCE'      => 'edited_province',
                'CITY'          => 'edited_city',
                'BARANGAY'      => 'edited_barangay',
                'ITEM_NAME'     => 'edited_item_name',
                'COD'           => 'edited_cod',
            ];

            if ($field === 'STATUS') {
                // ✅ status: ts|user|VALUE
                $line = "{$tsS}|{$userS}|{$newS}";

                $existing = trim((string)($record->status_logs ?? ''));
                $record->status_logs = $existing === '' ? $line : ($existing . "\n" . $line);
            } else {
                // ✅ history: ts|user|FIELD|OLD|NEW
                $line = "{$tsS}|{$userS}|{$fieldS}|{$oldS}|{$newS}";

                $existing = trim((string)($record->{'HISTORICAL LOGS'} ?? ''));
                $record->{'HISTORICAL LOGS'} = $existing === '' ? $line : ($existing . "\n" . $line);
            }

            // save updated field
            $record->{$field} = $newValue;

            // reset validation flags
            $record->validate_1   = 0;
            $record->validate_2   = 0;
            $record->item_checker = 0;

            // mark edited flags (first time only)
            if (array_key_exists($field, $editFlags)) {
                $flag = $editFlags[$field];
                if (!$record->{$flag}) {
                    $record->{$flag} = true;
                }
            }

            $record->save();
        }

        return response()->json([
            'status'  => 'success',
            'changed' => $changed,
        ]);
    }

    private function extractShopDetails(string $text): array
    {
        $item = '';
        $price = 0;
        $qty = 1;

        // ITEM: ....
        if (preg_match('/\bITEM\s*:\s*(.+?)(\r?\n|$)/iu', $text, $m)) {
            $item = trim((string)$m[1]);
        }

        // PRICE: ₱399 or 399
        if (preg_match('/\bPRICE\s*:\s*₱?\s*([\d,]+(?:\.\d+)?)(\r?\n|$)/iu', $text, $m)) {
            $raw = str_replace(',', '', (string)$m[1]);
            $price = (int) round((float)$raw);
        }

        // QUANTITY: 1
        if (preg_match('/\bQUANTITY\s*:\s*(\d+)(\r?\n|$)/iu', $text, $m)) {
            $qty = max(1, (int)$m[1]);
        }

        $expectedCod = $price;

        return [
            'item' => $item,
            'price' => $price,
            'qty' => $qty,
            'expected_cod' => $expectedCod,
        ];
    }

    private function normItem(string $s): string
    {
        $s = mb_strtoupper(trim($s), 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = preg_replace('/[^\p{L}\p{N} ]+/u', '', $s);
        return trim($s);
    }

    private function codToInt(string $s): int
    {
        $digits = preg_replace('/[^\d]/', '', $s);
        return $digits === '' ? 0 : (int)$digits;
    }

    public function bulkUpdate(Request $request)
    {
        foreach ($request->input('records', []) as $id => $fields) {
            MacroOutput::where('id', $id)->update($fields);
        }

        return redirect()->back()->with('success', 'All updates saved!');
    }
}
