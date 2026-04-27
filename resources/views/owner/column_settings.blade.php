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
    .toast.show { display:block; }
  </style>

  <div class="mx-auto px-4 py-4" style="max-width:920px;" x-data="colsSettings()">

    <p class="text-xs text-slate-500 mb-3">
      Drag any row to reorder · Toggle the checkbox to hide/show · Click <b>Save</b> per section to persist globally (affects all viewers).
    </p>

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
