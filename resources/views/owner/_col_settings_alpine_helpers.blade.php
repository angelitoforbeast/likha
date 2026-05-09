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

    function sectionState(table, matrix, roles) {
      const catalog = COL_CATALOG[table] || [];
      const idToLabel = Object.fromEntries(catalog.map(c => [c.id, c.label]));
      // matrix shape: { order, hidden (CEO's), visible_by_role: {role: [ids]} }
      // Build initial state — order already resolved server-side incl. new ids.
      const initVisibleByRole = {};
      (roles || []).forEach((r) => {
        const list = matrix?.visible_by_role?.[r] ?? [];
        initVisibleByRole[r] = new Set(Array.isArray(list) ? list : []);
      });

      return {
        table,
        roles: [...(roles || [])],
        order:  Array.isArray(matrix?.order)  && matrix.order.length  ? [...matrix.order]  : catalog.map(c => c.id),
        hidden: new Set(Array.isArray(matrix?.hidden) ? matrix.hidden : []),  // CEO's hidden list
        visibleByRole: initVisibleByRole,
        saving: false,
        dragIdx: -1,
        dragOverIdx: -1,

        labelFor(id) { return idToLabel[id] || id; },
        // CEO visibility = NOT in `hidden` set. Toggleable.
        isVisibleForCEO(id) { return !this.hidden.has(id); },
        toggleCEOVisible(id, visible) {
          if (visible) this.hidden.delete(id);
          else this.hidden.add(id);
          // Force reactivity by reassigning.
          this.hidden = new Set([...this.hidden]);
        },
        visibleCountForCEO() {
          return this.order.filter((id) => !this.hidden.has(id)).length;
        },
        isVisibleForRole(id, role) {
          return this.visibleByRole[role]?.has(id) === true;
        },
        toggleRoleVisible(id, role, visible) {
          const set = this.visibleByRole[role] || new Set();
          if (visible) set.add(id); else set.delete(id);
          // Force reactivity by reassigning.
          this.visibleByRole = { ...this.visibleByRole, [role]: new Set([...set]) };
        },
        visibleCountForRole(role) {
          const set = this.visibleByRole[role] || new Set();
          return this.order.filter((id) => set.has(id)).length;
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
          // Per-role: clear all (admin must opt-in).
          const cleared = {};
          this.roles.forEach((r) => { cleared[r] = new Set(); });
          this.visibleByRole = cleared;
        },
        async save() {
          this.saving = true;
          try {
            const fd = new FormData();
            fd.append('_token', COL_CSRF);
            fd.append('table', this.table);
            this.order.forEach(id => fd.append('order[]', id));
            [...this.hidden].forEach(id => fd.append('hidden[]', id));
            // Per-role visible lists.
            this.roles.forEach((role) => {
              const set = this.visibleByRole[role] || new Set();
              [...set].forEach((id) => fd.append(`visible_by_role[${role}][]`, id));
            });
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
