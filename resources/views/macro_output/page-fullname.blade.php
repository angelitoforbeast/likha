{{-- ✅ resources/views/macro_output/page-fullname.blade.php --}}
<x-layout>
  <x-slot name="title">Encoder</x-slot>

  <x-slot name="heading">
    <div class="sticky-header">
      PAGE + FB NAME (Filter by Date)
    </div>
  </x-slot>

  <style>
    .sticky-header {
      position: fixed;
      top: 64px;
      left: 0;
      right: 0;
      width: 100vw;
      background-color: white;
      z-index: 100;
      padding: 1rem;
      margin: 0;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }
  </style>

  @php
    // ✅ Build copy-all text: "PAGE<TAB>FB_NAME" per row, newline separated
    $lines = $rows->map(function ($r) {
      $page = trim((string) ($r->PAGE ?? ''));
      $fb   = trim((string) ($r->fb_name ?? ''));
      return $page . "\t" . $fb;
    })->filter(fn($x) => trim($x) !== '')->values();

    $copyAllText = $lines->implode("\n");
  @endphp

  <div class="p-4 pt-20 space-y-4">
    {{-- Filter --}}
    <form method="GET" action="{{ route('encoder.page-name') }}" class="flex flex-wrap gap-2 items-end">
      <div>
        <label class="block text-sm text-gray-700">Date</label>
        <input type="date" name="date" value="{{ $date }}" class="border border-gray-300 px-3 py-2 rounded">
      </div>

      <button type="submit" class="px-4 py-2 rounded bg-black text-white">
        FILTER
      </button>

      <div class="ml-auto flex items-center gap-2">
        <div class="text-sm text-gray-600">
          Rows: <span class="font-semibold">{{ $rows->count() }}</span>
        </div>

        <button
          type="button"
          class="px-4 py-2 rounded bg-blue-600 text-white"
          onclick="copyAll()"
          @if($rows->isEmpty()) disabled style="opacity:.5; cursor:not-allowed;" @endif
        >
          COPY ALL
        </button>
      </div>
    </form>

    {{-- Table --}}
    <div class="overflow-auto border rounded">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="text-left px-3 py-2 border-b w-16">#</th>
            <th class="text-left px-3 py-2 border-b">PAGE</th>
            <th class="text-left px-3 py-2 border-b">FB NAME</th>
          </tr>
        </thead>
        <tbody>
          @forelse ($rows as $i => $r)
            @php
              $page = trim((string) ($r->PAGE ?? ''));
              $fb   = trim((string) ($r->fb_name ?? ''));
            @endphp
            <tr class="hover:bg-gray-50">
              <td class="px-3 py-2 border-b text-gray-600">{{ $i + 1 }}</td>
              <td class="px-3 py-2 border-b font-medium">{{ $page }}</td>
              <td class="px-3 py-2 border-b">{{ $fb }}</td>
            </tr>
          @empty
            <tr>
              <td colspan="3" class="px-3 py-6 text-center text-gray-500">
                No records found for this date.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div id="copyToast" class="hidden fixed bottom-6 right-6 bg-black text-white text-sm px-4 py-2 rounded shadow">
      Copied!
    </div>

    {{-- ✅ Hidden textarea for fallback copy --}}
    <textarea id="copyAllText" class="hidden">{!! e($copyAllText) !!}</textarea>
  </div>

  <script>
    function showToast(msg) {
      const el = document.getElementById('copyToast');
      el.textContent = msg || 'Copied!';
      el.classList.remove('hidden');
      setTimeout(() => el.classList.add('hidden'), 900);
    }

    async function copyAll() {
      const text = document.getElementById('copyAllText').value || '';
      if (!text.trim()) {
        showToast('Nothing to copy');
        return;
      }

      try {
        await navigator.clipboard.writeText(text);
        showToast('Copied ALL rows!');
      } catch (e) {
        // fallback
        const ta = document.getElementById('copyAllText');
        ta.classList.remove('hidden');
        ta.select();
        document.execCommand('copy');
        ta.classList.add('hidden');
        showToast('Copied ALL rows!');
      }
    }
  </script>
</x-layout>
