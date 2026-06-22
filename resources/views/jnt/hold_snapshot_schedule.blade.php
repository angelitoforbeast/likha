<x-layout>
  <x-slot name="title">HOLD Snapshot — Cron Time</x-slot>
  <x-slot name="heading">HOLD Snapshot — Oras ng Cron</x-slot>

  <div class="max-w-lg mx-auto p-4">

    {{-- Back link --}}
    <a href="{{ route('jnt.hold-snapshots') }}" class="inline-flex items-center text-sm text-blue-600 hover:underline mb-4">
      ← Balik sa HOLD Snapshots
    </a>

    @if (session('success'))
      <div class="mb-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-green-800">{{ session('success') }}</div>
    @endif
    @if (session('error'))
      <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-800">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
      <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-800">{{ $errors->first() }}</div>
    @endif

    <div class="rounded-xl border bg-white shadow-sm p-5">
      <form method="POST" action="{{ route('jnt.hold-snapshots.schedule.update') }}" class="space-y-4">
        @csrf
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Oras ng daily snapshot (PH, 24-hour)</label>
          <input type="time" name="time" value="{{ $time }}" required
                 class="border border-gray-300 rounded px-3 py-2 text-sm bg-white">
          <p class="text-xs text-gray-500 mt-1">
            Tatakbo ang <code>holds:snapshot</code> araw-araw sa oras na 'to (Asia/Manila).
            Kasalukuyang oras sa server ngayon: <span class="font-semibold">{{ $nowPh }}</span> (PH).
          </p>
        </div>
        <button type="submit"
                class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
          I-save ang oras
        </button>
      </form>
    </div>

    {{-- Debug tip --}}
    <div class="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-900">
      🐞 <strong>Para i-debug agad:</strong> i-set ang oras ng <strong>1–2 minuto mula ngayon</strong> ({{ $nowPh }} PH),
      tapos hintayin at i-refresh ang <a href="{{ route('jnt.hold-snapshots') }}" class="underline font-semibold">HOLD Snapshots</a> —
      dapat may bagong <strong>cron</strong> row sa Run history.
    </div>

    <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
      ⚠️ Para tumakbo talaga ang cron, kailangang aktibo ang
      <code>* * * * * php artisan schedule:run</code> (every minute) sa server crontab.
      Kung wala 'to, <strong>walang epekto</strong> ang oras na ito.
    </div>

  </div>
</x-layout>
