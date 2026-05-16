<?php

namespace App\Http\Controllers\Encoder;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GLOBAL settings for /encoder/checker_1/idle-summary.
 *
 * Currently manages:
 *   - Idle-classification thresholds (work / idle / long-break in seconds)
 *   - Default shift window (start / end, HH:MM in PH time)
 *
 * Storage: app_settings table
 *   - key = 'encoder_checker_1_idle_thresholds' → JSON {"work": 300, "idle": 1800, "long": 7200}
 *   - key = 'encoder_checker_1_shift_times'      → JSON {"start": "09:00", "end": "18:00"}
 *
 * No role gate yet — open to anyone who can hit the route. Add ->middleware()
 * later when tested.
 */
class Checker1SettingsController extends Controller
{
    public const KEY_THRESHOLDS = 'encoder_checker_1_idle_thresholds';
    public const KEY_SHIFT      = 'encoder_checker_1_shift_times';

    public const DEFAULTS = [
        'work' => 300,   // 5 min — gaps ≤ this = working/searching
        'idle' => 1800,  // 30 min — gaps ≤ this = idle break
        'long' => 7200,  // 2 hrs — gaps ≤ this = long break (otherwise = away)
    ];

    public const SHIFT_DEFAULTS = [
        'start' => '09:00',  // 9 AM PH
        'end'   => '18:00',  // 6 PM PH
    ];

    public function index()
    {
        return view('encoder.checker_1.settings', [
            'thresholds'    => self::loadThresholds(),
            'defaults'      => self::DEFAULTS,
            'shift'         => self::loadShift(),
            'shiftDefaults' => self::SHIFT_DEFAULTS,
            'saved'         => session('settings_saved', false),
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            // Thresholds (seconds)
            'work'        => 'required|integer|min:30|max:3600',
            'idle'        => 'required|integer|min:60|max:14400',
            'long'        => 'required|integer|min:300|max:43200',
            // Shift window (HH:MM 24-hr PH)
            'shift_start' => 'required|date_format:H:i',
            'shift_end'   => 'required|date_format:H:i',
        ]);

        if ($validated['work'] >= $validated['idle']) {
            return back()->withInput()->withErrors(['work' => 'Working threshold must be less than Idle threshold.']);
        }
        if ($validated['idle'] >= $validated['long']) {
            return back()->withInput()->withErrors(['idle' => 'Idle threshold must be less than Long break threshold.']);
        }
        if ($validated['shift_start'] >= $validated['shift_end']) {
            return back()->withInput()->withErrors(['shift_start' => 'Shift start time must be earlier than shift end time.']);
        }

        self::saveThresholds([
            'work' => $validated['work'],
            'idle' => $validated['idle'],
            'long' => $validated['long'],
        ]);
        self::saveShift([
            'start' => $validated['shift_start'],
            'end'   => $validated['shift_end'],
        ]);

        return redirect()
            ->route('encoder.checker1.settings')
            ->with('settings_saved', true);
    }

    /**
     * Read saved thresholds. Falls back to DEFAULTS for any missing key.
     */
    public static function loadThresholds(): array
    {
        return self::readJsonOrDefault(self::KEY_THRESHOLDS, self::DEFAULTS, ['work', 'idle', 'long']);
    }

    /** Back-compat alias — Checker1IdleSummaryController calls ::load(). */
    public static function load(): array
    {
        return self::loadThresholds();
    }

    public static function loadShift(): array
    {
        return self::readJsonOrDefault(self::KEY_SHIFT, self::SHIFT_DEFAULTS, ['start', 'end']);
    }

    /**
     * Generic read-or-default helper. Returns DEFAULTS if row missing /
     * invalid JSON / missing keys, with per-key fallback so partial saves
     * still work.
     */
    private static function readJsonOrDefault(string $key, array $defaults, array $fields): array
    {
        $out = $defaults;
        $row = DB::table('app_settings')->where('key', $key)->first(['value']);
        if (!$row || !$row->value) return $out;
        $decoded = json_decode($row->value, true);
        if (!is_array($decoded)) return $out;
        foreach ($fields as $f) {
            if (isset($decoded[$f])) $out[$f] = $decoded[$f];
        }
        return $out;
    }

    private static function saveThresholds(array $thresholds): void
    {
        self::upsert(self::KEY_THRESHOLDS, [
            'work' => (int) $thresholds['work'],
            'idle' => (int) $thresholds['idle'],
            'long' => (int) $thresholds['long'],
        ]);
    }

    private static function saveShift(array $shift): void
    {
        self::upsert(self::KEY_SHIFT, [
            'start' => (string) $shift['start'],
            'end'   => (string) $shift['end'],
        ]);
    }

    private static function upsert(string $key, array $payload): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($payload), 'updated_at' => now(), 'created_at' => now()]
        );
    }
}
