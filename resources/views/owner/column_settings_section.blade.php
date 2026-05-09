<x-layout>
  <x-slot name="title">Column Settings</x-slot>
  <x-slot name="heading">Column Settings</x-slot>

  <style>
    .col-section { background:white; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:16px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .col-section h3 { font-size:14px; font-weight:700; color:#0f172a; margin-bottom:6px; }
    .col-section p.note { font-size:11px; color:#64748b; margin-bottom:12px; }
    .col-list { border:1px solid #e2e8f0; border-radius:6px; background:#f8fafc; }
    .col-item { display:flex; align-items:center; gap:8px; padding:8px 12px; background:white; border-bottom:1px solid #e2e8f0; cursor:grab; user-select:none; }
    .col-item:last-child { border-bottom:none; }
    .col-item:hover { background:#f1f5f9; }
    .col-item.dragging { opacity:0.4; }
    .col-item.drag-over { border-top:2px solid #2563eb; }
    .col-handle { color:#94a3b8; font-size:14px; cursor:grab; }
    .col-handle:active { cursor:grabbing; }
    .col-label { flex:1; font-size:13px; color:#1e293b; font-weight:500; }
    .role-header { display:flex; gap:8px; padding:8px 12px; background:#eef2ff; border-bottom:1px solid #c7d2fe; font-size:10px; font-weight:700; color:#3730a3; text-transform:uppercase; letter-spacing:0.04em; align-items:center; position:sticky; top:0; z-index:1; }
    .role-header .spacer { flex:1; }
    .role-header .role-col { width:78px; text-align:center; }
    .role-cell { width:78px; text-align:center; flex-shrink:0; }
    .role-cell input[type="checkbox"] { transform:scale(1.1); cursor:pointer; }
    .role-cell.ceo input[type="checkbox"] { accent-color:#16a34a; }
    .save-btn { background:#2563eb; color:white; padding:8px 18px; border-radius:6px; font-weight:600; font-size:13px; cursor:pointer; border:none; }
    .save-btn:hover { background:#1d4ed8; }
    .save-btn:disabled { background:#94a3b8; cursor:not-allowed; }
    .reset-btn { background:white; color:#374151; padding:8px 14px; border-radius:6px; font-weight:600; font-size:12px; cursor:pointer; border:1px solid #d1d5db; }
    .reset-btn:hover { background:#f3f4f6; }
    .toast { position:fixed; bottom:20px; right:20px; background:#16a34a; color:white; padding:10px 18px; border-radius:6px; font-weight:600; font-size:13px; box-shadow:0 4px 12px rgba(0,0,0,.15); z-index:9999; display:none; }
    .target-chip-row { display:flex; flex-wrap:wrap; gap:5px; padding:6px; border:1px solid #e2e8f0; border-radius:6px; background:#f8fafc; max-height:120px; overflow-y:auto; }
    .target-chip { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:9999px; font-size:11px; font-weight:600; cursor:pointer; user-select:none; border:1px solid #cbd5e1; background:white; color:#475569; transition:all .12s; }
    .target-chip:hover { border-color:#93c5fd; color:#2563eb; }
    .target-chip.active { background:#2563eb; color:white; border-color:#2563eb; }
    .target-chip .chip-count { font-size:9px; padding:1px 5px; background:rgba(255,255,255,.25); border-radius:9999px; }
    .target-chip:not(.active) .chip-count { background:#e2e8f0; }
    .builder-row { display:flex; flex-wrap:wrap; align-items:center; gap:6px; padding:10px; background:#f1f5f9; border:1px solid #cbd5e1; border-radius:6px; margin-top:8px; }
    .builder-row label.text-xs { font-weight:600; color:#475569; }
    .rule-group { background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:10px 12px; margin-top:10px; }
    .rule-group-header { display:flex; align-items:center; justify-content:space-between; padding-bottom:6px; border-bottom:1px solid #e2e8f0; }
    .rule-group-section { margin-top:8px; }
    .toast.show { display:block; }
    /* Section nav tabs */
    .sec-nav { display:flex; gap:6px; padding:6px; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:14px; flex-wrap:wrap; }
    .sec-tab { padding:8px 14px; border-radius:6px; font-size:13px; font-weight:600; color:#475569; text-decoration:none; transition:all .12s; }
    .sec-tab:hover { background:white; color:#1d4ed8; }
    .sec-tab.active { background:white; color:#1d4ed8; box-shadow:0 1px 2px rgba(0,0,0,.06); }
    /* Collapsible cond formatting */
    .cf-toggle { background:#f1f5f9; border:1px solid #cbd5e1; border-radius:6px; padding:10px 14px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; display:flex; align-items:center; justify-content:space-between; user-select:none; transition:all .12s; }
    .cf-toggle:hover { background:#e2e8f0; }
    .cf-chevron { transition:transform .15s ease; }
    .cf-toggle.open .cf-chevron { transform:rotate(90deg); }
  </style>

  <div class="mx-auto px-4 py-4" style="max-width:980px;" x-data="colsSettings()">

    {{-- ─── Section nav ─────────────────────────────────────────────────── --}}
    <nav class="sec-nav">
      <a href="{{ route('owner.column-settings.owner-private') }}"
         class="sec-tab {{ $sectionId === 'owner_private' ? 'active' : '' }}">📊 Page Summary</a>
      <a href="{{ route('owner.column-settings.campaigns') }}"
         class="sec-tab {{ $sectionId === 'campaigns' ? 'active' : '' }}">📈 Campaigns</a>
      <a href="{{ route('owner.column-settings.daily-summary') }}"
         class="sec-tab {{ $sectionId === 'daily_summary' ? 'active' : '' }}">📅 Daily Summary <span class="text-[9px] text-amber-700 bg-amber-50 px-1.5 py-0.5 rounded ml-1">CEO</span></a>
    </nav>

    @if(($breakevenTargetPct ?? null) !== null)
    {{-- ─── Computation Settings (only for owner_private section) ───────── --}}
    <div class="col-section" x-data="breakevenPctEditor({{ $breakevenTargetPct ?? 5 }})">
      <div class="flex items-baseline justify-between mb-1">
        <h3>🧮 Computation Settings</h3>
        <span class="text-[10px] text-slate-400">app_settings · owner_breakeven_target_pct</span>
      </div>
      <p class="note">
        Target Proj.% para sa <b>"Breakeven CPP"</b> column. Default: <code>5</code> (= 5% net margin).
      </p>
      <div class="flex items-center gap-3 mt-2">
        <label class="text-xs text-slate-600 font-semibold">Target %:</label>
        <input type="number" step="0.1" min="0" max="100"
               x-model.number="value"
               class="border border-slate-300 rounded px-3 py-1.5 text-sm w-28 text-right">
        <span class="text-xs text-slate-500">column header will say "Breakeven CPP (<span x-text="value"></span>%)"</span>
        <div class="flex-1"></div>
        <button class="save-btn" :disabled="saving" @click="save()">
          <span x-show="!saving">💾 Save target %</span>
          <span x-show="saving">Saving…</span>
        </button>
      </div>
    </div>
    @endif

    {{-- ─── Conditional Formatting (collapsible, hidden by default) ────── --}}
    <div x-data="{ cfOpen: false }" class="mb-4">
      <div class="cf-toggle" :class="{ open: cfOpen }" @click="cfOpen = !cfOpen">
        <span>{!! $cfTitle !!}</span>
        <span class="cf-chevron">▶</span>
      </div>
      <div x-show="cfOpen" x-transition class="mt-3">
        @include('owner._col_format_section', [
          'sectionTitle'      => '',
          'sectionKey'        => 'app_settings · ' . $sectionId . '_col_format',
          'tableId'           => $sectionId,
          'tableCatalog'      => $catalog[$sectionId] ?? [],
          'initialGroups'     => $colFormatGroups ?? [],
          'note'              => $cfNote,
          'allowRefValues'    => $cfAllowRef,
          'breakevenTargetPct'=> $breakevenTargetPct ?? 5,
        ])
      </div>
    </div>

    {{-- ─── Column visibility/order section ────────────────────────────── --}}
    <div class="col-section" x-data="sectionState('{{ $sectionId }}', @js($matrix), @js($nonCeoRoles))">
      <div class="flex items-baseline justify-between mb-1">
        <h3>{!! $sectionTitle !!}</h3>
        <span class="text-[10px] text-slate-400">{{ $sectionRoute }}</span>
      </div>
      <p class="note">{!! $sectionDesc !!}</p>

      <div class="col-list">
        <div class="role-header">
          <span style="width:18px;"></span>
          <span class="spacer">Column</span>
          <span class="role-col" title="CEO visibility — toggleable">CEO</span>
          @if($showRoleColumns)
            @foreach ($nonCeoRoles as $r)
              <span class="role-col" title="{{ $r }}">{{ $r === 'Marketing - OIC' ? 'MOIC' : $r }}</span>
            @endforeach
          @endif
        </div>
        <template x-for="(id, idx) in order" :key="id">
          <div class="col-item"
               draggable="true"
               @dragstart="dragStart(idx, $event)"
               @dragend="dragEnd($event)"
               @dragover.prevent="dragOver(idx, $event)"
               @dragleave="dragLeave($event)"
               @drop.prevent="drop(idx)">
            <span class="col-handle">⋮⋮</span>
            <span class="col-label" x-text="labelFor(id)"></span>
            <span class="role-cell ceo" title="CEO visibility (toggleable)">
              <input type="checkbox" :checked="isVisibleForCEO(id)"
                     @change="toggleCEOVisible(id, $event.target.checked)"
                     class="h-4 w-4">
            </span>
            @if($showRoleColumns)
              <template x-for="role in roles" :key="role">
                <span class="role-cell">
                  <input type="checkbox" :checked="isVisibleForRole(id, role)"
                         @change="toggleRoleVisible(id, role, $event.target.checked)"
                         class="h-4 w-4">
                </span>
              </template>
            @endif
          </div>
        </template>
      </div>

      <div class="flex gap-2 mt-3 items-center flex-wrap">
        <button class="save-btn" :disabled="saving" @click="save()">
          <span x-show="!saving">💾 Save</span>
          <span x-show="saving">Saving…</span>
        </button>
        <button class="reset-btn" @click="resetDefaults()">Reset to defaults</button>
        <span class="text-xs text-slate-500"
              x-text="'CEO: ' + visibleCountForCEO() + ' / ' + order.length"></span>
        @if($showRoleColumns)
          <template x-for="role in roles" :key="role">
            <span class="text-xs text-slate-500"
                  x-text="role + ': ' + visibleCountForRole(role) + ' / ' + order.length"></span>
          </template>
        @endif
      </div>
    </div>
  </div>

  <div id="colsToast" class="toast">✓ Saved</div>

  <script>
    const COL_CATALOG       = @json($catalog);
    const COL_DEFAULT_VIS   = @json($defaultVisible);
    const COL_SAVE_URL      = '{{ route('owner.column-settings.save') }}';
    const COL_CSRF          = document.querySelector('meta[name="csrf-token"]')?.content || '';

    function colsSettings() {
      return {
        showToast(msg) {
          const t = document.getElementById('colsToast');
          t.textContent = msg;
          t.classList.add('show');
          setTimeout(() => t.classList.remove('show'), 1800);
        },
      };
    }

    // Reuse same Alpine helpers from the original column_settings page.
    @include('owner._col_settings_alpine_helpers')
  </script>
</x-layout>
