{{-- resources/views/pancake/retrieve2.blade.php --}}
<x-layout>
  <x-slot name="title">Pancake Retrieve 2</x-slot>

  <x-slot name="heading">
    Pancake Retrieve 2 (Missing in Macro Output)
  </x-slot>

  @php
    if (!function_exists('btnClass')) {
      function btnClass($active) {
        return $active
          ? 'inline-flex items-center px-3 py-2 rounded-md bg-blue-600 text-white text-sm font-medium'
          : 'inline-flex items-center px-3 py-2 rounded-md bg-gray-100 hover:bg-gray-200 text-gray-800 text-sm font-medium';
      }
    }

    $presetUi = $preset ?? '';
    if ($presetUi === 'month') $presetUi = 'this_month';
  @endphp

  <div class="max-w-7xl mx-auto mt-6 bg-white shadow-md rounded-lg p-6 space-y-4">

    <div class="text-sm text-gray-600">
      Shows rows from <b>pancake_conversations</b> that <b>do NOT</b> exist in <b>macro_output</b>,
      comparing <b>(pancake_page_name + full_name)</b> vs <b>(PAGE + fb_name)</b>.
      Matching is <b>trimmed</b> + <b>case-insensitive</b>.
      <br>
      Date filter uses <b>{{ $tz }}</b> day (created_at is converted from UTC).
      <br>
      <b>SHOP DETAILS</b> is the most common value in <b>macro_output → SHOP DETAILS</b> per ts_date + page.
    </div>

    {{-- Preset buttons --}}
    <div class="flex flex-wrap gap-2 items-center">
      <a href="{{ url('/pancake/retrieve2') }}?preset=last7"
         class="{{ btnClass($presetUi === 'last7') }}">Last 7 Days</a>

      <a href="{{ url('/pancake/retrieve2') }}?preset=yesterday"
         class="{{ btnClass($presetUi === 'yesterday' || $presetUi === '') }}">Yesterday</a>

      <a href="{{ url('/pancake/retrieve2') }}?preset=today"
         class="{{ btnClass($presetUi === 'today') }}">Today</a>

      <a href="{{ url('/pancake/retrieve2') }}?preset=this_month"
         class="{{ btnClass($presetUi === 'this_month') }}">Month (Up to Yesterday)</a>

      <div class="ml-auto flex items-center gap-2">
        <button type="button" id="copyBtn"
                class="inline-flex items-center px-3 py-2 rounded-md bg-gray-900 hover:bg-black text-white text-sm font-medium">
          Copy All (Filtered Range)
        </button>
        <span id="copyMsg" class="text-sm text-green-700 hidden">Copied!</span>
        <span id="copyCount" class="text-sm text-gray-600"></span>
      </div>
    </div>

    {{-- Manual filters --}}
    <form method="GET" action="{{ url('/pancake/retrieve2') }}" class="bg-gray-50 border rounded-lg p-4">
      <div class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">

        <div class="md:col-span-1">
          <label class="block text-xs text-gray-600 mb-1">Date From</label>
          <input type="date" name="date_from" value="{{ request('date_from', $date_from) }}"
                 class="w-full border border-gray-300 rounded-md px-2 py-2 text-sm">
        </div>

        <div class="md:col-span-1">
          <label class="block text-xs text-gray-600 mb-1">Date To</label>
          <input type="date" name="date_to" value="{{ request('date_to', $date_to) }}"
                 class="w-full border border-gray-300 rounded-md px-2 py-2 text-sm">
        </div>

        <div class="md:col-span-2">
          <label class="block text-xs text-gray-600 mb-1">Page contains</label>
          <input type="text" name="page_contains" value="{{ request('page_contains', $page_contains ?? '') }}"
                 placeholder="e.g. Vivien"
                 class="w-full border border-gray-300 rounded-md px-2 py-2 text-sm">
        </div>

        <div class="md:col-span-2">
          <label class="block text-xs text-gray-600 mb-1">Name contains</label>
          <input type="text" name="name_contains" value="{{ request('name_contains', $name_contains ?? '') }}"
                 placeholder="e.g. Ronilo"
                 class="w-full border border-gray-300 rounded-md px-2 py-2 text-sm">
        </div>

        <div class="md:col-span-6 flex gap-2">
          <button type="submit"
                  class="inline-flex items-center px-4 py-2 rounded-md bg-blue-600 text-white text-sm font-medium">
            Apply
          </button>

          <a href="{{ url('/pancake/retrieve2') }}"
             class="inline-flex items-center px-4 py-2 rounded-md bg-gray-200 hover:bg-gray-300 text-gray-800 text-sm font-medium">
            Clear
          </a>

          <div class="ml-auto text-sm text-gray-600">
            Range: <b>{{ $date_from }}</b> to <b>{{ $date_to }}</b> |
            Showing (this page): <b>{{ $rows->count() }}</b> |
            Total matched: <b>{{ $rows->total() }}</b>
          </div>
        </div>
      </div>
    </form>

    <style>
      .cell-wrap { white-space: pre-wrap; word-break: break-word; line-height: 1.35; }
    </style>

    {{-- Table (still paginated for viewing) --}}
    <div class="overflow-x-auto">
      <table id="resultTable" class="min-w-full text-sm border border-gray-200">
        <thead class="bg-gray-100">
          <tr>
            <th class="text-left px-3 py-2 border-b whitespace-nowrap">Date Created</th>
            <th class="text-left px-3 py-2 border-b whitespace-nowrap">Page</th>
            <th class="text-left px-3 py-2 border-b whitespace-nowrap">Full Name</th>
            <th class="text-left px-3 py-2 border-b whitespace-nowrap">Phone Number</th>
            <th class="text-left px-3 py-2 border-b whitespace-nowrap">customers_chat</th>
