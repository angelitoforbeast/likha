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

    /* ✅ Pancake extra text formatting */
    .pancake-extra {
      margin-top: .5rem;
      font-size: 0.75rem;
      white-space: pre-wrap;
      line-height: 1.25rem;
    }
    .see-more-link {
      display: inline-block;
      margin-top: .25rem;
      font-size: 0.75rem;
      color: #2563eb; /* blue-600 */
      text-decoration: underline;
      cursor: pointer;
    }

    /* ✅ Validation highlight (does NOT overwrite edited-green inline styles) */
    .validate-invalid {
      background-color: #ff0000 !important;
    }

    /* ✅ Custom searchable dropdown (Page) */
    .page-dd { position: relative; width: 220px; }
    .page-dd-btn {
      width: 220px;
      border: 1px solid #d1d5db;
      border-radius: 0.25rem;
      padding: 0.25rem 0.5rem;
      background: white;
      text-align: left;
      font-size: 0.875rem;
      line-height: 1.25rem;
    }
    .page-dd-btn:focus { outline: 2px solid transparent; outline-offset: 2px; }
    .page-dd-panel {
      position: absolute;
      top: calc(100% + 6px);
      left: 0;
      width: 220px;
      background: white;
      border: 1px solid #e5e7eb;
      border-radius: 0.5rem;
      box-shadow: 0 10px 25px rgba(0,0,0,0.08);
      z-index: 9999;
      padding: 0.5rem;
      display: none;
    }
    .page-dd.open .page-dd-panel { display: block; }
    .page-dd-search {
      width: 100%;
      border: 1px solid #d1d5db;
      border-radius: 0.375rem;
      padding: 0.35rem 0.5rem;
      font-size: 0.875rem;
    }
    .page-dd-list {
      margin-top: 0.5rem;
      max-height: 260px;
      overflow: auto;
      border: 1px solid #f3f4f6;
      border-radius: 0.375rem;
    }
    .page-dd-item {
      padding: 0.45rem 0.6rem;
      cursor: pointer;
      font-size: 0.875rem;
      line-height: 1.25rem;
      border-bottom: 1px solid #f3f4f6;
      user-select: none;
    }
    .page-dd-item:last-child { border-bottom: 0; }
    .page-dd-item:hover { background: #f9fafb; }
    .page-dd-item.selected { background: #eef2ff; }
    .page-dd-empty {
      padding: 0.6rem;
      font-size: 0.8rem;
      color: #6b7280;
    }
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

    $currentPage = request('PAGE') ?? '';
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

      {{-- ✅ Page: Click shows ALL, typing FILTERS inside dropdown --}}
      <div class="page-dd" id="pageDd">
        <label class="text-sm font-medium">Page</label>

        {{-- Hidden field submitted to backend --}}
        <input type="hidden" name="PAGE" id="pageHidden" value="{{ $currentPage }}">

        {{-- Button that opens dropdown --}}
        <button type="button" class="page-dd-btn" id="pageDdBtn" aria-haspopup="listbox" aria-expanded="false">
          <span id="pageDdLabel">{{ $currentPage !== '' ? $currentPage : 'All' }}</span>
        </button>

        {{-- Panel --}}
        <div class="page-dd-panel" id="pageDdPanel">
          <input
            type="text"
            class="page-dd-search"
            id="pageDdSearch"
            placeholder="Type to filter..."
            autocomplete="off"
          >
          <div class="page-dd-list" id="pageDdList" role="listbox">
            {{-- All option --}}
            <div class="page-dd-item {{ $currentPage === '' ? 'selected' : '' }}" data-value="">
              All
            </div>

            @foreach($pages as $page)
              <div class="page-dd-item {{ $currentPage == $page ? 'selected' : '' }}" data-value="{{ $page }}">
                {{ $page }}
              </div>
            @endforeach

            <div class="page-dd-empty hidden" id="pageDdEmpty">No matches.</div>
          </div>

          <div class="text-[11px] text-gray-500 mt-2">
            Click dropdown shows all. Type here to filter.
          </div>
        </div>
      </div>

      {{-- ✅ Checker filter dropdown (ONLY: All, Check, To Fix, Blank) --}}
      <div>
        <label class="text-sm font-medium">Filter</label>
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
    @if(!empty($paginateOnlyWhenAll) && $paginateOnlyWhenAll)
      <div class="mt-2">
        {{ $records->withQueryString()->links() }}
      </div>
    @endif

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
              data-fbname="{{ $record->fb_name ?? '' }}"
            >
              <span class="all-user-input-text">{{ $record['all_user_input'] }}</span>

              {{-- ✅ Pancake extra area (filled by JS) --}}
              <div class="pancake-extra hidden"></div>

              {{-- ✅ See more link (blue) --}}
              <a href="#" class="see-more-link">See more</a>
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

  {{-- ✅ Bottom pagination (ONLY when All pages) --}}
  @if(!empty($paginateOnlyWhenAll) && $paginateOnlyWhenAll)
    <div class="mt-6 px-4">
      {{ $records->withQueryString()->links() }}
    </div>
  @endif

  <script>
    function resetCheckerAndSubmit(form) {
      const checker = form.querySelector('select[name="checker"]');
      if (checker) checker.value = '';
      form.submit();
    }
  </script>

  {{-- ✅ Page dropdown JS (click shows all, typing filters) --}}
  <script>
    (function initPageDropdown() {
      const dd = document.getElementById('pageDd');
      if (!dd) return;

      const btn = document.getElementById('pageDdBtn');
      const panel = document.getElementById('pageDdPanel');
      const search = document.getElementById('pageDdSearch');
      const list = document.getElementById('pageDdList');
      const empty = document.getElementById('pageDdEmpty');
      const hidden = document.getElementById('pageHidden');
      const label = document.getElementById('pageDdLabel');

      function norm(s) {
        return (s || '')
          .toString()
          .toLowerCase()
          .normalize('NFD')
          .replace(/[\u0300-\u036f]/g, '')
          .trim();
      }

      function open() {
        dd.classList.add('open');
        btn.setAttribute('aria-expanded', 'true');

        // IMPORTANT: click open -> show ALL
        search.value = '';
        filter('');

        setTimeout(() => search.focus(), 0);
      }

      function close() {
        dd.classList.remove('open');
        btn.setAttribute('aria-expanded', 'false');
      }

      function filter(q) {
        const query = norm(q);
        let shown = 0;

        const items = Array.from(list.querySelectorAll('.page-dd-item'));
        items.forEach(item => {
          const text = norm(item.textContent);
          const isMatch = query === '' ? true : text.includes(query);
          item.style.display = isMatch ? 'block' : 'none';
          if (isMatch) shown++;
        });

        if (empty) {
          empty.classList.toggle('hidden', shown > 0);
        }
      }

      function selectValue(val, text) {
        hidden.value = val;
        label.textContent = (val === '' ? 'All' : text);

        // mark selected class
        list.querySelectorAll('.page-dd-item').forEach(i => i.classList.remove('selected'));
        const selectedEl = Array.from(list.querySelectorAll('.page-dd-item')).find(i => (i.dataset.value ?? '') === (val ?? ''));
        if (selectedEl) selectedEl.classList.add('selected');

        close();

        // Submit filters form
        const form = document.getElementById('filtersForm');
        if (form) resetCheckerAndSubmit(form);
      }

      btn.addEventListener('click', (e) => {
        e.preventDefault();
        if (dd.classList.contains('open')) close();
        else open();
      });

      search.addEventListener('input', () => filter(search.value));

      list.addEventListener('click', (e) => {
        const item = e.target.closest('.page-dd-item');
        if (!item) return;
        const val = item.dataset.value ?? '';
        selectValue(val, item.textContent.trim());
      });

      // close on outside click
      document.addEventListener('click', (e) => {
        if (!dd.classList.contains('open')) return;
        if (dd.contains(e.target)) return;
        close();
      });

      // close on ESC
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && dd.classList.contains('open')) close();
      });
    })();
  </script>

  <script>
    document.getElementById('downloadBtn')?.addEventListener('click', function (e) {
      e.preventDefault();
      const date = document.querySelector('input[name="date"]')?.value || '';
      const page = document.getElementById('pageHidden')?.value || '';
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

      const uniq = {};
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
            this.classList.remove('validate-invalid');
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
            this.classList.add('validate-invalid');
          }
        })
        .catch(() => {
          this.classList.add('validate-invalid');
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

    // ✅ Updated: rebuild as BASE + EXTRA so we don't lose Pancake appended text
    function expandAndHighlight(cell) {
      if (!cell.classList.contains('expanded')) cell.classList.add('expanded');

      const span = cell.querySelector('.all-user-input-text');
      if (!span) return;

      // base text (original all_user_input)
      if (!span.dataset.baseText) span.dataset.baseText = span.textContent || '';

      const extraText = cell.dataset.extraText || '';
      const combined = extraText ? (span.dataset.baseText + "\n\n" + extraText) : span.dataset.baseText;

      span.textContent = combined;

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

  {{-- ✅ Pancake: See more loader --}}
  <script>
    function getCsrfToken() {
      return '{{ csrf_token() }}';
    }

    // ✅ Pancake: See more loader (customers_chat only, NOT appended)
    async function loadPancakeMore(cell, linkEl) {
      const fbName = (cell.dataset.fbname || '').trim();

      // ✅ load once guard (NEW FLAG)
      if (cell.dataset.pancakeLoaded === '1') {
        linkEl.textContent = 'Loaded';
        linkEl.style.pointerEvents = 'none';
        return;
      }

      linkEl.textContent = 'Loading...';
      linkEl.style.pointerEvents = 'none';

      try {
        const res = await fetch("{{ route('macro_output.pancake_more') }}", {
          method: 'POST',
          headers: {
            'X-CSRF-TOKEN': getCsrfToken(),
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({ fb_name: fbName })
        });

        const data = await res.json();

        // ✅ Show ONLY customers_chat (controller should return chat-only in data.text)
        const chatOnly = (data && typeof data.text === 'string') ? data.text : '';

        const extraDiv = cell.querySelector('.pancake-extra');
        if (extraDiv) {
          extraDiv.classList.remove('hidden');
          extraDiv.textContent = chatOnly; // ✅ ONLY customers_chat
        }

        // ✅ mark loaded
        cell.dataset.pancakeLoaded = '1';

        linkEl.textContent = 'Loaded';
      } catch (e) {
        linkEl.textContent = 'See more';
        linkEl.style.pointerEvents = 'auto';

        const extraDiv = cell.querySelector('.pancake-extra');
        if (extraDiv) {
          extraDiv.classList.remove('hidden');
          extraDiv.textContent = '(Load failed)';
        }
      }
    }

    // ✅ Attach click handlers
    window.addEventListener('load', () => {
      document.querySelectorAll('.customer-details .see-more-link').forEach(link => {
        link.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();

          const cell = this.closest('.customer-details');
          if (!cell) return;

          // ✅ only load once (using pancakeLoaded flag)
          if (cell.dataset.pancakeLoaded === '1') {
            this.textContent = 'Loaded';
            this.style.pointerEvents = 'none';
            return;
          }

          loadPancakeMore(cell, this);
        });
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

  {{-- ✅ Validation helpers (FULL NAME rules + move-to-top) --}}
  <script>
    // FULL NAME allowed: A-Z, a-z, ñ/Ñ, space, dot, comma
    // Disallow other scripts (Chinese etc.) by strict whitelist.
    function isValidFullName(v) {
  const s = (v ?? '').toString();
  if (s.trim() === '') return true; // blank = allowed
  return /^[\p{L}\s\.,\-']+$/u.test(s);
}


    function clearValidateMarks() {
      document.querySelectorAll('.validate-invalid').forEach(el => el.classList.remove('validate-invalid'));
    }

    function markInvalid(id, field) {
      const el = document.querySelector(`[data-id="${id}"][data-field="${field}"]`);
      if (el) el.classList.add('validate-invalid');
    }

    function moveRowsWithIssuesToTop(invalidIdsSet) {
      const tbody = document.querySelector('#table-body tbody');
      if (!tbody) return;

      const rows = Array.from(tbody.querySelectorAll('tr[data-id]'));
      if (!rows.length) return;

      const invalidRows = [];
      const okRows = [];

      rows.forEach(r => {
        const id = r.getAttribute('data-id');
        if (invalidIdsSet.has(String(id))) invalidRows.push(r);
        else okRows.push(r);
      });

      const frag = document.createDocumentFragment();
      invalidRows.forEach(r => frag.appendChild(r));
      okRows.forEach(r => frag.appendChild(r));
      tbody.appendChild(frag);
    }
  </script>

  {{-- Validate --}}
  <script>
    document.getElementById('validate-btn')?.addEventListener('click', function () {
      const statusEl = document.getElementById('validate-status');
      statusEl.textContent = 'Validating...';
      statusEl.classList.remove('text-green-600', 'text-red-600');
      statusEl.classList.add('text-gray-600');

      // ✅ clear only validation reds
      clearValidateMarks();

      const rows = Array.from(document.querySelectorAll('tr[data-id]'));
      const ids = rows
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
        const invalidRowIds = new Set();

        // 1) server-side invalid fields (province/city/brgy + others if your API adds later)
        results.forEach(result => {
          const id = String(result.id);
          if (!result.invalid_fields) return;

          let rowHasIssue = false;

          Object.keys(result.invalid_fields).forEach(field => {
            if (result.invalid_fields[field]) {
              errorCount++;
              rowHasIssue = true;
              markInvalid(id, field);
            }
          });

          if (rowHasIssue) invalidRowIds.add(id);
        });

        // 2) client-side FULL NAME validation
        rows.forEach(row => {
          const id = String(row.dataset.id || '');
          if (!id) return;

          const status = row.querySelector('[data-field="STATUS"]')?.value || '';
          if (status === 'CANNOT PROCEED') return;

          const fullNameEl = row.querySelector('[data-field="FULL NAME"]');
          if (!fullNameEl) return;

          const fullNameVal = (fullNameEl.value ?? fullNameEl.textContent ?? '').toString();
          if (!isValidFullName(fullNameVal)) {
            errorCount++;
            invalidRowIds.add(id);
            markInvalid(id, 'FULL NAME');
          }
        });

        // 3) move invalid rows to top
        if (invalidRowIds.size > 0) {
          moveRowsWithIssuesToTop(invalidRowIds);
        }

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

      // ✅ clear only validation reds
      clearValidateMarks();

      const rows = Array.from(document.querySelectorAll('tr[data-id]'));
      const ids = rows
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

      let errorCount = 0;
      const invalidRowIds = new Set();

      results.forEach(result => {
        const id = String(result.id);
        const invalid = result.invalid_fields || {};

        let rowHasIssue = false;

        if (invalid.ITEM_NAME) {
          markInvalid(id, 'ITEM_NAME');
          errorCount++;
          rowHasIssue = true;
        }

        if (invalid.COD) {
          markInvalid(id, 'COD');
          errorCount++;
          rowHasIssue = true;
        }

        if (rowHasIssue) invalidRowIds.add(id);
      });

      // move invalid rows to top
      if (invalidRowIds.size > 0) {
        moveRowsWithIssuesToTop(invalidRowIds);
      }

      statusEl.textContent = errorCount > 0 ? `${errorCount} item(s) need fixing` : 'All good! ✅';
      statusEl.classList.remove('text-gray-600');
      statusEl.classList.add(errorCount > 0 ? 'text-red-600' : 'text-green-600');
    });
  </script>

</x-layout>
