<x-layout>
  <x-slot name="title">Flow Compare</x-slot>
  <x-slot name="heading">📊 Flow comparison (A/B testing)</x-slot>

  <style>
    .ct-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); padding:14px; }
    .ct-label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:4px; display:block; }
    .ct-input { font-size:13px; padding:6px 10px; border-radius:8px; border:1px solid #cbd5e1; width:100%; background:#fff; }
    .ct-btn { font-size:13px; padding:7px 14px; border-radius:8px; font-weight:600; cursor:pointer; border:none; }
    .ct-btn.primary { background:#3b82f6; color:#fff; }
    .ct-btn.primary:hover { background:#2563eb; }
    .ct-btn.ghost { background:#fff; color:#475569; border:1px solid #e2e8f0; }
    .ct-btn.ghost:hover { background:#f1f5f9; }
    .ct-btn.danger { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }

    .set-card { border:2px dashed #cbd5e1; border-radius:12px; padding:12px; min-width:200px; flex:1; background:#fafafa; }
    .set-card.active { border-style:solid; border-color:#3b82f6; background:#fff; }
    .set-card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; font-size:12px; font-weight:600; color:#1e40af; }
    .add-set-card { border:2px dashed #cbd5e1; border-radius:12px; padding:24px; min-width:160px; display:flex; align-items:center; justify-content:center; cursor:pointer; color:#64748b; font-size:13px; }
    .add-set-card:hover { background:#f0f9ff; border-color:#3b82f6; color:#1e40af; }

    .compare-section { background:#fff; border:1px solid #e5e7eb; border-radius:12px; margin-bottom:14px; overflow:hidden; }
    .compare-section-header { background:#f8fafc; padding:8px 14px; border-bottom:1px solid #e2e8f0; font-weight:600; font-size:13px; color:#0f172a; display:flex; gap:8px; align-items:center; }
    .compare-section table { width:100%; font-size:13px; border-collapse:collapse; }
    .compare-section th, .compare-section td { padding:8px 12px; border-bottom:1px solid #f1f5f9; }
    .compare-section th { background:#f8fafc; font-size:11px; color:#475569; text-transform:uppercase; letter-spacing:0.05em; text-align:center; vertical-align:top; }
    .compare-section th:first-child, .compare-section td:first-child { text-align:left; }
    .compare-section td.num { text-align:center; font-variant-numeric:tabular-nums; }
    .compare-section td.metric-label { color:#475569; font-weight:500; min-width:140px; text-align:left; }
    /* Conditional formatting — only for rate columns: bold + green bg */
    .compare-section td.rate-winner { background:#00ff00; font-weight:700; color:#000; }
    .compare-section .empty-cell { color:#94a3b8; font-style:italic; }

    /* Set summary header in picked-variants table */
    .set-header-cell { padding:10px 12px; background:#f1f5f9; vertical-align:top; min-width:200px; }
    .set-header-label { font-size:10.5px; font-weight:700; color:#3730a3; text-transform:uppercase; letter-spacing:0.05em; }
    .set-header-name { font-size:14px; font-weight:700; color:#0f172a; margin-top:2px; }
    .set-header-rows { font-size:11px; color:#64748b; margin-top:2px; }

    /* Bubbles preview sa baba ng set summary — Messenger style */
    .bubbles-cell { padding:10px 12px; background:#fafafa; vertical-align:top; max-width:320px; }
    /* Full-width row toggle for Content section */
    .content-row-toggle { cursor:pointer; background:#f8fafc; padding:10px 12px; text-align:left !important; user-select:none; font-weight:400 !important; text-transform:none !important; letter-spacing:normal !important; }
    .content-row-toggle:hover { background:#f1f5f9; }
    .content-row-toggle .label { font-size:12px; font-weight:400; color:#475569; text-transform:none; letter-spacing:normal; }
    .content-row-toggle .arrow { font-size:11px; color:#475569; transition:transform 0.15s; float:right; }
    .content-row-toggle.expanded .arrow { transform:rotate(90deg); }
    /* Bubbles row cells — override th defaults (bold, uppercase, center) */
    .content-bubbles-row th { font-weight:normal !important; text-transform:none !important; letter-spacing:normal !important; text-align:left !important; background:#fafafa !important; }
    .bubbles-content { max-height:400px; overflow-y:auto; }
    /* Messenger-style chat bubble */
    .bubble-preview { background:#f1f5f9; border-radius:18px; padding:8px 12px; margin-bottom:6px; font-size:12px; color:#0f172a; line-height:1.4; max-width:85%; word-wrap:break-word; }
    .bubble-preview img, .bubble-preview video { max-width:100%; max-height:160px; border-radius:12px; display:block; }
    .bubble-preview .bubble-text { white-space:pre-wrap; word-break:break-word; }
    .bubble-preview .bubble-caption { font-size:10.5px; color:#64748b; font-style:italic; margin-top:4px; padding-left:2px; }
    .bubble-preview.media { background:transparent; padding:0; }
    .bubble-preview.media .bubble-caption { padding:0 2px; margin-top:2px; }
    .no-bubbles { color:#94a3b8; font-style:italic; font-size:11px; text-align:center; padding:12px; }

    .pill-flow { display:inline-flex; align-items:center; padding:2px 10px; border-radius:999px; font-size:11px; font-weight:600; }
    .pill-flow.loop { background:#fef3c7; color:#92400e; }
    .pill-flow.main { background:#dcfce7; color:#166534; }
    .pill-flow.seq  { background:#e0e7ff; color:#3730a3; }
    .pill-flow.other{ background:#f1f5f9; color:#475569; }
  </style>

  <div class="w-full flex flex-col gap-3 p-2">

    {{-- Top action bar --}}
    <div class="flex justify-between items-center">
      <div class="text-xs text-slate-500">
        Compare flow variants side-by-side. Pick 2-4 flows. Auto-detects all unique flow names sa data.
      </div>
      <div class="flex gap-2">
        <a href="{{ route('conversation.tracker.stats') }}" class="ct-btn ghost">← Back to stats</a>
      </div>
    </div>

    {{-- Filters (shared across all sets) --}}
    <div class="ct-card">
      <form method="GET" action="{{ route('conversation.tracker.compare') }}" id="compareForm">
        <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
          <div>
            <label class="ct-label">Page</label>
            <select name="page_name" class="ct-input">
              <option value="all">All pages</option>
              @foreach ($pages as $p)
                <option value="{{ $p }}" @if($pageName === $p) selected @endif>{{ $p }}</option>
              @endforeach
            </select>
          </div>
          <div>
            <label class="ct-label">From date</label>
            <input type="date" name="from_date" value="{{ $fromDate }}" class="ct-input">
          </div>
          <div>
            <label class="ct-label">To date</label>
            <input type="date" name="to_date" value="{{ $toDate }}" class="ct-input">
          </div>
          <div>
            <label class="ct-label">Reply flag</label>
            <input type="text" name="reply_flag" value="{{ $replyFlag }}" class="ct-input">
          </div>
          <div>
            <label class="ct-label">Order flag</label>
            <input type="text" name="order_flag" value="{{ $orderFlag }}" class="ct-input">
          </div>
        </div>

        {{-- Set picker cards — cascading Page → Flow dropdowns per set --}}
        <div class="mt-4">
          <label class="ct-label">Variants to compare (max 4) — pick a Page first, then the Flow</label>
          <div class="flex flex-wrap gap-3" id="setCards">
            @php
              $renderVariants = $variants;
              while (count($renderVariants) < 2) $renderVariants[] = ''; // always show 2 slots minimum
            @endphp
            @foreach ($renderVariants as $idx => $v)
              {{-- Restore variant's page from submitted form data (paired by index) --}}
              @php
                $variantPage = $variantPages[$idx] ?? '';
              @endphp
              <div class="set-card {{ $v ? 'active' : '' }}">
                <div class="set-card-header">
                  <span>Set {{ chr(65 + $idx) }}</span>
                  @if ($idx >= 2)
                    <button type="button" class="ct-btn danger" style="font-size:11px;padding:2px 8px;" onclick="removeSet(this)">×</button>
                  @endif
                </div>
                {{-- Page dropdown — submitted as variant_pages[], paired by index sa variants[] --}}
                <select name="variant_pages[]" class="ct-input cascade-page" data-set-idx="{{ $idx }}" style="margin-bottom:6px;">
                  <option value="">— Any page (use global filter) —</option>
                  @foreach ($flowsByPage as $pg => $flows)
                    <option value="{{ $pg }}" @if($variantPage === $pg) selected @endif>{{ $pg }}</option>
                  @endforeach
                </select>
                {{-- Flow dropdown — populated by JS based on selected page. Empty placeholder
                     pag walang page picked (forced cascade). --}}
                <select name="variants[]" class="ct-input cascade-flow" data-set-idx="{{ $idx }}" data-current-flow="{{ $v }}">
                  @if ($variantPage !== '' && isset($flowsByPage[$variantPage]))
                    <option value="">— Select flow —</option>
                    @foreach ($flowsByPage[$variantPage] as $fn)
                      <option value="{{ $fn }}" @if($v === $fn) selected @endif>{{ $fn }}</option>
                    @endforeach
                  @else
                    {{-- Walang page picked — empty (force user to pick page first) --}}
                    <option value="">— Pick a page first —</option>
                  @endif
                </select>
              </div>
            @endforeach
            @if (count($renderVariants) < 4)
              <div class="add-set-card" onclick="addSet()" id="addSetBtn">
                + Add Set
              </div>
            @endif
          </div>
        </div>

        <div class="mt-4 flex gap-2 items-center">
          <button type="submit" class="ct-btn primary">Compare</button>
          <a href="{{ route('conversation.tracker.compare') }}" class="ct-btn ghost">Reset</a>
          <span class="text-xs text-slate-500 ml-auto">
            {{ count($allFlowNames) }} unique flows detected
          </span>
        </div>
      </form>
    </div>

    {{-- Comparison results --}}
    @if (empty($variants) || count($variants) < 1)
      <div class="ct-card text-center text-slate-500 py-8">
        Pumili ng 2 or more variants sa taas para sa comparison.
      </div>
    @else
      {{-- Set summary card replaced by integrated header sa picked variants table below --}}

      @php
        // Pre-build a lookup ng picked variants para sa special handling
        $variantsUpper = array_map(fn($v) => strtoupper(trim($v)), $variants);

        // Map: variant name (uppercase) → comparison row
        $variantRowsByName = [];
        foreach ($comparison as $row) {
          $rowFlowUpper = strtoupper(trim($row['flow']));
          if (in_array($rowFlowUpper, $variantsUpper, true)) {
            $variantRowsByName[$rowFlowUpper] = $row;
          }
        }

        // Other (non-picked) rows
        $otherRows = array_values(array_filter($comparison, function ($r) use ($variantsUpper) {
          return !in_array(strtoupper(trim($r['flow'])), $variantsUpper, true);
        }));

        $metricsToShow = [
          'total'         => 'Total',
          'replied'       => 'Replied',
          'replied_cells' => 'Replied Cells',
          'ordered'       => 'Customer Ordered',
          'reply_rate'    => 'Reply rate',
          'conv_total'    => 'Conv rate (vs total)',
          'conv_replied'  => 'Conv rate (vs replied)',
        ];
        $isPctMetric = ['reply_rate', 'conv_total', 'conv_replied'];
      @endphp

      {{-- ═════════════════════════════════════════════
           PICKED VARIANTS COMPARISON — Integrated table:
             Header row 1: Set summary (label + variant name + row count)
             Header row 2: Flow content (bubbles preview)
             Body: Per-metric values (rates have conditional formatting)
           Each set's column shows THAT set's own variant data.
           ═════════════════════════════════════════════ --}}
      @if (!empty($variantsUpper))
        <div class="compare-section" style="border:2px solid #3b82f6;">
          <div class="compare-section-header" style="background:#eff6ff;">
            <span class="pill-flow main">📊 Picked variants comparison</span>
            <span class="text-xs text-slate-500 font-normal">— each column shows that set's own variant data</span>
          </div>
          <table>
            <thead>
              {{-- Set summary row (was a separate card) --}}
              <tr>
                <th class="set-header-cell" style="background:#f1f5f9;">
                  <div class="set-header-label">Set summary</div>
                </th>
                @foreach ($sets as $idx => $s)
                  <th class="set-header-cell">
                    <div class="set-header-label">Set {{ chr(65 + $idx) }}</div>
                    @if (!empty($s['page']))
                      <div class="text-[10.5px] text-slate-500" style="font-style:italic;">{{ $s['page'] }}</div>
                    @endif
                    <div class="set-header-name">{{ $s['variant'] }}</div>
                    <div class="set-header-rows">{{ number_format($s['rows']) }} rows in this set</div>
                  </th>
                @endforeach
              </tr>
              {{-- Flow content (bubbles) — single full-width row toggle, expands to bubbles row --}}
              <tr>
                <th colspan="{{ count($sets) + 1 }}" class="content-row-toggle" onclick="toggleContentRow(this)">
                  <span class="label">Content</span>
                  <span class="arrow">▶</span>
                </th>
              </tr>
              <tr class="content-bubbles-row" style="display:none;">
                <th class="bubbles-cell" style="background:#fafafa;"></th>
                @foreach ($sets as $idx => $s)
                  <th class="bubbles-cell">
                    @php
                      $bubbles = $bubblesByVariant[$s['variant']] ?? [];
                    @endphp
                    @if (empty($bubbles))
                      <div class="no-bubbles">No bubbles saved for this flow</div>
                    @else
                      <div class="bubbles-content">
                        @foreach ($bubbles as $b)
                          @php
                            $btype    = $b['type'] ?? 'text';
                            $btext    = $b['text'] ?? '';
                            $burl     = $b['url']  ?? '';
                            $bcaption = $b['caption'] ?? '';
                          @endphp
                          @if ($btype === 'text')
                            <div class="bubble-preview">
                              <div class="bubble-text">{{ $btext }}</div>
                            </div>
                          @elseif ($btype === 'image' && $burl)
                            <div class="bubble-preview media">
                              <img src="{{ $burl }}" alt="">
                              @if ($bcaption)<div class="bubble-caption">{{ $bcaption }}</div>@endif
                            </div>
                          @elseif ($btype === 'video' && $burl)
                            <div class="bubble-preview media">
                              <video controls src="{{ $burl }}"></video>
                              @if ($bcaption)<div class="bubble-caption">{{ $bcaption }}</div>@endif
                            </div>
                          @else
                            <div class="bubble-preview">
                              <div class="bubble-text" style="color:#94a3b8;">— no content —</div>
                            </div>
                          @endif
                        @endforeach
                      </div>
                    @endif
                  </th>
                @endforeach
              </tr>
              {{-- Metric column header --}}
              <tr>
                <th>Metric</th>
                @foreach ($sets as $idx => $s)
                  <th>
                    <div>Set {{ chr(65 + $idx) }}</div>
                    @if (!empty($s['page']))
                      <div class="text-[10px] text-slate-500" style="font-style:italic;font-weight:normal;">{{ $s['page'] }}</div>
                    @endif
                    <div style="font-weight:600;">{{ $s['variant'] }}</div>
                  </th>
                @endforeach
              </tr>
            </thead>
            <tbody>
              @foreach ($metricsToShow as $key => $label)
                @php
                  // For each set, get THAT set's data sa ITS OWN variant
                  $perSetValues = [];
                  foreach ($sets as $idx => $s) {
                    $variantUpper = strtoupper(trim($s['variant']));
                    $variantRow = $variantRowsByName[$variantUpper] ?? null;
                    $perSetValues[] = $variantRow ? $variantRow['sets'][$idx][$key] : null;
                  }
                  $nonNullValues = array_filter($perSetValues, fn($v) => $v !== null);
                  $max = !empty($nonNullValues) ? max($nonNullValues) : null;
                  $hasMultipleNonNull = count($nonNullValues) > 1;
                  $isPct = in_array($key, $isPctMetric);
                @endphp
                <tr>
                  <td class="metric-label">{{ $label }}</td>
                  @foreach ($perSetValues as $val)
                    @php
                      $isWinner = $hasMultipleNonNull && $val !== null && $val == $max && $val > 0;
                      // Apply conditional formatting only sa rate columns
                      $applyWinner = $isWinner && $isPct;
                    @endphp
                    <td class="num {{ $applyWinner ? 'rate-winner' : '' }}">
                      @if ($val === null)
                        <span class="empty-cell">—</span>
                      @elseif ($isPct)
                        {{ number_format($val, 1) }}%
                      @else
                        {{ number_format($val) }}
                      @endif
                    </td>
                  @endforeach
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif

      {{-- ═════════════════════════════════════════════
           OTHER FLOWS (non-picked) — cross-comparison
           Same flow name across sets, side-by-side metrics
           ═════════════════════════════════════════════ --}}
      @forelse ($otherRows as $row)
        @php
          $flow = $row['flow'];
          $cls = 'other';
          if (str_starts_with($flow, 'LOOP'))     $cls = 'loop';
          elseif (str_starts_with($flow, 'MAIN')) $cls = 'main';
          elseif (str_starts_with($flow, 'SEQ'))  $cls = 'seq';

          $anyData = false;
          foreach ($row['sets'] as $sd) {
            if ($sd['has_data']) { $anyData = true; break; }
          }
        @endphp

        @if ($anyData)
          <div class="compare-section">
            <div class="compare-section-header">
              <span class="pill-flow {{ $cls }}">{{ $flow }}</span>
              <span class="text-xs text-slate-500 font-normal">— per-set metrics</span>
            </div>
            <table>
              <thead>
                <tr>
                  <th>Metric</th>
                  @foreach ($row['sets'] as $idx => $sd)
                    <th>
                      <div>Set {{ chr(65 + $idx) }}</div>
                      @if (!empty($sd['page']))
                        <div class="text-[10px] text-slate-500" style="font-style:italic;font-weight:normal;">{{ $sd['page'] }}</div>
                      @endif
                      <div style="font-weight:600;">{{ $sd['variant'] }}</div>
                    </th>
                  @endforeach
                </tr>
              </thead>
              <tbody>
                @foreach ($metricsToShow as $key => $label)
                  @php
                    $values = array_map(fn($sd) => $sd[$key], $row['sets']);
                    $valuesNonNull = array_filter($values, fn($v) => $v !== null);
                    $max = !empty($valuesNonNull) ? max($valuesNonNull) : null;
                    $hasMultipleNonNull = count($valuesNonNull) > 1;
                    $isPct = in_array($key, $isPctMetric);
                  @endphp
                  <tr>
                    <td class="metric-label">{{ $label }}</td>
                    @foreach ($row['sets'] as $sd)
                      @php
                        $val = $sd[$key];
                        $isWinner = $hasMultipleNonNull && $val !== null && $val == $max && $val > 0;
                        // Conditional formatting only sa rate columns
                        $applyWinner = $isWinner && $isPct;
                      @endphp
                      <td class="num {{ $applyWinner ? 'rate-winner' : '' }}">
                        @if ($val === null)
                          <span class="empty-cell">—</span>
                        @elseif ($isPct)
                          {{ number_format($val, 1) }}%
                        @else
                          {{ number_format($val) }}
                        @endif
                      </td>
                    @endforeach
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endif
      @empty
        @if (empty($variantsUpper))
          <div class="ct-card text-center text-slate-500 py-8">
            No data found for selected variants. Adjust filters or pick different variants.
          </div>
        @endif
      @endforelse
    @endif

  </div>

  <script>
    function toggleContentRow(headerCell) {
      const toggleRow = headerCell.closest('tr');
      const bubblesRow = toggleRow ? toggleRow.nextElementSibling : null;
      if (!bubblesRow) return;
      const isHidden = bubblesRow.style.display === 'none' || bubblesRow.style.display === '';
      bubblesRow.style.display = isHidden ? '' : 'none';
      headerCell.classList.toggle('expanded', isHidden);
    }

    // Flows-by-page map para sa cascading dropdown — bigay ng controller
    const FLOWS_BY_PAGE = @json($flowsByPage ?? []);
    const ALL_FLOW_NAMES = @json($allFlowNames ?? []);

    /**
     * Repopulate yung Flow dropdown ng isang set card based sa selected Page.
     * Forced cascade: pag walang Page selected, Flow dropdown ay empty placeholder lang
     * (force user to pick Page first).
     */
    function repopulateFlowDropdown(setCard, preserveValue) {
      const pageSel = setCard.querySelector('.cascade-page');
      const flowSel = setCard.querySelector('.cascade-flow');
      if (!pageSel || !flowSel) return;

      const selectedPage = pageSel.value;
      const currentFlow = preserveValue !== undefined ? preserveValue : flowSel.value;

      // Walang page picked — show only placeholder, force user to pick page first
      if (!selectedPage || !FLOWS_BY_PAGE[selectedPage]) {
        flowSel.innerHTML = '<option value="">— Pick a page first —</option>';
        return;
      }

      const flowsList = FLOWS_BY_PAGE[selectedPage];
      let html = '<option value="">— Select flow —</option>';
      flowsList.forEach(fn => {
        const sel = (fn === currentFlow) ? 'selected' : '';
        const safe = String(fn).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        html += `<option value="${safe}" ${sel}>${safe}</option>`;
      });
      flowSel.innerHTML = html;
    }

    // Wire cascade: when Page changes → repopulate Flow dropdown
    function wireCascade(setCard) {
      const pageSel = setCard.querySelector('.cascade-page');
      if (!pageSel) return;
      pageSel.addEventListener('change', () => repopulateFlowDropdown(setCard));
    }

    // Wire all existing set cards on page load
    document.querySelectorAll('#setCards .set-card').forEach(wireCascade);

    function addSet() {
      const container = document.getElementById('setCards');
      const addBtn = document.getElementById('addSetBtn');
      const existingCards = container.querySelectorAll('.set-card');
      const idx = existingCards.length;

      // Compute label: A-Z then AA, AB, ... for >26 sets
      const label = (idx < 26)
        ? String.fromCharCode(65 + idx)
        : String.fromCharCode(65 + Math.floor(idx / 26) - 1) + String.fromCharCode(65 + (idx % 26));

      // Build Page dropdown options
      let pageOptions = '<option value="">— Any page (use global filter) —</option>';
      Object.keys(FLOWS_BY_PAGE).forEach(pg => {
        const safe = String(pg).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        pageOptions += `<option value="${safe}">${safe}</option>`;
      });

      // Flow dropdown initial state — empty placeholder (forced cascade).
      // Mag-popula lang pagka pumili ng page (handled by repopulateFlowDropdown).
      let flowOptions = '<option value="">— Pick a page first —</option>';

      const newCard = document.createElement('div');
      newCard.className = 'set-card';
      newCard.innerHTML = `
        <div class="set-card-header">
          <span>Set ${label}</span>
          <button type="button" class="ct-btn danger" style="font-size:11px;padding:2px 8px;" onclick="removeSet(this)">×</button>
        </div>
        <select name="variant_pages[]" class="ct-input cascade-page" data-set-idx="${idx}" style="margin-bottom:6px;">${pageOptions}</select>
        <select name="variants[]" class="ct-input cascade-flow" data-set-idx="${idx}">${flowOptions}</select>
      `;

      container.insertBefore(newCard, addBtn);
      wireCascade(newCard);
    }

    function setLabel(idx) {
      // A-Z for first 26, then AA, AB, ... AZ, BA, BB ... for 26+
      if (idx < 26) return String.fromCharCode(65 + idx);
      return String.fromCharCode(65 + Math.floor(idx / 26) - 1) + String.fromCharCode(65 + (idx % 26));
    }

    function removeSet(btn) {
      const card = btn.closest('.set-card');
      if (card) {
        card.remove();
        // Re-label remaining cards
        const cards = document.querySelectorAll('#setCards .set-card');
        cards.forEach((c, i) => {
          const header = c.querySelector('.set-card-header span');
          if (header) header.textContent = 'Set ' + setLabel(i);
        });
      }
    }
  </script>
</x-layout>
