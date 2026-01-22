<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MacroOutputController extends Controller
{
    /**
     * Example endpoint: reads a row, extracts phone from ALL_USER_INPUT,
     * and optionally saves it to PHONE_NUMBER if missing.
     *
     * Adjust table/column names to match your project.
     */
    public function extractPhoneFix(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);

        // ✅ Change this table name if yours differs
        $row = DB::table('macro_output')->where('id', $request->id)->first();
        if (!$row) {
            return response()->json(['status' => 'error', 'message' => 'Row not found'], 404);
        }

        // ✅ Change these columns if yours differs
        $allUserInput = (string)($row->all_user_input ?? $row->ALL_USER_INPUT ?? '');
        $currentPhone = (string)($row->phone_number ?? $row->PHONE_NUMBER ?? '');

        $extracted = self::extractPhoneNumber($allUserInput);

        // If you want to auto-save only when empty:
        if ($extracted && trim($currentPhone) === '') {
            DB::table('macro_output')->where('id', $row->id)->update([
                'phone_number' => $extracted,
                'updated_at'   => now(),
            ]);
        }

        return response()->json([
            'status'        => 'success',
            'id'            => $row->id,
            'current_phone' => $currentPhone,
            'extracted'     => $extracted,
            'saved'         => ($extracted && trim($currentPhone) === ''),
        ]);
    }

    /**
     * ✅ MAIN FIX: robust PH phone extraction.
     * Returns normalized mobile as 11-digit "09XXXXXXXXX" when possible.
     * If no mobile, may return a landline "0XXXXXXXXX" when detected.
     */
    private static function extractPhoneNumber(string $text): ?string
    {
        $text = str_replace(["\r\n", "\n", "\r", "\t"], ' ', $text);
        $text = trim($text);

        if ($text === '') return null;

        // 1) Collect "number-like" candidates (includes digits + separators + common obfuscation letters)
        //    We purposely allow O/o/I/i/L/l in the candidate, because people type 0 as O.
        preg_match_all('/(?:\+?\s*63|0)?[0-9OoIiLl\-\s\(\)\.]{9,}/u', $text, $m);
        $candidates = $m[0] ?? [];

        // Also catch "pure" long sequences (in case regex above misses)
        preg_match_all('/[0-9OoIiLl]{9,}/u', $text, $m2);
        $candidates = array_merge($candidates, $m2[0] ?? []);

        // De-dup while keeping order
        $seen = [];
        $uniq = [];
        foreach ($candidates as $c) {
            $k = trim($c);
            if ($k === '' || isset($seen[$k])) continue;
            $seen[$k] = true;
            $uniq[] = $k;
        }

        $bestMobile = null;
        $bestLandline = null;

        foreach ($uniq as $cand) {
            $normalizedDigits = self::normalizeDigitsFromCandidate($cand);

            if ($normalizedDigits === '') continue;

            // Try mobile first
            $mobile = self::normalizePhilippineMobile($normalizedDigits);
            if ($mobile) {
                $bestMobile = $bestMobile ?? $mobile;
                // If you want the FIRST valid mobile, break immediately:
                break;
            }

            // If not mobile, try landline fallback (optional)
            $landline = self::normalizePhilippineLandline($normalizedDigits);
            if ($landline) {
                $bestLandline = $bestLandline ?? $landline;
            }
        }

        return $bestMobile ?: $bestLandline;
    }

    /**
     * Converts obfuscation letters inside a candidate, then strips to digits only.
     */
    private static function normalizeDigitsFromCandidate(string $cand): string
    {
        // Convert common mistypes ONLY inside candidate strings
        // o/O => 0, i/I/l/L => 1
        $cand = strtr($cand, [
            'o' => '0', 'O' => '0',
            'i' => '1', 'I' => '1',
            'l' => '1', 'L' => '1',
        ]);

        // Remove everything except digits
        $digits = preg_replace('/\D+/', '', $cand);
        return $digits ? (string)$digits : '';
    }

    /**
     * Normalizes PH mobile to "09XXXXXXXXX" (11 digits).
     * Accepts:
     * - 09XXXXXXXXX
     * - 9XXXXXXXXX (10 digits) => 09...
     * - 639XXXXXXXXX (12 digits) => 09...
     * - 63 + 9XXXXXXXXX variants after digit cleanup
     */
    private static function normalizePhilippineMobile(string $digits): ?string
    {
        // Remove leading zeros that can appear from copy-paste? (careful; we handle formats explicitly)
        // We'll just match explicit patterns:

        // 09XXXXXXXXX
        if (preg_match('/^09\d{9}$/', $digits)) {
            return $digits;
        }

        // 9XXXXXXXXX (10) => 09XXXXXXXXX
        if (preg_match('/^9\d{9}$/', $digits)) {
            return '0' . $digits;
        }

        // 639XXXXXXXXX (12) => 09XXXXXXXXX
        if (preg_match('/^639\d{9}$/', $digits)) {
            return '0' . substr($digits, 2); // remove leading "63"
        }

        // Sometimes people paste: 0639... or 00639...
        if (preg_match('/^0+639\d{9}$/', $digits)) {
            // strip leading zeros, then re-normalize
            $trimmed = ltrim($digits, '0'); // becomes 639...
            if (preg_match('/^639\d{9}$/', $trimmed)) {
                return '0' . substr($trimmed, 2);
            }
        }

        // Sometimes extra digits are appended; try to find a valid mobile inside the string
        // Example: "09185934888 032" -> digits "09185934888032" contains "09185934888"
        if (preg_match('/(09\d{9})/', $digits, $mm)) {
            return $mm[1];
        }
        if (preg_match('/(639\d{9})/', $digits, $mm)) {
            return '0' . substr($mm[1], 2);
        }
        if (preg_match('/(^|[^0-9])(9\d{9})([^0-9]|$)/', $digits, $mm)) {
            return '0' . $mm[2];
        }

        return null;
    }

    /**
     * Optional: normalize PH landline.
     * Very loose fallback:
     * - starts with 02 and 9-10 total digits OR
     * - starts with 0 + area code (3-4 digits) + subscriber (6-7 digits)
     */
    private static function normalizePhilippineLandline(string $digits): ?string
    {
        // Typical Metro Manila landline: 02XXXXXXXX or 02XXXXXXX (varies by system)
        if (preg_match('/^02\d{7,8}$/', $digits)) {
            return $digits;
        }

        // Generic landline: 0 + (2-3 digit area) + (6-8 digit local)
        if (preg_match('/^0\d{9,10}$/', $digits)) {
            // Avoid accidentally returning a mobile-looking number (already handled above)
            if (preg_match('/^09\d{9}$/', $digits)) return null;
            return $digits;
        }

        // Search inside longer sequences
        if (preg_match('/(02\d{7,8})/', $digits, $m)) {
            return $m[1];
        }
        if (preg_match('/(0\d{9,10})/', $digits, $m)) {
            if (preg_match('/^09\d{9}$/', $m[1])) return null;
            return $m[1];
        }

        return null;
    }
}
