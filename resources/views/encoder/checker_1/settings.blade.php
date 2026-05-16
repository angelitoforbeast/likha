<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Checker 1 – Settings</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body { background:#f3f4f6; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; }
    .swatch { display:inline-block; width:14px; height:14px; border-radius:3px; vertical-align:middle; margin-right:6px; }
  </style>
</head>
<body class="text-gray-900">

  <nav class="bg-white border-b sticky top-0 z-50">
    <div class="max-w-4xl mx-auto px-4">
      <div class="h-14 flex items-center justify-between">
        <div class="font-semibold text-lg">Checker 1 – Settings</div>
        <a href="{{ route('encoder.checker1.idle-summary') }}" class="text-sm text-blue-600 hover:underline">
          ← Back to Idle Summary
        </a>
      </div>
    </div>
  </nav>

  <main class="max-w-3xl mx-auto px-4 py-6 space-y-4">

    @if($saved)
      <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-3 text-sm">
        ✓ Settings saved. Refresh the Idle Summary page to apply.
      </div>
    @endif

    @if($errors->any())
      <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-3 text-sm">
        @foreach($errors->all() as $err)
          <div>• {{ $err }}</div>
        @endforeach
      </div>
    @endif

    <form method="POST" action="{{ route('encoder.checker1.settings.update') }}" class="space-y-4">
      @csrf

      {{-- Shift window --}}
      <section class="bg-white rounded-xl shadow p-5">
        <div class="font-semibold text-lg mb-1">🕐 Shift Window</div>
        <p class="text-sm text-gray-500 mb-4">
          Default working hours (PH time). Encoders' status-log activity outside this window will be flagged separately in the Idle Summary.
        </p>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label for="shift_start" class="block text-sm font-semibold mb-1">Shift start</label>
            <input type="time" id="shift_start" name="shift_start" required
                   value="{{ old('shift_start', $shift['start']) }}"
                   class="border rounded px-3 py-2 w-full text-sm font-mono">
            <p class="text-xs text-gray-500 mt-1">Default: 09:00 (9 AM)</p>
          </div>
          <div>
            <label for="shift_end" class="block text-sm font-semibold mb-1">Shift end</label>
            <input type="time" id="shift_end" name="shift_end" required
                   value="{{ old('shift_end', $shift['end']) }}"
                   class="border rounded px-3 py-2 w-full text-sm font-mono">
            <p class="text-xs text-gray-500 mt-1">Default: 18:00 (6 PM)</p>
          </div>
        </div>
      </section>

      {{-- Idle thresholds --}}
      <section class="bg-white rounded-xl shadow p-5">
        <div class="font-semibold text-lg mb-1">⚙ Activity Thresholds</div>
        <p class="text-sm text-gray-500 mb-5">
          These values determine how inter-edit gaps in <code class="bg-gray-100 px-1 py-0.5 rounded text-xs">status_logs</code> are
          classified into <strong>Working / Idle / Long break / Away</strong> buckets.
        </p>

        <div class="space-y-5">
          <div>
            <label for="work" class="block text-sm font-semibold mb-1">
              <span class="swatch" style="background:#22c55e;"></span>Working threshold (≤)
            </label>
            <div class="flex items-center gap-3">
              <input type="number" id="work" name="work" min="30" max="3600" step="10"
                     value="{{ old('work', $thresholds['work']) }}"
                     class="border rounded px-3 py-2 w-32 text-sm font-mono" required>
              <span class="text-sm text-gray-500">seconds</span>
              <span class="text-xs text-gray-400" id="work_help"></span>
            </div>
            <p class="text-xs text-gray-500 mt-1">
              Gaps shorter than this = active work (typing, searching addresses, looking up info). Default: 300 (5 min).
            </p>
          </div>

          <div>
            <label for="idle" class="block text-sm font-semibold mb-1">
              <span class="swatch" style="background:#f59e0b;"></span>Idle break threshold (≤)
            </label>
            <div class="flex items-center gap-3">
              <input type="number" id="idle" name="idle" min="60" max="14400" step="60"
                     value="{{ old('idle', $thresholds['idle']) }}"
                     class="border rounded px-3 py-2 w-32 text-sm font-mono" required>
              <span class="text-sm text-gray-500">seconds</span>
              <span class="text-xs text-gray-400" id="idle_help"></span>
            </div>
            <p class="text-xs text-gray-500 mt-1">
              Gaps in (working, this] = idle break (bathroom, snack, phone). Default: 1800 (30 min).
            </p>
          </div>

          <div>
            <label for="long" class="block text-sm font-semibold mb-1">
              <span class="swatch" style="background:#ef4444;"></span>Long break threshold (≤)
            </label>
            <div class="flex items-center gap-3">
              <input type="number" id="long" name="long" min="300" max="43200" step="60"
                     value="{{ old('long', $thresholds['long']) }}"
                     class="border rounded px-3 py-2 w-32 text-sm font-mono" required>
              <span class="text-sm text-gray-500">seconds</span>
              <span class="text-xs text-gray-400" id="long_help"></span>
            </div>
            <p class="text-xs text-gray-500 mt-1">
              Gaps in (idle, this] = long break (lunch, smoking). Anything longer is classified as "away" and excluded from totals. Default: 7200 (2 hrs).
            </p>
          </div>
        </div>
      </section>

      {{-- Save bar --}}
      <div class="flex items-center gap-2">
        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded px-4 py-2 text-sm">
          💾 Save All Settings
        </button>
        <button type="button"
                onclick="document.getElementById('shift_start').value='{{ $shiftDefaults['start'] }}';
                         document.getElementById('shift_end').value='{{ $shiftDefaults['end'] }}';
                         document.getElementById('work').value={{ $defaults['work'] }};
                         document.getElementById('idle').value={{ $defaults['idle'] }};
                         document.getElementById('long').value={{ $defaults['long'] }};
                         updateHelpers();"
                class="border hover:bg-gray-50 rounded px-4 py-2 text-sm">
          Reset all to defaults
        </button>
      </div>
    </form>

    <section class="bg-white rounded-xl shadow p-5">
      <div class="font-semibold mb-2">📊 Quick reference: seconds → human</div>
      <table class="text-sm">
        <tbody class="divide-y">
          <tr><td class="py-1 pr-6 font-mono">60</td><td class="text-gray-600">1 minute</td></tr>
          <tr><td class="py-1 pr-6 font-mono">180</td><td class="text-gray-600">3 minutes</td></tr>
          <tr><td class="py-1 pr-6 font-mono">300</td><td class="text-gray-600">5 minutes (default Working)</td></tr>
          <tr><td class="py-1 pr-6 font-mono">600</td><td class="text-gray-600">10 minutes</td></tr>
          <tr><td class="py-1 pr-6 font-mono">900</td><td class="text-gray-600">15 minutes</td></tr>
          <tr><td class="py-1 pr-6 font-mono">1800</td><td class="text-gray-600">30 minutes (default Idle)</td></tr>
          <tr><td class="py-1 pr-6 font-mono">3600</td><td class="text-gray-600">1 hour</td></tr>
          <tr><td class="py-1 pr-6 font-mono">5400</td><td class="text-gray-600">1.5 hours</td></tr>
          <tr><td class="py-1 pr-6 font-mono">7200</td><td class="text-gray-600">2 hours (default Long)</td></tr>
          <tr><td class="py-1 pr-6 font-mono">10800</td><td class="text-gray-600">3 hours</td></tr>
          <tr><td class="py-1 pr-6 font-mono">14400</td><td class="text-gray-600">4 hours</td></tr>
        </tbody>
      </table>
    </section>
  </main>

  <script>
    function fmtSec(s) {
      s = Number(s) || 0;
      if (s < 60) return s + 's';
      if (s < 3600) {
        const m = Math.floor(s/60), sec = s % 60;
        return m + 'm' + (sec ? ' ' + sec + 's' : '');
      }
      const h = Math.floor(s/3600), m = Math.round((s%3600)/60);
      return h + 'h' + (m ? ' ' + m + 'm' : '');
    }
    function updateHelpers() {
      ['work', 'idle', 'long'].forEach(id => {
        const v = document.getElementById(id).value;
        document.getElementById(id + '_help').textContent = '= ' + fmtSec(v);
      });
    }
    ['work', 'idle', 'long'].forEach(id => {
      document.getElementById(id).addEventListener('input', updateHelpers);
    });
    updateHelpers();
  </script>
</body>
</html>
