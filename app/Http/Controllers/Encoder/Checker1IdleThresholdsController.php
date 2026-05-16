<?php

namespace App\Http\Controllers\Encoder;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Manage GLOBAL idle-classification thresholds for /encoder/checker_1/idle-summary.
 *
 * Storage: app_settings table, key = 'encoder_checker_1_idle_thresholds',
 * value = JSON {"work": 300, "idle": 1800, "long": 7200} (seconds).
 *
 * No role gate yet — open to anyone who can hit the route. Add ->middleware()
 * later when tested.
 */
class Checker1IdleThresholdsController extends Controller
{
    public const SETTINGS_KEY = 'encoder_checker_1_idle_thresholds';

    public const DEFAULTS = [
        'work' => 300,   // 5 min — gaps ≤ this = working/searching
        'idle' => 1800,  // 30 min — gaps ≤ this = idle break
        'long' => 7200,  // 2 hrs — gaps ≤ this = long break (otherwise = away)
    ];

    public function index()
    {
        return view('encoder.checker_1.idle_thresholds', [
            'thresholds' => self::load(),
            'defaults'   => self::DEFAULTS,
            'saved'      => session('thresholds_saved', false),
        ]);
    }

    public function update(Request $request)
    {
        // Integer seconds with sane bounds. Order constraint: work < idle < long.
        $validated = $request->validate([
            'work' => 'required|integer|min:30|max:3600',     // 30s – 1 hr
            'idle' => 'required|integer|min:60|max:14400',    // 1 min – 4 hrs
            'long' => 'required|integer|min:300|max:43200',   // 5 min – 12 hrs
        ]);

        if ($validated['work'] >= $validated['idle']) {
            return back()
                ->withInput()
                ->withErrors(['work' => 'Working threshold must be less than Idle threshold.']);
        }
        if ($validated['idle'] >= $validated['long']) {
            return back()
                ->withInput()
                ->withErrors(['idle' => 'Idle threshold must be less than Long break threshold.']);
        }

        self::save($validated);

        return redirect()
            ->route('encoder.checker1.idle-thresholds')
            ->with('thresholds_saved', true);
    }

    /**
     * Read saved thresholds. Falls back to DEFAULTS for any missing key
     * (e.g., when settings row doesn't exist yet or partial save).
     */
    public static function load(): array
    {
        $out = self::DEFAULTS;
        $row = DB::table('app_settings')->where('key', self::SETTINGS_KEY)->first(['value']);
        if (!$row || !$row->value) return $out;

        $decoded = json_decode($row->value, true);
        if (!is_array($decoded)) return $out;

        foreach (['work', 'idle', 'long'] as $k) {
            if (isset($decoded[$k]) && is_numeric($decoded[$k])) {
                $out[$k] = (int) $decoded[$k];
            }
        }
        return $out;
    }

    private static function save(array $thresholds): void
    {
        $payload = json_encode([
            'work' => (int) $thresholds['work'],
            'idle' => (int) $thresholds['idle'],
            'long' => (int) $thresholds['long'],
        ]);
        // Driver-agnostic upsert
        DB::table('app_settings')->updateOrInsert(
            ['key' => self::SETTINGS_KEY],
            ['value' => $payload, 'updated_at' => now(), 'created_at' => now()]
        );
    }
}
