<x-layout>
  <x-slot name="title">Prompt Generator — Settings</x-slot>
  <x-slot name="heading"><div class="text-xl font-bold">⚙️ Prompt Generator — Settings</div></x-slot>

  <style>
    .pgs-wrap { padding:20px; max-width:920px; margin:0 auto; }
    .pgs-card { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:20px; margin-bottom:16px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    .pgs-topbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px; flex-wrap:wrap; }
    .pgs-link { color:#4f46e5; font-weight:600; text-decoration:none; font-size:13px; }
    .pgs-link:hover { text-decoration:underline; }
    .pgs-field { display:block; margin-bottom:14px; }
    .pgs-field > span { display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px; }
    .pgs-field textarea, .pgs-field input { width:100%; border:1px solid #d1d5db; border-radius:8px; padding:9px 11px; font-size:13px; font-family:inherit; line-height:1.5; }
    .pgs-field textarea:focus, .pgs-field input:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 2px rgba(99,102,241,.18); }
    .pgs-btn { border:0; border-radius:8px; padding:9px 16px; font-weight:600; font-size:13px; cursor:pointer; }
    .pgs-btn.primary { background:#4f46e5; color:#fff; } .pgs-btn.primary:hover { background:#4338ca; }
    .pgs-btn.ghost { background:#fff; border:1px solid #e2e8f0; color:#64748b; }
    .pgs-sec-title { font-size:14px; font-weight:800; color:#0f172a; margin:2px 0 10px; }
    .pgs-help { font-size:12px; color:#94a3b8; margin-bottom:12px; line-height:1.45; }
    .pgs-ref pre { white-space:pre-wrap; word-break:break-word; font-size:11.5px; background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; padding:10px; max-height:240px; overflow:auto; color:#334155; margin:6px 0 0; }
  </style>

  <div class="pgs-wrap">
    <div class="pgs-topbar">
      <div class="pgs-help" style="margin:0;">Protected defaults — <strong>hindi hinahawakan ng Analyze Image</strong>. Ito ang ginagamit na default sa prompt at sa Copy for Sheet. Naka-save sa database; may Reset to Default.</div>
      <a href="{{ route('prompt.generator.index') }}" class="pgs-link">← Back to Generator</a>
    </div>

    <div class="pgs-card">
      <div class="pgs-sec-title">Policies &amp; Delivery</div>
      <div id="policyFields"></div>

      <div class="pgs-sec-title" style="margin-top:16px;">Bot Flow — Loops (for Sheet)</div>
      <div class="pgs-help">LOOP 1 = order form, LOOP 2 = order confirmation. Ginagamit sa Copy for Sheet.</div>
      <div id="loopFields"></div>

      <div style="display:flex; align-items:center; gap:10px; margin-top:12px; flex-wrap:wrap;">
        <button class="pgs-btn primary" id="pgsSave" type="button">💾 Save Settings</button>
        <button class="pgs-btn ghost" id="pgsReset" type="button">↩︎ Reset to Default</button>
        <span id="pgsStatus" style="font-size:12px; color:#94a3b8;"></span>
      </div>
    </div>

    <div class="pgs-card pgs-ref">
      <div class="pgs-sec-title">📋 Generation Prompts &amp; Inputs (reference)</div>
      <div class="pgs-help">Mga system prompt + inputs na ginagamit para mag-generate. Read-only muna.</div>
      <div id="pgsRef"></div>
    </div>
  </div>

  <script>
    window.PGS = {
      csrf:      '{{ csrf_token() }}',
      saveUrl:   '{{ route('prompt.generator.settings.save') }}',
      resetUrl:  '{{ route('prompt.generator.settings.reset') }}',
      settings:  @json($settings ?? []),
      defaults:  @json($defaults ?? []),
      promptRef: @json($promptRef ?? []),
    };
  </script>
  <script>
  (function(){
    const $=id=>document.getElementById(id);
    const POLICY=[
      ['WARRANTY_POLICY','Warranty / Replacement Policy',2],
      ['COVERAGE_AREA','Coverage Area',2],
      ['DELIVERY_TIME','Delivery Time',2],
      ['PAYMENT_METHOD','Payment Method',1],
      ['OPEN_PARCEL_POLICY','Open Parcel Policy',2],
      ['LEGITIMACY_INFO','Legitimacy Information',2],
      ['AVAILABILITY_INFORMATION','Availability Information',2],
    ];
    const LOOPS=[
      ['LOOP1','LOOP 1 — Order Form',7],
      ['LOOP2','LOOP 2 — Order Confirmation',5],
    ];
    function field(key,label,rows){
      const s=window.PGS.settings||{};
      const wrap=document.createElement('label'); wrap.className='pgs-field';
      const span=document.createElement('span'); span.textContent=label;
      const ta=document.createElement('textarea'); ta.setAttribute('data-setting',key); ta.rows=rows||2;
      ta.value = s[key]!=null ? s[key] : '';
      wrap.appendChild(span); wrap.appendChild(ta); return wrap;
    }
    function renderFields(){
      const pf=$('policyFields'), lf=$('loopFields'); pf.innerHTML=''; lf.innerHTML='';
      POLICY.forEach(([k,l,r])=>pf.appendChild(field(k,l,r)));
      LOOPS.forEach(([k,l,r])=>lf.appendChild(field(k,l,r)));
    }
    function renderRef(){
      const box=$('pgsRef'); box.innerHTML='';
      (window.PGS.promptRef||[]).forEach(r=>{
        const d=document.createElement('div'); d.style.marginBottom='12px';
        const h=document.createElement('div'); h.style.cssText='font-weight:700;font-size:12.5px;color:#0f172a;'; h.textContent=r.name;
        const inp=document.createElement('div'); inp.style.cssText='font-size:11.5px;color:#94a3b8;margin:2px 0;'; inp.textContent='Inputs: '+(r.inputs||'');
        const pre=document.createElement('pre'); pre.textContent=r.prompt||'';
        d.appendChild(h); d.appendChild(inp); d.appendChild(pre); box.appendChild(d);
      });
    }
    const jhead={'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':window.PGS.csrf};
    async function save(){
      const st=$('pgsStatus'), btn=$('pgsSave'); btn.disabled=true; btn.textContent='Saving…';
      const payload={}; document.querySelectorAll('[data-setting]').forEach(el=>payload[el.dataset.setting]=el.value);
      try{
        const res=await fetch(window.PGS.saveUrl,{method:'POST',headers:jhead,body:JSON.stringify({settings:payload})});
        const j=await res.json();
        if(!j.ok){ st.style.color='#b91c1c'; st.textContent=j.message||'Save failed'; return; }
        window.PGS.settings=j.settings||payload; renderFields();
        st.style.color='#16a34a'; st.textContent='Saved ✅';
      }catch(e){ st.style.color='#b91c1c'; st.textContent='Error: '+e.message; }
      finally{ btn.disabled=false; btn.textContent='💾 Save Settings'; setTimeout(()=>{ st.textContent=''; },4000); }
    }
    async function reset(){
      if(!confirm('Reset lahat ng protected defaults sa original values?')) return;
      const st=$('pgsStatus'), btn=$('pgsReset'); btn.disabled=true; btn.textContent='Resetting…';
      try{
        const res=await fetch(window.PGS.resetUrl,{method:'POST',headers:jhead,body:JSON.stringify({})});
        const j=await res.json();
        if(!j.ok){ st.style.color='#b91c1c'; st.textContent=j.message||'Reset failed'; return; }
        window.PGS.settings=j.settings||window.PGS.defaults; renderFields();
        st.style.color='#16a34a'; st.textContent='Reset to default ✅';
      }catch(e){ st.style.color='#b91c1c'; st.textContent='Error: '+e.message; }
      finally{ btn.disabled=false; btn.textContent='↩︎ Reset to Default'; setTimeout(()=>{ st.textContent=''; },4000); }
    }
    renderFields(); renderRef();
    $('pgsSave').onclick=save; $('pgsReset').onclick=reset;
  })();
  </script>
</x-layout>
