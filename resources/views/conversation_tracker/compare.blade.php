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
    .compare-section th, .compare-section td { padding:8px 12px; border-bottom:1px solid #f1f5f9; text-align:left; }
    .compare-section th { background:#f8fafc; font-size:11px; color:#475569; text-transform:uppercase; letter-spacing:0.05em; }
    .compare-section td.num { text-align:right; font-variant-numeric:tabular-nums; }
    .compare-section td.metric-label { color:#475569; font-weight:500; min-width:140px; }
    .compare-section .winner { background:#f0fdf4; color:#15803d; font-weight:700; }
    .compare-section .empty-cell { color:#94a3b8; font-style:italic; }

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

        {{-- Set picker cards --}}
        <div class="mt-4">
          <label class="ct-label">Variants to compare (max 4)</label>
          <div class="flex flex-wrap gap-3" id="setCards">
            @php
              $renderVariants = $variants;
              while (count($renderVariants) < 2) $renderVariants[] = ''; // always show 2 slots minimum
            @endphp
            @foreach ($renderVariants as $idx => $v)
              <div class="set-card {{ $v ? 'active' : '' }}">
                <div class="set-card-header">
                  <span>Set {{ chr(65 + $idx) }}</span>
                  @if ($idx >= 2)
                    <button type="button" class="ct-btn danger" style="font-size:11px;padding:2px 8px;" onclick="removeSet(this)">×</button>
                  @endif
                </div>
                <select name="variants[]" class="ct-input">
                  <option value="">— Select flow —</option>
                  @foreach ($allFlowNames as $fn)
                    <option value="{{ $fn }}" @if($v === $fn) selected @endif>{{ $fn }}</option>
                  @endforeach
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
      {{-- Set summary header --}}
      <div class="ct-card">
        <div class="text-xs uppercase font-semibold text-slate-500 mb-2">Set summary</div>
        <div class="grid grid-cols-1 md:grid-cols-{{ min(count($sets), 4) }} gap-3">
          @foreach ($sets as $idx => $s)
            <div class="bg-slate-50 rounded-lg p-3 border border-slate-200">
              <div class="text-xs font-semibold text-blue-700 mb-1">Set {{ chr(65 + $idx) }}</div>
              <div class="text-sm font-bold text-slate-800">{{ $s['variant'] }}</div>
              <div class="text-xs text-slate-500 mt-1">{{ number_format($s['rows']) }} rows in this set</div>
            </div>
          @endforeach
        </div>
      </div>

      {{-- Per-flow comparison sections --}}
      @forelse ($comparison as $row)
        @php
          $flow = $row['flow'];
          $cls = 'other';
          if (str_starts_with($flow, 'LOOP'))     $cls = 'loop';
          elseif (str_starts_with($flow, 'MAIN')) $cls = 'main';
          elseif (str_starts_with($flow, 'SEQ'))  $cls = 'seq';

          // Check if any set has data for this flow (skip flow if all empty)
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
                    <th class="num">Set {{ chr(65 + $idx) }} — {{ $sd['variant'] }}</th>
                  @endforeach
                </tr>
              </thead>
              <tbody>
                @php
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

                @foreach ($metricsToShow as $key => $label)
                  @php
                    // Find max value for "winner" highlighting
                    $values = array_map(fn($sd) => $sd[$key], $row['sets']);
                    $valuesNonNull = array_filter($values, fn($v) => $v !== null);
                    $max = !empty($valuesNonNull) ? max($valuesNonNull) : null;
                    $hasMultipleNonNull = count($valuesNonNull) > 1;
                  @endphp
                  <tr>
                    <td class="metric-label">{{ $label }}</td>
                    @foreach ($row['sets'] as $sd)
                      @php
                        $val = $sd[$key];
                        $isWinner = $hasMultipleNonNull && $val !== null && $val == $max && $val > 0;
                        $isPct = in_array($key, $isPctMetric);
                      @endphp
                      <td class="num {{ $isWinner ? 'winner' : '' }}">
                        @if ($val === null)
                          <span class="empty-cell">—</span>
                        @elseif ($isPct)
                          {{ number_format($val, 1) }}%{{ $isWinner ? ' 🏆' : '' }}
                        @else
                          {{ number_format($val) }}{{ $isWinner ? ' 🏆' : '' }}
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
        <div class="ct-card text-center text-slate-500 py-8">
          No data found for selected variants. Adjust filters or pick different variants.
        </div>
      @endforelse
    @endif

  </div>

  <script>
    function addSet() {
      const container = document.getElementById('setCards');
      const addBtn = document.getElementById('addSetBtn');
      const existingCards = container.querySelectorAll('.set-card');
      const idx = existingCards.length;

      if (idx >= 4) return;

      // Get all flow options from existing dropdown (clone)
      const firstSelect = container.querySelector('select[name="variants[]"]');
      if (!firstSelect) return;
      const optionsHtml = firstSelect.innerHTML;

      const newCard = document.createElement('div');
      newCard.className = 'set-card';
      newCard.innerHTML = `
        <div class="set-card-header">
          <span>Set ${String.fromCharCode(65 + idx)}</span>
          <button type="button" class="ct-btn danger" style="font-size:11px;padding:2px 8px;" onclick="removeSet(this)">×</button>
        </div>
        <select name="variants[]" class="ct-input">${optionsHtml}</select>
      `;
      // Reset the cloned select's selected value
      const newSelect = newCard.querySelector('select');
      newSelect.value = '';

      container.insertBefore(newCard, addBtn);

      if (idx + 1 >= 4) {
        addBtn.style.display = 'none';
      }
    }

    function removeSet(btn) {
      const card = btn.closest('.set-card');
      if (card) {
        card.remove();
        // Re-show add button kung naka-hide
        const addBtn = document.getElementById('addSetBtn');
        if (addBtn) addBtn.style.display = '';
        // Re-label remaining cards (Set A, Set B, Set C, ...)
        const cards = document.querySelectorAll('#setCards .set-card');
        cards.forEach((c, i) => {
          const header = c.querySelector('.set-card-header span');
          if (header) header.textContent = 'Set ' + String.fromCharCode(65 + i);
        });
      }
    }
  </script>
</x-layout>
