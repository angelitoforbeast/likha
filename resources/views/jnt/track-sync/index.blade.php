<x-layout>
  <x-slot name="title">J&T Track Sync</x-slot>
  <x-slot name="heading"><div class="text-xl font-bold">📡 J&T Track Sync (from_jnts status via API)</div></x-slot>

  <style>
    .ts-wrap { max-width:1050px; margin:16px auto; padding:0 16px; }
    .ts-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; margin-bottom:14px; }
    .ts-note { font-size:12px; color:#7c2d12; background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; padding:10px 12px; margin-bottom:12px; line-height:1.5; }
    .ts-row { display:flex; gap:10px; align-items:end; flex-wrap:wrap; }
    .ts-fld { display:flex; flex-direction:column; gap:3px; }
    .ts-fld span { font-size:11px; font-weight:600; color:#475569; }
    .ts-fld input { border:1px solid #d1d5db; border-radius:8px; padding:7px 9px; font-size:13px; }
    .ts-btn { border:0; border-radius:8px; padding:9px 16px; font-weight:700; font-size:13px; cursor:pointer; }
    .ts-btn.preview { background:#4f46e5; color:#fff; }
    .ts-btn.apply { background:#065f46; color:#fff; }
    .ts-btn:disabled { opacity:.5; cursor:not-allowed; }
    .ts-stat { display:grid; grid-template-columns:repeat(auto-fit,minmax(110px,1fr)); gap:8px; margin-top:12px; }
    .ts-tile { border:1px solid #e2e8f0; border-radius:8px; padding:8px 10px; text-align:center; cursor:pointer; transition:box-shadow .1s, border-color .1s; }
    .ts-tile:hover { border-color:#c7d2fe; }
    .ts-tile.sel { border-color:#4f46e5; box-shadow:0 0 0 2px rgba(79,70,229,.25) inset; }
    .ts-tile b { display:block; font-size:19px; }
    .ts-tile span { font-size:10.5px; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
    .ts-tile.upd b{color:#065f46;} .ts-tile.skip b{color:#b45309;} .ts-tile.unmap b{color:#b91c1c;} .ts-tile.fail b{color:#dc2626;}
    table.ts-tbl { width:100%; border-collapse:collapse; font-size:12px; margin-top:8px; }
    table.ts-tbl th, table.ts-tbl td { border:1px solid #eef2f7; padding:5px 8px; text-align:left; }
    table.ts-tbl th { background:#f8fafc; color:#475569; font-weight:700; }
    .ts-badge { font-size:10px; font-weight:700; padding:1px 7px; border-radius:999px; }
    .b-run{background:#dbeafe;color:#1e40af;} .b-done{background:#dcfce7;color:#166534;} .b-fail{background:#fee2e2;color:#991b1b;} .b-queued{background:#f1f5f9;color:#475569;}
    .ts-arrow{color:#94a3b8;}
    .ts-muted{color:#94a3b8;font-size:12px;}
    .ts-pill{display:inline-block;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;margin:2px;}
  </style>

  <div class="ts-wrap" x-data="trackSync()" x-init="init()">
    <div class="ts-note">
      <b>Paano ito gumagana:</b> Kinukuha ang mga <b>non-final</b> na waybill sa <b>from_jnts</b> (status NOT <b>Delivered/Returned</b> — final na yun, di gagalawin) sa napiling <b>date range</b> (submission_time), tapos tinatawagan ang J&T <b>TRACKQUERY</b> (by batches) para i-refresh ang <b>status</b> + <b>status_logs</b> (from → to) + <b>signingtime</b>.<br>
      <b>Preview (dry-run)</b> = walang isusulat — makikita mo muna ang mga magbabago at ang <b>unmapped</b> (hal. <i>Problematic</i>) bago mag-<b>Apply</b>.
    </div>

    <div class="ts-card">
      <div class="ts-row">
        <label class="ts-fld"><span>From (submission date)</span><input type="date" x-model="dateFrom"></label>
        <label class="ts-fld"><span>To</span><input type="date" x-model="dateTo"></label>
        <button class="ts-btn preview" :disabled="busy" @click="start(true)">🔍 Preview (dry-run)</button>
        <button class="ts-btn apply" :disabled="busy" @click="applyConfirm()">✍️ Apply (isulat na)</button>
        <span class="ts-muted" x-show="busy" x-text="phase"></span>
      </div>
    </div>

    <!-- Progress + results -->
    <div class="ts-card" x-show="run">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span class="ts-badge" :class="{'b-run':run?.status==='running','b-done':run?.status==='done','b-fail':run?.status==='failed','b-queued':run?.status==='queued'}" x-text="(run?.status||'').toUpperCase()"></span>
        <b x-text="run?.dry_run ? 'PREVIEW (dry-run)' : 'APPLY'"></b>
        <span class="ts-muted" x-text="'Run #'+(run?.id||'')+' · '+(run?.date_from||'')+' → '+(run?.date_to||'')"></span>
        <span class="ts-muted" x-show="run && run.total>0" x-text="'· '+(run?.processed||0)+' / '+(run?.total||0)"></span>
      </div>
      <div x-show="run && run.status==='failed'" style="color:#b91c1c;font-size:12px;margin-top:6px;" x-text="'Error: '+(run?.last_error||'')"></div>

      <div class="ts-stat">
        <div class="ts-tile" :class="activeCat===null?'sel':''" @click="activeCat=null"><b x-text="run?.total||0"></b><span>Total</span></div>
        <div class="ts-tile upd" :class="activeCat==='updated'?'sel':''" @click="activeCat='updated'"><b x-text="run?.updated||0"></b><span x-text="run?.dry_run ? 'Mababago' : 'Updated'"></span></div>
        <div class="ts-tile" :class="activeCat==='unchanged'?'sel':''" @click="activeCat='unchanged'"><b x-text="run?.unchanged||0"></b><span>Unchanged</span></div>
        <div class="ts-tile skip" :class="activeCat==='skipped'?'sel':''" @click="activeCat='skipped'"><b x-text="run?.skipped||0"></b><span>Skipped</span></div>
        <div class="ts-tile unmap" :class="activeCat==='unmapped'?'sel':''" @click="activeCat='unmapped'"><b x-text="run?.unmapped||0"></b><span>Unmapped</span></div>
        <div class="ts-tile fail" :class="activeCat==='failed'?'sel':''" @click="activeCat='failed'"><b x-text="run?.failed||0"></b><span>Failed</span></div>
      </div>
      <div class="ts-muted" style="margin-top:6px;font-size:11px;">I-click ang tile para makita ang listahan ng waybills.</div>

      <!-- Unmapped scantypes -->
      <template x-if="unmappedTypes().length">
        <div style="margin-top:12px;">
          <div style="font-size:12px;font-weight:700;color:#b91c1c;">⚠ Unmapped scantypes (kailangan i-decide ang mapping — hindi isinulat):</div>
          <div style="margin-top:4px;">
            <template x-for="u in unmappedTypes()" :key="u.type">
              <span class="ts-pill" x-text="u.type+' ('+u.count+')'"></span>
            </template>
          </div>
        </div>
      </template>

      <!-- Category list (galing sa clicked tile) -->
      <template x-if="activeCat && activeCat!=='failed'">
        <div style="margin-top:12px;">
          <div style="font-size:12px;font-weight:700;color:#334155;">
            <span style="text-transform:capitalize;" x-text="activeCat"></span>:
            <span x-text="catRows().length"></span> ipinapakita<span x-show="catCount()>catRows().length" x-text="' (sa kabuuang '+catCount()+' — unang '+catRows().length+')'"></span>
          </div>
          <div style="max-height:340px;overflow:auto;margin-top:4px;">
            <table class="ts-tbl">
              <thead><tr><th>Waybill</th><th>Detalye</th><th>Scantype</th></tr></thead>
              <tbody>
                <template x-for="r in catRows()" :key="r.waybill">
                  <tr>
                    <td x-text="r.waybill"></td>
                    <td>
                      <template x-if="r.from!==undefined"><span><span x-text="r.from||'—'"></span> <span class="ts-arrow">→</span> <b x-text="r.to"></b></span></template>
                      <template x-if="r.from===undefined"><span x-text="r.status||'—'"></span></template>
                    </td>
                    <td x-text="r.scantype||''"></td>
                  </tr>
                </template>
                <template x-if="!catRows().length"><tr><td colspan="3" class="ts-muted" style="text-align:center;">Walang laman sa category na ito.</td></tr></template>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </div>

    <!-- History -->
    <div class="ts-card">
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <b style="font-size:13px;">Recent runs</b>
        <button class="ts-btn" style="background:#f1f5f9;color:#475569;padding:5px 10px;" @click="loadHistory()">↻ Refresh</button>
      </div>
      <div style="max-height:280px;overflow:auto;margin-top:6px;">
        <table class="ts-tbl">
          <thead><tr><th>#</th><th>Range</th><th>Mode</th><th>Status</th><th>Upd</th><th>Skip</th><th>Unmap</th><th>Fail</th><th>When</th></tr></thead>
          <tbody>
            <template x-for="h in history" :key="h.id">
              <tr>
                <td x-text="h.id"></td>
                <td x-text="h.date_from+' → '+h.date_to"></td>
                <td x-text="h.dry_run ? 'preview' : 'apply'"></td>
                <td><span class="ts-badge" :class="{'b-run':h.status==='running','b-done':h.status==='done','b-fail':h.status==='failed','b-queued':h.status==='queued'}" x-text="h.status"></span></td>
                <td x-text="h.updated"></td><td x-text="h.skipped"></td><td x-text="h.unmapped"></td><td x-text="h.failed"></td>
                <td class="ts-muted" x-text="(h.created_at||'').substring(0,16).replace('T',' ')"></td>
              </tr>
            </template>
            <template x-if="!history.length"><tr><td colspan="9" class="ts-muted" style="text-align:center;">Wala pang run.</td></tr></template>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <script>
    function trackSync(){
      return {
        dateFrom: @json($defaultFrom),
        dateTo:   @json($defaultTo),
        run: null,
        busy: false,
        phase: '',
        _timer: null,
        history: [],
        activeCat: null, // null=Total (walang list) | updated|unchanged|skipped|unmapped|failed

        init(){ this.loadHistory(); },

        // Listahan ng waybills sa napiling category (galing result_sample).
        catRows(){
          if (!this.activeCat || !this.run || !this.run.result_sample) return [];
          const rs = this.run.result_sample;
          return Array.isArray(rs[this.activeCat]) ? rs[this.activeCat] : [];
        },
        // Kabuuang bilang sa category (para sa "X of Y" kung na-cap ang list).
        catCount(){
          if (!this.activeCat || !this.run) return 0;
          return Number(this.run[this.activeCat] || 0);
        },
        unmappedTypes(){
          const m = (this.run && this.run.result_sample && this.run.result_sample.unmapped_scantypes) ? this.run.result_sample.unmapped_scantypes : {};
          return Object.keys(m).map(k => ({type:k, count:m[k]})).sort((a,b)=>b.count-a.count);
        },

        applyConfirm(){
          if (confirm('APPLY na — isusulat ang status/status_logs sa from_jnts para sa range na ito. Tuloy?')) this.start(false);
        },

        async start(dryRun){
          if (this.busy) return;
          this.busy = true; this.run = null;
          this.phase = dryRun ? 'Nagpe-preview…' : 'Nag-a-apply…';
          try{
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const fd = new FormData();
            fd.append('date_from', this.dateFrom); fd.append('date_to', this.dateTo);
            fd.append('dry_run', dryRun ? '1' : '0');
            const res = await fetch('{{ route('jnt.track-sync.start') }}', {method:'POST', headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf}, body:fd});
            const j = await res.json();
            if (!j.ok){ alert(j.message||'Failed to start'); this.busy=false; return; }
            this.poll(j.run_id);
          }catch(e){ alert('Error: '+e.message); this.busy=false; }
        },

        poll(runId){
          clearInterval(this._timer);
          const tick = async () => {
            try{
              const res = await fetch('{{ url('/jnt/track-sync/status') }}/'+runId, {headers:{'Accept':'application/json'}});
              const j = await res.json();
              if (j.ok){
                this.run = j.run;
                if (j.run.status === 'done' || j.run.status === 'failed'){
                  clearInterval(this._timer); this.busy = false; this.phase=''; this.loadHistory();
                }
              }
            }catch(e){ /* keep polling */ }
          };
          tick();
          this._timer = setInterval(tick, 1500);
        },

        async loadHistory(){
          try{
            const res = await fetch('{{ route('jnt.track-sync.history') }}', {headers:{'Accept':'application/json'}});
            const j = await res.json();
            if (j.ok) this.history = j.runs || [];
          }catch(e){}
        },
      };
    }
  </script>
</x-layout>
