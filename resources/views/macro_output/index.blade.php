{{-- ✅ resources/views/macro_output/index.blade.php (FULL) --}}
<x-layout>
  <x-slot name="title">Encoder</x-slot>

  <x-slot name="heading">
    <div class="sticky-header">
      CHECKER 1
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

    .all-user-input {
      transition: all 0.25s ease;
      max-height: 4.5em;
      overflow: hidden;
      white-space: nowrap;
      text-overflow: ellipsis;
    }
    .all-user-input.expanded {
      white-space: normal;
      overflow: visible;
      text-overflow: initial;
      max-height: none;
    }

    table td, table th { word-break: break-word; }

    textarea {
      overflow: hidden !important;
      resize: none;
      min-height: 3em;
      line-height: 1.2em;
    }

    mark.hl-brgy {
      background: #fde68a;
      padding: 0 2px;
      border-radius: 3px;
    }
    mark.hl-city {
      background: #bfdbfe;
      padding: 0 2px;
      border-radius: 3px;
    }
    mark.hl-prov {
      background: #e9d5ff;
      padding: 0 2px;
      border-radius: 3px;
    }

    .col-hidden { display: none !important; }

    /* ✅ Logs: collapsed by default (~2 lines), expand on click (expand-only-once) */
    .log-cell { cursor: pointer; }
    .log-cell .log-content {
      overflow: hidden;
      max-height: 2.6em;       /* ~2 lines */
      line-height: 1.3em;
    }
    .log-cell.expanded .log-content {
      max-height: none;
      overflow: visible;
    }

    .log-content ul {
      margin: 0;
      padding-left: 1.05rem;
    }
    .log-content li { margin: 0; }

    /* smaller font for historical logs */
    .hist-log { font-size: 0.72rem; }   /* smaller than text-xs */
    .status-log { font-size: 0.8rem; }
  </style>

  @php
    $role = Auth::user()?->employeeProfile?->role ?? null;
    $canEditItemAndCod = in_array($role, ['CEO', 'Marketing - OIC', 'Data Encoder - OIC']);
    $canSeeDownloadAll = ($role === 'Marketing - OIC');

    /**
     * ✅ STATUS LOGS renderer
     * New DB format: ts|user|NEW_STATUS
     * Display: [ts] → NEW_STATUS - user (bold NEW_STATUS)
     *
     * Also supports old legacy:
     * [ts] Bryan changed STATUS: "" → "PROCEED
     */
    if (!function_exists('render_status_logs')) {
      function render_status_logs($raw) {
        $raw = trim((string)($raw ?? ''));
        if ($raw === '') return '';

        $lines = preg_split("/\r\n|\n|\r/", $raw);
        $lines = array_values(array_filter(array_map('trim', $lines))); // remove blanks
        $lines = array_reverse($lines); // ✅ oldest first

        $items = [];

        foreach ($lines as $line) {
          $line = trim($line);
          if ($line === '') continue;

          // New pipe format: ts|user|value
          if (str_contains($line, '|')) {
            $p = explode('|', $line);
            if (count($p) >= 3) {
              $ts   = e(trim($p[0] ?? ''));
              $user = e(trim($p[1] ?? ''));
              $val  = e(trim($p[2] ?? ''));
              $items[] = "<li><span class=\"text-gray-500\">[{$ts}]</span> → <strong>{$val}</strong> <span class=\"text-gray-500\">- {$user}</span></li>";
              continue;
            }
          }

          // Legacy: [ts] User changed STATUS: "old" → "new"  (allow missing end quote)
          if (preg_match('/^\[(.*?)\]\s*(.*?)\s*changed\s*STATUS:\s*"(.*)"\s*→\s*"(.*)\s*$/u', $line, $m)) {
            $ts   = e(trim($m[1] ?? ''));
            $user = e(trim($m[2] ?? ''));
            $new  = e(trim($m[4] ?? ''));
            $items[] = "<li><span class=\"text-gray-500\">[{$ts}]</span> → <strong>{$new}</strong> <span class=\"text-gray-500\">- {$user}</span></li>";
            continue;
          }

          // fallback
          $items[] = "<li><span class=\"text-gray-500\">" . e($line) . "</span></li>";
        }

        return $items ? '<ul class="list-disc space-y-1">'.implode('', $items).'</ul>' : '';
      }
    }

    /**
     * ✅ HISTORICAL LOGS renderer
     * New DB format: ts|user|FIELD|OLD|NEW
     * Display: ts — FIELD: OLD → NEW — user (bold OLD/NEW)
     *
     * Supports legacy:
     * [ts] Bryan updated BARANGAY: "OLD" → "NEW
     */
    if (!function_exists('render_hist_logs')) {
      function render_hist_logs($raw) {
        $raw = trim((string)($raw ?? ''));
        if ($raw === '') return '';

        $lines = preg_split("/\r\n|\n|\r/", $raw);
        $items = [];

        foreach ($lines as $line) {
          $line = trim($line);
          if ($line === '') continue;

          // New pipe format: ts|user|field|old|new
          if (str_contains($line, '|')) {
            $p = explode('|', $line);
            if (count($p) >= 5) {
              $ts    = e(trim($p[0] ?? ''));
              $user  = e(trim($p[1] ?? ''));
              $field = e(trim($p[2] ?? ''));
              $old   = e(trim($p[3] ?? ''));
              $new   = e(trim($p[4] ?? ''));
              $items[] = "<li><span class=\"text-gray-500\">{$ts}</span> — <strong>{$field}</strong>: <strong>{$old}</strong> → <strong>{$new}</strong> <span class=\"text-gray-500\">— {$user}</span></li>";
              continue;
            }

            // Older pipe format from previous version
            if (count($p) >= 6 && ($p[3] ?? '') === 'from') {
              $ts    = e(trim($p[0] ?? ''));
              $user  = e(trim($p[1] ?? ''));
              $field = e(trim($p[2] ?? ''));
              $old   = e(trim($p[4] ?? ''));
              $new   = e(trim($p[5] ?? ''));
              $items[] = "<li><span class=\"text-gray-500\">{$ts}</span> — <strong>{$field}</strong>: <strong>{$old}</strong> → <strong>{$new}</strong> <span class=\"text-gray-500\">— {$user}</span></li>";
              continue;
            }
            if (count($p) >= 5 && ($p[3] ?? '') === 'to') {
              $ts    = e(trim($p[0] ?? ''));
              $user  = e(trim($p[1] ?? ''));
              $field = e(trim($p[2] ?? ''));
              $new   = e(trim($p[4] ?? ''));
              $items[] = "<li><span class=\"text-gray-500\">{$ts}</span> — <strong>{$field}</strong>: → <strong>{$new}</strong> <span class=\"text-gray-500\">— {$user}</span></li>";
              continue;
            }
          }

          // Legacy: [ts] User updated FIELD: "old" → "new" (allow missing end quote)
          if (preg_match('/^\[(.*?)\]\s*(.*?)\s*updated\s*(.*?):\s*"(.*)"\s*→\s*"(.*)\s*$/u', $line, $m)) {
            $ts    = e(trim($m[1] ?? ''));
            $user  = e(trim($m[2] ?? ''));
            $field = e(trim($m[3] ?? ''));
            $old   = e(trim($m[4] ?? ''));
            $new   = e(trim($m[5] ?? ''));
            $items[] = "<li><span class=\"text-gray-500\">{$ts}</span> — <strong>{$field}</strong>: <strong>{$old}</strong> → <strong>{$new}</strong> <span class=\"text-gray-500\">— {$user}</span></li>";
            continue;
          }

          // fallback
          $items[] = "<li><span class=\"text-gray-500\">" . e($line) . "</span></li>";
        }

        return $items ? '<ul class="list-disc space-y-1">'.implode('', $items).'</ul>' : '';
      }
    }
  @endphp

  <div id="fixed-header" class="fixed top-[128px] left-0 right-0 z-40 bg-white px-4 pt-4 pb-2 shadow">
    {{-- Filters --}}
    <form id="filtersForm" method="get" action="{{ route('macro_output.index') }}" class="flex items-end gap-4 mb-2 flex-wrap w-full">
      <div>
        <label class="text-sm font-medium">Date</label>
        <input type="date" name="date" value="{{ $date }}"
          class="border rounded px-2 py-1"
          onchange="resetCheckerAndSubmit(this.form)" />
      </div>

      <div>
        <label class="text-sm font-medium">Page</label>
        <select name="PAGE" class="border rounded px-2 py-1" onchange="resetCheckerAndSubmit(this.form)">
          <option value="">All</option>
          @foreach($pages as $page)
            <option value="{{ $page }}" @selected(request('PAGE') == $page)>{{ $page }}</option>
          @endforeach
        </select>
      </div>

      {{-- ✅ Checker filter dropdown (ONLY: All, Check, To Fix, Blank) --}}
      <div>
        <label class="text-sm font-medium">To Fix</label>
        <select name="checker" class="border rounded px-2 py-1" onchange="this.form.submit()">
          <option value="" @selected(!request()->filled('checker'))>All</option>
          <option value="__CHECK__"  @selected(request('checker') === '__CHECK__')>Check</option>
          <option value="__TO_FIX__" @selected(request('checker') === '__TO_FIX__')>To Fix</option>
          <option value="__BLANK__"  @selected(request('checker') === '__BLANK__')>Blank</option>
        </select>
      </div>

      @php
        $statusColors = [
          'TOTAL' => 'bg-gray-300',
          'PROCEED' => 'bg-green-200',
          'CANNOT PROCEED' => 'bg-red-200',
          'ODZ' => 'bg-yellow-200',
          'BLANK' => 'bg-blue-200',
        ];
      @endphp

      <div class="mb-2 text-sm space-x-4 flex flex-wrap gap-2">
        @foreach ($statusCounts as $status => $count)
          <a
            href="{{ $status === 'TOTAL'
              ? route('macro_output.index', request()->except('status_filter'))
              : route('macro_output.index', array_merge(request()->all(), ['status_filter' => $status]))
            }}"
            class="inline-block px-3 py-1 rounded {{ $statusColors[$status] ?? 'bg-gray-200' }} hover:bg-opacity-80"
          >
            <strong>{{ $status }}:</strong> {{ $count }}
          </a>
        @endforeach
      </div>

      <div class="ml-auto flex gap-2 items-center">
        @if($canEditItemAndCod)
          <button type="button" id="itemCheckerBtn" class="bg-yellow-600 text-white px-4 py-2 rounded hover:bg-yellow-700">
            ITEM CHECKER
          </button>
          <span id="item-checker-status" class="text-sm text-gray-600"></span>
        @endif

        <button type="button" id="validate-btn" class="bg-gray-700 text-white px-4 py-2 rounded hover:bg-gray-800">Validate</button>
        <span id="validate-status" class="text-sm text-gray-600"></span>
      </div>
    </form>

    {{-- ✅ Pagination shown UNDER Page dropdown area (inside header) --}}
    <div class="mt-2">
      {{ $records->withQueryString()->links() }}
    </div>

    {{-- Column toggles (default unchecked) --}}
    <div class="flex items-center justify-end gap-4 text-sm mb-3 mt-2">
      <label class="inline-flex items-center gap-2">
        <input type="checkbox" id="toggleHistorical">
        Show Historical Logs
      </label>

      @if($role !== 'Data Encoder')
        <label class="inline-flex items-center gap-2">
          <input type="checkbox" id="toggleStatusLogs">
          Show Status Logs
        </label>
      @endif
    </div>

    {{-- Download --}}
    @if($role !== 'Data Encoder')
      <div class="flex items-center justify-end gap-3">
        @if($canSeeDownloadAll)
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" id="downloadAll">
            Download All (include CANNOT PROCEED, ODZ, etc.)
          </label>
        @endif

        <a href="#" id="downloadBtn" class="bg-gray-700 text-white px-4 py-2 rounded hover:bg-gray-800">
          Download
        </a>
      </div>
    @endif

    @if(session('error'))
      <div class="bg-red-100 text-red-700 border border-red-400 p-3 rounded mt-2">
        {{ session('error') }}
      </div>
    @endif
  </div>

  {{-- Table Head --}}
  <table class="table-fixed w-full border text-sm">
    <thead class="bg-gray-100 text-left">
      <tr>
        <th class="border p-2" style="width: 10%">FULL NAME</th>
        <th class="border p-2" style="width: 10%">PHONE NUMBER</th>
        <th class="border p-2" style="width: 10%">ADDRESS</th>
        <th class="border p-2" style="width: 8%">PROVINCE</th>
        <th class="border p-2" style="width: 8%">CITY</th>
        <th class="border p-2" style="width: 8%">BARANGAY</th>
        <th class="border p-2" style="width: 8%">STATUS</th>
        @if($canEditItemAndCod)
          <th class="border p-2" style="width: 8%">ITEM NAME</th>
          <th class="border p-2" style="width: 8%">COD</th>
        @endif
        <th class="border p-2" style="width: 20%">CUSTOMER DETAILS</th>

        <th class="border p-2 col-historical" style="width: 10%">HISTORICAL LOGS</th>

        @if($role !== 'Data Encoder')
          <th class="border p-2 col-statuslogs" style="width: 10%">STATUS LOGS</th>
        @endif
      </tr>
    </thead>
  </table>

  <div class="h-[0px]"></div>

  <div id="table-body-wrapper" class="px-4">
    <table class="table-fixed w-full border text-sm" id="table-body">
      <tbody>
        @foreach($records as $record)
          @php
            $checker = strtolower($record['APP SCRIPT CHECKER'] ?? '');
            $shouldHighlight = function($field) use ($checker) {
              if (str_contains($checker, 'full address')) {
                return in_array($field, ['PROVINCE', 'CITY', 'BARANGAY']);
              }
              return match($field) {
                'PROVINCE' => str_contains($checker, 'province'),
                'CITY' => str_contains($checker, 'city'),
                'BARANGAY' => str_contains($checker, 'barangay'),
                default => false,
              };
            };
            $rowClass = ($record->STATUS === 'CANNOT PROCEED') ? 'bg-red-500' : '';
          @endphp

          <tr class="{{ $rowClass }}" data-id="{{ $record->id }}">
            @foreach(['FULL NAME','PHONE NUMBER','ADDRESS','PROVINCE','CITY','BARANGAY','STATUS','ITEM_NAME','COD'] as $field)
              @if (in_array($field, ['ITEM_NAME', 'COD']) && !$canEditItemAndCod)
                @continue
              @endif

              @php
                $editFlag = match($field) {
                  'FULL NAME' => $record->edited_full_name ?? false,
                  'PHONE NUMBER' => $record->edited_phone_number ?? false,
                  'ADDRESS' => $record->edited_address ?? false,
                  'PROVINCE' => $record->edited_province ?? false,
                  'CITY' => $record->edited_city ?? false,
                  'BARANGAY' => $record->edited_barangay ?? false,
                  'ITEM_NAME' => $record->edited_item_name ?? false,
                  'COD' => $record->edited_cod ?? false,
                  default => false
                };

                $finalClass = 'editable-input auto-resize w-full px-2 py-1 border rounded text-sm';
                $customStyle = '';

                if ($editFlag) {
                  $customStyle = 'background-color: #00ff00;';
                } elseif ($shouldHighlight($field)) {
                  $finalClass .= ' bg-red-200';
                }

                $columnWidth = match($field) {
                  'PROVINCE','CITY','BARANGAY','STATUS','ITEM_NAME','COD' => '8%',
                  default => '10%',
                };
              @endphp

              <td class="border p-1 align-top" style="width: {{ $columnWidth }}">
                @if($field === 'STATUS')
                  <select data-id="{{ $record->id }}" data-field="{{ $field }}" class="editable-input w-full px-2 py-1 border rounded text-sm">
                    <option value="">—</option>
                    @foreach(['PROCEED','CANNOT PROCEED','ODZ'] as $option)
                      <option value="{{ $option }}" @selected(($record[$field] ?? '') === $option)>{{ $option }}</option>
                    @endforeach
                  </select>
                @else
                  <textarea
                    data-id="{{ $record->id }}"
                    data-field="{{ $field }}"
                    class="{{ $finalClass }}"
                    style="{{ $customStyle }}"
                    oninput="autoResize(this)"
                  >{{ $record[$field] ?? '' }}</textarea>
                @endif
              </td>
            @endforeach

            {{-- CUSTOMER DETAILS --}}
            <td
              class="border p-2 text-gray-700 cursor-pointer all-user-input customer-details"
              style="width: 20%"
              onclick="expandAndHighlight(this)"
              data-brgy='@json($record->brgy_tokens ?? [])'
              data-city='@json($record->city_tokens ?? [])'
              data-prov='@json($record->prov_tokens ?? [])'
            >
              <span class="all-user-input-text">{{ $record['all_user_input'] }}</span>
            </td>

            {{-- HIST LOGS: expand-only-once --}}
            <td class="border p-2 text-gray-700 log-cell col-historical" style="width: 10%" onclick="expandOnlyOnce(this)">
              <div class="log-content hist-log">
                {!! render_hist_logs($record['HISTORICAL LOGS'] ?? '') !!}
              </div>
            </td>

            {{-- STATUS LOGS: expand-only-once --}}
            @if($role !== 'Data Encoder')
              <td class="border p-2 text-gray-700 log-cell col-statuslogs" style="width: 10%" onclick="expandOnlyOnce(this)">
                <div class="log-content status-log">
                  {!! render_status_logs($record->status_logs ?? '') !!}
                </div>
              </td>
            @endif
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  {{-- ✅ Keep bottom pagination too (optional, but you had it before) --}}
  <div class="mt-6 px-4">
    {{ $records->withQueryString()->links() }}
  </div>

  <script>
    function resetCheckerAndSubmit(form) {
      const checker = form.querySelector('select[name="checker"]');
      if (checker) checker.value = '';
      form.submit();
    }
  </script>

  <script>
    document.getElementById('downloadBtn')?.addEventListener('click', function (e) {
      e.preventDefault();
      const date = document.querySelector('input[name="date"]')?.value || '';
      const page = document.querySelector('select[name="PAGE"]')?.value || '';
      const downloadAll = document.getElementById('downloadAll')?.checked;

      const params = new URLSearchParams();
      if (date) params.set('date', date);
      if (page) params.set('PAGE', page);
      if (downloadAll) params.set('download_all', '1');

      window.location.href = "{{ route('macro_output.download') }}?" + params.toString();
    });
  </script>

  {{-- Column toggles (default unchecked) --}}
  <script>
    function setColumnHidden(selectorClass, hidden) {
      document.querySelectorAll('.' + selectorClass).forEach(el => {
        el.classList.toggle('col-hidden', !!hidden);
      });
    }

    function initColumnToggles() {
      const hist = document.getElementById('toggleHistorical');
      const stat = document.getElementById('toggleStatusLogs');

      setColumnHidden('col-historical', !hist.checked);
      if (stat) setColumnHidden('col-statuslogs', !stat.checked);

      hist.addEventListener('change', () => setColumnHidden('col-historical', !hist.checked));
      stat?.addEventListener('change', () => setColumnHidden('col-statuslogs', !stat.checked));
    }

    window.addEventListener('load', initColumnToggles);
  </script>

  {{-- ✅ Expand only once (no collapse on click) --}}
  <script>
    function expandOnlyOnce(cell) {
      if (!cell.classList.contains('expanded')) cell.classList.add('expanded');
    }
  </script>

  {{-- Editable save --}}
  <script>
    function autoResize(el) {
      el.style.height = 'auto';
      el.style.height = el.scrollHeight + 'px';
    }

    function quickTokens(raw, type) {
      let s = (raw || '').toString().trim();
      if (!s) return [];
      s = s.replace(/\u00A0/g, ' ').replace(/[(){}\[\]"“”'`]+/g, ' ');

      if (type === 'brgy') s = s.replace(/^(brgy\.?|barangay|bgy|brg)\s*/i, '');
      if (type === 'city') s = s.replace(/^(city\s+of|city|municipality\s+of|municipality|mun\.?)\s*/i, '');
      if (type === 'prov') s = s.replace(/^(province\s+of|prov\.?)\s*/i, '');

      const stop = new Set(['brgy','barangay','bgy','brg','city','of','province','prov','municipality','mun']);
      const parts = s.split(/[^0-9A-Za-zñÑ]+/).filter(Boolean);

      const uniq = [];
      for (const p of parts) {
        const t = (p || '').trim();
        if (!t) continue;
        const low = t.toLowerCase();
        if (stop.has(low)) continue;
        if (t.length === 1 && /^[0-9]$/.test(t)) continue;
        if (t.length < 2 && !/^\d{2,}$/.test(t)) continue;
        uniq[t] = true;
      }
      return Object.keys(uniq).sort((a,b) => b.length - a.length);
    }

    document.querySelectorAll('.editable-input').forEach(input => {
      if (input.tagName.toLowerCase() === 'textarea') autoResize(input);

      const handler = function () {
        const id = this.dataset.id;
        const field = this.dataset.field;
        const value = this.value;

        fetch('{{ route("macro_output.update_field") }}', {
          method: 'POST',
          headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({ id, field, value })
        })
        .then(res => res.json())
        .then(data => {
          if (data.status === 'success') {
            this.classList.remove('bg-red-200');
            this.classList.add('bg-green-100');

            const row = this.closest('tr');

            // update tokens after editing
            if (row && (field === 'BARANGAY' || field === 'CITY' || field === 'PROVINCE')) {
              const cell = row.querySelector('.customer-details');
              if (cell) {
                if (field === 'BARANGAY') cell.dataset.brgy = JSON.stringify(quickTokens(value, 'brgy'));
                if (field === 'CITY') cell.dataset.city = JSON.stringify(quickTokens(value, 'city'));
                if (field === 'PROVINCE') cell.dataset.prov = JSON.stringify(quickTokens(value, 'prov'));
              }
            }

            // ✅ Only when STATUS changed: collapse logs (same row) + customer details
            if (field === 'STATUS' && row) {
              const userInputCell = row.querySelector('.customer-details.expanded');
              if (userInputCell) userInputCell.classList.remove('expanded');

              row.querySelectorAll('.log-cell.expanded').forEach(c => c.classList.remove('expanded'));
            }
          } else {
            this.style.backgroundColor = '#fee2e2';
          }
        })
        .catch(() => {
          this.style.backgroundColor = '#fee2e2';
        });
      };

      input.addEventListener('blur', handler);
      input.addEventListener('change', handler);
    });
  </script>

  {{-- Highlight logic --}}
  <script>
    function escapeRegExp(str) {
      return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function highlightTokenInTextNode(textNode, token, className) {
      const text = textNode.nodeValue;
      if (!text || !text.trim()) return false;

      const re = new RegExp(
        `(^|[^\\p{L}\\p{N}])(${escapeRegExp(token)})(?=[^\\p{L}\\p{N}]|\\p{N}|$)`,
        'giu'
      );

      const matches = [...text.matchAll(re)];
      if (!matches.length) return false;

      const frag = document.createDocumentFragment();
      let lastIndex = 0;

      for (const m of matches) {
        const matchStart = m.index ?? 0;
        const prefix = m[1] ?? '';
        const tok = m[2] ?? '';
        const tokenStart = matchStart + prefix.length;

        if (matchStart > lastIndex) frag.appendChild(document.createTextNode(text.slice(lastIndex, matchStart)));
        if (prefix) frag.appendChild(document.createTextNode(prefix));

        const mark = document.createElement('mark');
        mark.className = className;
        mark.textContent = tok;
        frag.appendChild(mark);

        lastIndex = tokenStart + tok.length;
      }

      if (lastIndex < text.length) frag.appendChild(document.createTextNode(text.slice(lastIndex)));

      textNode.parentNode.replaceChild(frag, textNode);
      return true;
    }

    function highlightTokens(container, tokens, className) {
      if (!container || !tokens?.length) return;

      for (const token of tokens) {
        const walker = document.createTreeWalker(
          container,
          NodeFilter.SHOW_TEXT,
          {
            acceptNode(node) {
              if (!node.nodeValue || !node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
              if (node.parentElement && node.parentElement.closest('mark')) return NodeFilter.FILTER_REJECT;
              return NodeFilter.FILTER_ACCEPT;
            }
          }
        );

        const nodes = [];
        while (walker.nextNode()) nodes.push(walker.currentNode);
        nodes.forEach(n => highlightTokenInTextNode(n, token, className));
      }
    }

    function expandAndHighlight(cell) {
      if (!cell.classList.contains('expanded')) cell.classList.add('expanded');

      const span = cell.querySelector('.all-user-input-text');
      if (!span) return;

      if (!span.dataset.originalText) span.dataset.originalText = span.textContent || '';
      span.textContent = span.dataset.originalText;

      const brgyTokens = JSON.parse(cell.dataset.brgy || '[]');
      const cityTokens = JSON.parse(cell.dataset.city || '[]');
      const provTokens = JSON.parse(cell.dataset.prov || '[]');

      highlightTokens(span, brgyTokens, 'hl-brgy');
      highlightTokens(span, cityTokens, 'hl-city');
      highlightTokens(span, provTokens, 'hl-prov');
    }
  </script>

  {{-- ✅ OPTION B: Auto expand + highlight ALL user inputs on page load --}}
  <script>
    window.addEventListener('load', () => {
      document.querySelectorAll('.customer-details').forEach(cell => {
        expandAndHighlight(cell);
      });
    });
  </script>

  {{-- Sticky offset --}}
  <script>
    function adjustTableBodyMargin() {
      const sticky = document.querySelector('.sticky-header');
      const fixed = document.getElementById('fixed-header');
      const bodyWrapper = document.getElementById('table-body-wrapper');

      if (sticky && fixed && bodyWrapper) {
        const totalOffset = sticky.offsetHeight + fixed.offsetHeight;
        bodyWrapper.style.marginTop = totalOffset + 'px';
      }
    }
    window.addEventListener('load', adjustTableBodyMargin);
    window.addEventListener('resize', adjustTableBodyMargin);
  </script>

  {{-- Validate --}}
  <script>
    document.getElementById('validate-btn')?.addEventListener('click', function () {
      const statusEl = document.getElementById('validate-status');
      statusEl.textContent = 'Validating...';
      statusEl.classList.remove('text-green-600');
      statusEl.classList.add('text-gray-600');

      document.querySelectorAll('[data-id][data-field]').forEach(input => {
        input.style.backgroundColor = '';
      });

      const ids = Array.from(document.querySelectorAll('tr[data-id]'))
        .filter(row => row.querySelector('[data-field="STATUS"]')?.value !== 'CANNOT PROCEED')
        .map(row => row.dataset.id);

      fetch("{{ route('macro_output.validate') }}", {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': '{{ csrf_token() }}',
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ ids })
      })
      .then(res => res.json())
      .then(results => {
        let errorCount = 0;

        results.forEach(result => {
          if (!result.invalid_fields) return;

          Object.keys(result.invalid_fields).forEach(field => {
            if (result.invalid_fields[field]) {
              errorCount++;
              const input = document.querySelector(`[data-id="${result.id}"][data-field="${field}"]`);
              if (input) input.style.backgroundColor = '#ff0000';
            }
          });
        });

        statusEl.textContent = errorCount > 0 ? `${errorCount} cell(s) with issues` : 'All good! ✅';
        statusEl.classList.remove('text-gray-600');
        statusEl.classList.add(errorCount > 0 ? 'text-red-600' : 'text-green-600');
      })
      .catch(() => {
        statusEl.textContent = 'Validation failed.';
        statusEl.classList.remove('text-gray-600');
        statusEl.classList.add('text-red-600');
      });
    });
  </script>

  {{-- Item checker --}}
  <script>
    document.getElementById('itemCheckerBtn')?.addEventListener('click', async function () {
      const statusEl = document.getElementById('item-checker-status');
      statusEl.textContent = 'Checking...';
      statusEl.classList.remove('text-green-600', 'text-red-600');
      statusEl.classList.add('text-gray-600');

      const ids = Array.from(document.querySelectorAll('tr[data-id]'))
        .filter(row => row.querySelector('[data-field="STATUS"]')?.value !== 'CANNOT PROCEED')
        .map(row => row.getAttribute('data-id'))
        .filter(Boolean);

      const response = await fetch('/macro_output/validate-items', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': '{{ csrf_token() }}',
        },
        body: JSON.stringify({ ids }),
      });

      const results = await response.json();

      document.querySelectorAll('[data-field="ITEM_NAME"], [data-field="COD"]').forEach(input => {
        input.style.removeProperty('background-color');
      });

      let errorCount = 0;

      results.forEach(result => {
        const { id, invalid_fields } = result;

        if (invalid_fields?.ITEM_NAME) {
          const input = document.querySelector(`[data-id="${id}"][data-field="ITEM_NAME"]`);
          if (input) input.style.setProperty('background-color', '#ff0000', 'important');
          errorCount++;
        }

        if (invalid_fields?.COD) {
          const input = document.querySelector(`[data-id="${id}"][data-field="COD"]`);
          if (input) input.style.setProperty('background-color', '#ff0000', 'important');
          errorCount++;
        }
      });

      statusEl.textContent = errorCount > 0 ? `${errorCount} item(s) need fixing` : 'All good! ✅';
      statusEl.classList.remove('text-gray-600');
      statusEl.classList.add(errorCount > 0 ? 'text-red-600' : 'text-green-600');
    });
  </script>
</x-layout>
