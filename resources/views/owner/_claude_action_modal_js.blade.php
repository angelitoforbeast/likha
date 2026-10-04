@php
  // $note = 'claude' | 'ceo', $noteLabel = 'Claude' | 'CEO' (hardcoded lang, hindi galing sa user)
  $m = $note.'Modal';
  $N = ucfirst($note);
  $saveRoute = $note === 'ceo' ? route('owner.private.ceo-action.save') : route('owner.private.claude-action.save');
@endphp
      // {{ $note }}-modal-js-start
      // ── {{ $noteLabel }} Action / Reason modal (CEO lang) — gaya ng Action note modal ──
      {{ $m }}: {open:false, saving:false, error:null, saved:null, page_key:'', page_name:'', ts_date:'', action:'', reason:'', source:null, at:null, focus:'action', _row:null, x:140, y:120, _dragging:false, _dx:0, _dy:0},
      open{{ $N }}Modal(row, field){
        const w = 460;
        const cx = Math.max(20, Math.round((window.innerWidth  - w) / 2));
        const cy = Math.max(20, Math.round((window.innerHeight - 360) / 2));
        this.{{ $m }} = {
          open:true, saving:false, error:null, saved:null,
          page_key:   row.page_key,
          page_name:  row.page_name,
          ts_date:    this.endDate,
          action:     row.{{ $note }}_action || '',
          reason:     row.{{ $note }}_reason || '',
          source:     row.{{ $note }}_source || null,
          at:         row.{{ $note }}_at || null,
          focus:      field === 'reason' ? 'reason' : 'action',
          _row:       row,
          x: cx, y: cy, _dragging:false, _dx:0, _dy:0,
        };
      },
      start{{ $N }}Drag(e){
        const m = this.{{ $m }};
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
      async save{{ $N }}Note(){
        const m = this.{{ $m }};
        m.saving = true; m.error = null; m.saved = null;
        try {
          const r = await fetch('{{ $saveRoute }}', {
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
            m._row.{{ $note }}_action = j.{{ $note }}_action || null;
            m._row.{{ $note }}_reason = j.{{ $note }}_reason || null;
            m._row.{{ $note }}_source = j.{{ $note }}_source || null;
            m._row.{{ $note }}_at     = j.{{ $note }}_at || null;
          }
          m.saved = '✓ Saved';
          setTimeout(() => { this.{{ $m }}.open = false; }, 500);
        } catch(e) {
          m.error = e.message || 'Save failed';
        } finally {
          m.saving = false;
        }
      },
      // {{ $note }}-modal-js-end