<th class="text-left px-3 py-2 border-b whitespace-nowrap">SHOP DETAILS</th>

          </tr>
        </thead>

        <tbody>
          @forelse ($rows as $r)
            <tr class="hover:bg-gray-50 align-top">
              <td class="px-3 py-2 border-b whitespace-nowrap">{{ $r->date_created }}</td>
              <td class="px-3 py-2 border-b whitespace-nowrap">{{ $r->page }}</td>
              <td class="px-3 py-2 border-b whitespace-nowrap">{{ $r->full_name }}</td>

              <td class="px-3 py-2 border-b whitespace-nowrap">
                @if (!empty($r->phone_number))
                  {{ $r->phone_number }}
                @else
                  —
                @endif
              </td>

              <td class="px-3 py-2 border-b">
  @if (!empty($r->customers_chat))
    <div class="cell-wrap">{{ $r->customers_chat }}</div>
  @else
    —
  @endif
</td>

<td class="px-3 py-2 border-b">
  @if (!empty($r->shop_details))
    <div class="cell-wrap">{{ $r->shop_details }}</div>
  @else
    —
  @endif
</td>


            </tr>
          @empty
            <tr>
              <td colspan="6" class="px-3 py-6 text-center text-gray-500">
                No results for the selected range/filters.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div>
      {{ $rows->links() }}
    </div>

  </div>

  {{-- ✅ Copy ALL matched rows (not just current page) + preserve line breaks via CHAR(10) formula --}}
  <script>
    (function () {

      function escForFormula(s) {
        return String(s ?? '').replace(/"/g, '""');
      }

      function buildMultilineFormula(str) {
        // Safe for Google Sheets paste: preserve line breaks inside a single cell
        // without putting actual "\n" into clipboard rows.
        const raw = String(str ?? '');
        const normalized = raw.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
        const lines = normalized.split('\n');

        if (lines.length === 1) {
          return '="' + escForFormula(lines[0]) + '"';
        }

        let formula = '="' + escForFormula(lines[0]) + '"';
        for (let i = 1; i < lines.length; i++) {
          formula += '&CHAR(10)&"' + escForFormula(lines[i]) + '"';
        }
        return formula;
      }

      function cleanOneLineCell(str) {
        // For non-multiline columns: prevent tabs/newlines from breaking paste
        let t = String(str ?? '');
        t = t.replace(/\t/g, ' ');
        t = t.replace(/\r\n/g, ' ').replace(/\r/g, ' ').replace(/\n/g, ' ');
        t = t.replace(/\s+/g, ' ').trim();
        return t;
      }

      async function copyTextToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(text);
          return true;
        }
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        ta.style.top = '-9999px';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        return ok;
      }

      function buildTSVFromRows(rows) {
  const lines = [];

  for (const r of rows) {
    const manilaToday = new Intl.DateTimeFormat('en-CA', {
  timeZone: 'Asia/Manila',
  year: 'numeric',
  month: '2-digit',
  day: '2-digit'
}).format(new Date());

const dateCreated = manilaToday;

    const page        = cleanOneLineCell(r.page);
    const fullName    = cleanOneLineCell(r.full_name);
    const phone       = cleanOneLineCell(r.phone_number);

    const chatFormula = buildMultilineFormula(r.customers_chat || '');
    const shopFormula = buildMultilineFormula(r.shop_details || '');

    lines.push([
      dateCreated,
      page,
      fullName,
      phone,
      chatFormula,
      shopFormula
    ].join('\t'));
  }

  return lines.join('\n');
}



      const btn = document.getElementById('copyBtn');
      const msg = document.getElementById('copyMsg');
      const countEl = document.getElementById('copyCount');

      if (!btn) return;

      btn.addEventListener('click', async () => {
        btn.disabled = true;
        if (countEl) countEl.textContent = 'Fetching all rows...';

        try {
          // Keep current filters/date range (everything in URL query)
          const qs = window.location.search || '';
          const url = `{{ route('pancake.retrieve2.export') }}${qs ? qs : ''}`;

          const res = await fetch(url, {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
          });

          if (!res.ok) {
            throw new Error('Export fetch failed (' + res.status + ')');
          }

          const json = await res.json();
          const rows = Array.isArray(json.rows) ? json.rows : [];

          if (!rows.length) {
            alert('No rows to copy for this filter range.');
            return;
          }

          const tsv = buildTSVFromRows(rows);
          const ok = await copyTextToClipboard(tsv);

          if (!ok) {
            alert('Copy failed. Try again.');
            return;
          }

          if (msg) {
            msg.classList.remove('hidden');
            setTimeout(() => msg.classList.add('hidden'), 1200);
          }

          if (countEl) {
            countEl.textContent = `Copied ${rows.length} row(s) (ALL matched for the range).`;
          }

        } catch (e) {
          alert('Copy failed: ' + (e && e.message ? e.message : e));
          if (countEl) countEl.textContent = '';
        } finally {
          btn.disabled = false;
        }
      });

    })();
  </script>
</x-layout>
