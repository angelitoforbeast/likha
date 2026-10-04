      // claude-modal-js-start
      // ── Claude Action / Reason modal (CEO lang) — gaya ng Action note modal ──
      claudeModal: {open:false, saving:false, error:null, saved:null, page_key:'', page_name:'', ts_date:'', action:'', reason:'', source:null, at:null, focus:'action', _row:null, x:140, y:120, _dragging:false, _dx:0, _dy:0},
      openClaudeModal(row, field){
        const w = 460;
        const cx = Math.max(20, Math.round((window.innerWidth  - w) / 2));
        const cy = Math.max(20, Math.round((window.innerHeight - 360) / 2));
        this.claudeModal = {
          open:true, saving:false, error:null, saved:null,
          page_key:   row.page_key,
          page_name:  row.page_name,
          ts_date:    this.endDate,
          action:     row.claude_action || '',
          reason:     row.claude_reason || '',
          source:     row.claude_source || null,
          at:         row.claude_at || null,
          focus:      field === 'reason' ? 'reason' : 'action',
          _row:       row,
          x: cx, y: cy, _dragging:false, _dx:0, _dy:0,
        };
      },
      startClaudeDrag(e){
        const m = this.claudeModal;
        m._dragging = true;
        m._dx = e.clientX - m.x;
        m._dy = e.clientY - m.y;
        const move = (ev) => {
          if (!m._dragging) return;
          m.x = Math.max(0, ev.clientX - m._dx);
          m.y = Math.max(0, ev.clientY - m._dy);
        };
        const up = () => {
          m._dragging = false;
          window.removeEventListener('mousemove', move);
          window.removeEventListener('mouseup', up);
        };
        window.addEventListener('mousemove', move);
        window.addEventListener('mouseup', up);
      },
      async saveClaudeNote(){
        const m = this.claudeModal;
        m.saving = true; m.error = null; m.saved = null;
        try {
          const r = await fetch('{{ route('owner.private.claude-action.save') }}', {
            method:'POST',
            headers:{
              'Content-Type':'application/json',
              'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
              'Accept':'application/json',
            },
            body: JSON.stringify({
              page_key: m.page_key,
              ts_date:  m.ts_date,
              action:   m.action || '',
              reason:   m.reason || '',
            }),
          });
          const j = await r.json();
          if (!r.ok || !j.ok) throw new Error(j.message || ('HTTP '+r.status));
          // I-reflect sa row in place (walang full reload).
          if (m._row) {
            m._row.claude_action = j.claude_action || null;
            m._row.claude_reason = j.claude_reason || null;
            m._row.claude_source = j.claude_source || null;
            m._row.claude_at     = j.claude_at || null;
          }
          m.saved = '✓ Saved';
          setTimeout(() => { this.claudeModal.open = false; }, 500);
        } catch(e) {
          m.error = e.message || 'Save failed';
        } finally {
          m.saving = false;
        }
      },
      // claude-modal-js-end
