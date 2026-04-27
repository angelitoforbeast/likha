<x-layout>
  <x-slot name="title">Column Settings</x-slot>
  <x-slot name="heading">Column Settings</x-slot>

  <style>
    .col-section { background:white; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:16px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .col-section h3 { font-size:14px; font-weight:700; color:#0f172a; margin-bottom:6px; }
    .col-section p.note { font-size:11px; color:#64748b; margin-bottom:12px; }
    .col-list { border:1px solid #e2e8f0; border-radius:6px; max-height:420px; overflow-y:auto; background:#f8fafc; }
    .col-item { display:flex; align-items:center; gap:10px; padding:8px 12px; background:white; border-bottom:1px solid #e2e8f0; cursor:grab; user-select:none; }
    .col-item:last-child { border-bottom:none; }
    .col-item:hover { background:#f1f5f9; }
    .col-item.dragging { opacity:0.4; }
    .col-item.drag-over { border-top:2px solid #2563eb; }
    .col-handle { color:#94a3b8; font-size:14px; cursor:grab; }
    .col-handle:active { cursor:grabbing; }
    .col-label { flex:1; font-size:13px; color:#1e293b; font-weight:500; }
    .col-id { font-family:ui-monospace,monospace; font-size:10px; color:#94a3b8; }
    .save-btn { background:#2563eb; color:white; padding:8px 18px; border-radius:6px; font-weight:600; font-size:13px; cursor:pointer; border:none; }
    .save-btn:hover { background:#1d4ed8; }
    .save-btn:disabled { background:#94a3b8; cursor:not-allowed; }
    .reset-btn { background:white; color:#374151; padding:8px 14px; border-radius:6px; font-weight:600; font-size:12px; cursor:pointer; border:1px solid #d1d5db; }
    .reset-btn:hover { background:#f3f4f6; }
    .toast { position:fixed; bottom:20px; right:20px; background:#16a34a; color:white; padding:10px 18px; border-radius:6px; font-weight:600; font-size:13px; box-shadow:0 4px 12px rgba(0,0,0,.15); z-index:9999; display:none; }
    /* Multi-select chip pills used by the bulk rule builder. */
    .target-chip-row { display:flex; flex-wrap:wrap; gap:5px; padding:6px; border:1px solid #e2e8f0; border-radius:6px; background:#f8fafc; max-height:120px; overflow-y:auto; }
    .target-chip { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:9999px; font-size:11px; font-weight:600; cursor:pointer; user-select:none; border:1px solid #cbd5e1; background:white; color:#475569; transition:all .12s; }
    .target-chip:hover { border-color:#93c5fd; color:#2563eb; }
    .target-chip.active { background:#2563eb; color:white; border-color:#2563eb; }
    .target-chip .chip-count { font-size:9px; padding:1px 5px; background:rgba(255,255,255,.25); border-radius:9999px; }
    .target-chip:not(.active) .chip-count { background:#e2e8f0; }
    .builder-row { display:flex; flex-wrap:wrap; align-items:center; gap:6px; padding:10px; background:#f1f5f9; border:1px solid #cbd5e1; border-radius:6px; margin-top:8px; }
    .builder-row label.text-xs { font-weight:600; color:#475569; }
    /* Rule group cards (shared rules across N columns). */
    .rule-group { background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:10px 12px; margin-top:10px; }
    .rule-group-header { display:flex; align-items:center; justify-content:space-between; padding-bottom:6px; border-bottom:1px solid #e2e8f0; }
    .rule-group-section { margin-top:8px; }
    .toast.show { display:block; }
  </style>

  <div class="mx-auto px-4 py-4" style="max-width:980px;" x-data="colsSettings()">

    <p class="text-xs text-slate-500 mb-3">
      Drag any row to reorder · Toggle the checkbox to hide/show · Click <b>Save</b> per section to persist globally (affects all viewers).
    </p>

    {{-- ─── Computation Settings ───────────────────────────────────────── --}}
    <div class="col-section" x-data="breakevenPctEditor({{ $breakevenTargetPct ?? 5 }})">
      <div class="flex items-baseline justify-between mb-1">
        <h3>🧮 Computation Settings</h3>
        <span class="text-[10px] text-slate-400">app_settings · owner_breakeven_target_pct</span>
      </div>
      <p class="note">
        Target Proj.% para sa <b>"Breakeven CPP"</b> column. Default: <code>5</code> (= 5% net margin).
        Applied formula:<br>
        <code>breakeven_cpp = (proceed/orders) × [(1 − rts) × (0.9832 × price − item_value) − 37] − (target/100) × price</code>
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

    {{-- ─── Conditional Formatting: Page Summary Table ─────────────────── --}}
    @include('owner._col_format_section', [
      'sectionTitle'  => '🎨 Conditional Formatting · Page Summary Table',
      'sectionKey'    => 'app_settings · owner_private_col_format',
      'tableId'       => 'owner_private',
      'tableCatalog'  => $catalog['owner_private'] ?? [],
      'initialGroups' => $colFormatGroups ?? [],
      'note'          => 'Applies sa <code>/owner/private</code> per-row cells. Rules\' values can be a literal number or a <b>reference</b> to another Page Summary column (rare here — useful mostly for /ads_manager/campaigns rules).',
      'allowRefValues'=> true,
    ])

    {{-- ─── Conditional Formatting: Campaigns / Ad Sets / Ads Table ────── --}}
    @include('owner._col_format_section', [
      'sectionTitle'  => '🎨 Conditional Formatting · Campaigns / Ad Sets / Ads',
      'sectionKey'    => 'app_settings · campaigns_col_format',
      'tableId'       => 'campaigns',
      'tableCatalog'  => $catalog['campaigns'] ?? [],
      'initialGroups' => $campaignsColFormatGroups ?? [],
      'note'          => 'Applies sa Campaigns table sa <code>/ads_manager/campaigns</code> at sa inline expand sa <code>/owner/private</code>. <b>Cross-table reference</b> sa Page Summary column (e.g. <code>cpp ≥ breakeven_cpp</code>) gumagana lang sa /owner/private expand panel — sa standalone view, ref-rules ay literal-only fallback (skipped).',
      'allowRefValues'=> true,
    ])

    {{-- ─── Section 1: /owner/private ──────────────────────────────────────── --}}
    <div class="col-section" x-data="sectionState('owner_private', @js($savedOwnerPrivate))">
      <div class="flex items-baseline justify-between mb-1">
        <h3>📊 /owner/private — Page Summary Table</h3>
        <span class="text-[10px] text-slate-400">app_settings · owner_private_cols</span>
      </div>
      <p class="note">
        Mga columns na lumalabas sa <code>/owner/private</code> per-page summary row. Page + Item + Actions
        columns ay laging visible (hindi nila kasama dito).
      </p>

      <div class="col-list">
        <template x-for="(id, idx) in order" :key="id">
          <div class="col-item"
               draggable="true"
               @dragstart="dragStart(idx, $event)"
               @dragend="dragEnd($event)"
               @dragover.prevent="dragOver(idx, $event)"
               @dragleave="dragLeave($event)"
               @drop.prevent="drop(idx)">
            <span class="col-handle">⋮⋮</span>
            <input type="checkbox" :checked="!isHidden(id)"
                   @change="toggleVisible(id, $event.target.checked)"
                   class="h-4 w-4 cursor-pointer">
            <span class="col-label" x-text="labelFor(id)"></span>
            <span class="col-id" x-text="id"></span>
          </div>
        </template>
      </div>

      <div class="flex gap-2 mt-3 items-center">
        <button class="save-btn" :disabled="saving" @click="save()">
          <span x-show="!saving">💾 Save</span>
          <span x-show="saving">Saving…</span>
        </button>
        <button class="reset-btn" @click="resetDefaults()">Reset to defaults</button>
        <span class="text-xs text-slate-500 ml-2"
              x-text="'Visible: ' + visibleCount() + ' / ' + order.length"></span>
      </div>
    </div>

    {{-- ─── Section 2: /ads_manager/campaigns + expand panel ──────────────── --}}
    <div class="col-section" x-data="sectionState('campaigns', @js($savedCampaigns))">
      <div class="flex items-baseline justify-between mb-1">
        <h3>📈 Campaigns / Ad Sets / Ads Table</h3>
        <span class="text-[10px] text-slate-400">app_settings · campaigns_cols</span>
      </div>
      <p class="note">
        Same columns ginagamit sa <code>/ads_manager/campaigns</code> at sa inline expand panel
        sa <code>/owner/private</code>. Bawat tab (Campaigns / Adsets / Ads) gumagamit ng same column set.
        Pag pinipili mo less columns dito, mas kasya sa viewport — walang horizontal scroll.
      </p>

      <div class="col-list">
        <template x-for="(id, idx) in order" :key="id">
          <div class="col-item"
               draggable="true"
               @dragstart="dragStart(idx, $event)"
               @dragend="dragEnd($event)"
               @dragover.prevent="dragOver(idx, $event)"
               @dragleave="dragLeave($event)"
               @drop.prevent="drop(idx)">
            <span class="col-handle">⋮⋮</span>
            <input type="checkbox" :checked="!isHidden(id)"
                   @change="toggleVisible(id, $event.target.checked)"
                   class="h-4 w-4 cursor-pointer">
            <span class="col-label" x-text="labelFor(id)"></span>
            <span class="col-id" x-text="id"></span>
          </div>
        </template>
      </div>

      <div class="flex gap-2 mt-3 items-center">
        <button class="save-btn" :disabled="saving" @click="save()">
          <span x-show="!saving">💾 Save</span>
          <span x-show="saving">Saving…</span>
        </button>
        <button class="reset-btn" @click="resetDefaults()">Reset to defaults</button>
        <span class="text-xs text-slate-500 ml-2"
              x-text="'Visible: ' + visibleCount() + ' / ' + order.length"></span>
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

    function breakevenPctEditor(initial) {
      return {
        value: Number(initial) || 5,
        saving: false,
        async save() {
          this.saving = true;
          try {
            const fd = new FormData();
            fd.append('_token', COL_CSRF);
            fd.append('value', this.value);
            const r = await fetch('{{ route('owner.column-settings.breakeven-pct') }}', { method: 'POST', body: fd });
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'Save failed');
            const t = document.getElementById('colsToast');
            t.textContent = '✓ Target % saved';
            t.classList.add('show');
            setTimeout(() => t.classList.remove('show'), 1800);
          } catch (e) {
            alert('Save failed: ' + e.message);
          } finally { this.saving = false; }
        },
      };
    }

    // Rule-group based editor. Table-aware (owner_private OR campaigns).
    // State shape:
    //   groups: [ { cols: [...], rules: [ {op,value,bg,bold,label}, ... ] } ]
    // A rule's `value` is either a number (literal) OR
    //   { type:'ref', table:'owner_private', col:'<col_id>' } (cross-ref).
    function colFormatEditor(tableId, tableCatalog, ownerPrivateCatalog, initialGroups, breakevenPct) {
      // Inject the configured target % into breakeven_cpp's label so users see
      // the same "Breakeven CPP (5%)" form as the actual column header in the
      // table — minimizes confusion when picking a Ref target.
      const enrichedOwnerCatalog = (ownerPrivateCatalog || []).map(c =>
        c.id === 'breakeven_cpp'
          ? Object.assign({}, c, { label: c.label + ' (' + (breakevenPct ?? 5) + '%)' })
          : c
      );
      const ID_TO_LABEL = Object.fromEntries((tableCatalog || []).map(c => [c.id, c.label]));
      const OP_LABEL_TO_REF_LABEL = Object.fromEntries(enrichedOwnerCatalog.map(c => [c.id, c.label]));
      const cleanInitial = Array.isArray(initialGroups) ? JSON.parse(JSON.stringify(initialGroups)) : [];
      return {
        tableId,
        tableCatalog: tableCatalog || [],
        ownerPrivateCatalog: enrichedOwnerCatalog,
        groups: cleanInitial,
        saving: false,
        dragSrc: null,   // { gIdx, rIdx }

        labelFor(id){ return ID_TO_LABEL[id] || id; },

        addGroup(){ this.groups.push({ cols: [], rules: [] }); },
        removeGroup(gIdx){ this.groups.splice(gIdx, 1); },
        toggleGroupCol(gIdx, colId){
          const g = this.groups[gIdx];
          if (!g) return;
          const i = g.cols.indexOf(colId);
          if (i >= 0) g.cols.splice(i, 1);
          else g.cols.push(colId);
        },
        addRuleToGroup(gIdx){
          const g = this.groups[gIdx];
          if (!g) return;
          g.rules.push({ op: '>=', value: 0, bg: '#fee2e2', bold: false, label: '' });
        },
        removeRule(gIdx, rIdx){
          const g = this.groups[gIdx];
          if (!g) return;
          g.rules.splice(rIdx, 1);
        },
        ruleDragStart(gIdx, rIdx, ev){
          this.dragSrc = { gIdx, rIdx };
          ev.dataTransfer.effectAllowed = 'move';
        },
        ruleDrop(gIdx, rIdx){
          if (!this.dragSrc) return;
          if (this.dragSrc.gIdx !== gIdx) { this.dragSrc = null; return; }
          if (this.dragSrc.rIdx === rIdx) { this.dragSrc = null; return; }
          const g = this.groups[gIdx];
          const moved = g.rules.splice(this.dragSrc.rIdx, 1)[0];
          g.rules.splice(rIdx, 0, moved);
          this.dragSrc = null;
        },

        // ── Rule value (literal vs ref) helpers ─────────────────────────
        ruleValueKind(r){
          if (r && r.value && typeof r.value === 'object' && r.value.type === 'ref') return 'ref';
          return 'literal';
        },
        setRuleValueKind(r, kind){
          if (kind === 'ref') {
            // Switching to ref — pick first owner_private col as default.
            const firstCol = (this.ownerPrivateCatalog[0] || {}).id || 'breakeven_cpp';
            r.value = { type: 'ref', table: 'owner_private', col: firstCol };
          } else {
            r.value = 0;
          }
        },
        rulePreviewText(r){
          if (this.ruleValueKind(r) === 'ref') {
            const lbl = OP_LABEL_TO_REF_LABEL[r.value.col] || r.value.col;
            return '→ ' + lbl;
          }
          return (r.value ?? 0);
        },

        previewTextColor(hex) {
          const m = /^#([0-9a-f]{6})$/i.exec(hex || '');
          if (!m) return '#111827';
          const r = parseInt(m[1].slice(0,2), 16);
          const g = parseInt(m[1].slice(2,4), 16);
          const b = parseInt(m[1].slice(4,6), 16);
          const yiq = (r*299 + g*587 + b*114) / 1000;
          return yiq >= 150 ? '#111827' : '#ffffff';
        },
        async save() {
          this.saving = true;
          try {
            const clean = this.groups
              .filter(g => Array.isArray(g.cols) && g.cols.length > 0
                        && Array.isArray(g.rules) && g.rules.length > 0)
              .map(g => ({
                cols: [...new Set(g.cols)],
                rules: g.rules.map(r => {
                  let value;
                  if (r && r.value && typeof r.value === 'object' && r.value.type === 'ref') {
                    value = { type: 'ref', table: 'owner_private', col: String(r.value.col || '') };
                  } else {
                    value = Number(r.value) || 0;
                  }
                  return {
                    op:    r.op,
                    value: value,
                    bg:    String(r.bg || '#fee2e2'),
                    color: r.color ? String(r.color) : '',
                    bold:  !!r.bold,
                    label: String(r.label || ''),
                  };
                }),
              }));
            const fd = new FormData();
            fd.append('_token', COL_CSRF);
            fd.append('table',  this.tableId);
            fd.append('groups', JSON.stringify(clean));
            const r = await fetch('{{ route('owner.column-settings.col-format') }}', { method: 'POST', body: fd });
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'Save failed');
            this.groups = Array.isArray(j.groups) ? JSON.parse(JSON.stringify(j.groups)) : [];
            const t = document.getElementById('colsToast');
            t.textContent = '✓ Formatting saved · ' + this.tableId;
            t.classList.add('show');
            setTimeout(() => t.classList.remove('show'), 1800);
          } catch (e) {
            alert('Save failed: ' + e.message);
          } finally { this.saving = false; }
        },
      };
    }

    function sectionState(table, saved) {
      const catalog = COL_CATALOG[table] || [];
      const idToLabel = Object.fromEntries(catalog.map(c => [c.id, c.label]));
      // Build initial state from saved config (already resolved server-side
      // so it includes new catalog ids appended at the end).
      return {
        table,
        order:  Array.isArray(saved.order)  && saved.order.length  ? [...saved.order]  : catalog.map(c => c.id),
        hidden: new Set(Array.isArray(saved.hidden) ? saved.hidden : []),
        saving: false,
        dragIdx: -1,
        dragOverIdx: -1,

        labelFor(id) { return idToLabel[id] || id; },
        isHidden(id) { return this.hidden.has(id); },
        toggleVisible(id, visible) {
          if (visible) this.hidden.delete(id);
          else this.hidden.add(id);
          // Force reactivity by reassigning the Set.
          this.hidden = new Set([...this.hidden]);
        },
        visibleCount() {
          return this.order.filter(id => !this.hidden.has(id)).length;
        },
        dragStart(idx, ev) {
          this.dragIdx = idx;
          ev.dataTransfer.effectAllowed = 'move';
          ev.target.classList.add('dragging');
        },
        dragEnd(ev) {
          ev.target.classList.remove('dragging');
          document.querySelectorAll('.col-item.drag-over').forEach(el => el.classList.remove('drag-over'));
          this.dragIdx = -1;
          this.dragOverIdx = -1;
        },
        dragOver(idx, ev) {
          ev.dataTransfer.dropEffect = 'move';
          if (this.dragOverIdx !== idx) {
            document.querySelectorAll('.col-item.drag-over').forEach(el => el.classList.remove('drag-over'));
            ev.currentTarget.classList.add('drag-over');
            this.dragOverIdx = idx;
          }
        },
        dragLeave(ev) {
          ev.currentTarget.classList.remove('drag-over');
        },
        drop(targetIdx) {
          if (this.dragIdx < 0 || this.dragIdx === targetIdx) return;
          const moved = this.order.splice(this.dragIdx, 1)[0];
          this.order.splice(targetIdx, 0, moved);
          this.dragIdx = -1;
          document.querySelectorAll('.col-item.drag-over').forEach(el => el.classList.remove('drag-over'));
        },
        resetDefaults() {
          if (!confirm('Reset to defaults? Hindi pa nase-save until you click Save.')) return;
          this.order  = catalog.map(c => c.id);
          const visible = new Set(COL_DEFAULT_VIS[table] || []);
          this.hidden = new Set(this.order.filter(id => !visible.has(id)));
        },
        async save() {
          this.saving = true;
          try {
            const fd = new FormData();
            fd.append('_token', COL_CSRF);
            fd.append('table', this.table);
            this.order.forEach(id => fd.append('order[]', id));
            [...this.hidden].forEach(id => fd.append('hidden[]', id));
            const r = await fetch(COL_SAVE_URL, { method: 'POST', body: fd });
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'Save failed');
            const t = document.getElementById('colsToast');
            t.textContent = '✓ Saved · ' + this.table;
            t.classList.add('show');
            setTimeout(() => t.classList.remove('show'), 2000);
          } catch (e) {
            alert('Save failed: ' + e.message);
          } finally {
            this.saving = false;
          }
        },
      };
    }
  </script>
</x-layout>
