<x-layout>
  <x-slot name="title">Prompt Generator — Settings</x-slot>
  <x-slot name="heading"><div class="text-xl font-bold">⚙️ Prompt Generator — Settings</div></x-slot>

  <style>
    .pgs-wrap { padding:20px; max-width:920px; margin:0 auto; }
    .pgs-card { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:20px; margin-bottom:16px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    .pgs-topbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px; flex-wrap:wrap; }
    .pgs-link { color:#4f46e5; font-weight:600; text-decoration:none; font-size:13px; }
    .pgs-link:hover { text-decoration:underline; }
    .pgs-subnav { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px; }
    .pgs-subnav a { padding:8px 14px; font-size:13px; font-weight:600; border-radius:9px; text-decoration:none; color:#64748b; background:#fff; border:1px solid #e2e8f0; }
    .pgs-subnav a:hover { background:#f1f5f9; }
    .pgs-subnav a.active { background:#eef2ff; color:#4338ca; border-color:#c7d2fe; }
    .pgs-field { display:block; margin-bottom:14px; }
    .pgs-field > span { display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px; }
    .pgs-field textarea, .pgs-field input { width:100%; border:1px solid #d1d5db; border-radius:8px; padding:9px 11px; font-size:13px; font-family:inherit; line-height:1.5; }
    .pgs-field textarea:focus, .pgs-field input:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 2px rgba(99,102,241,.18); }
    .pgs-btn { border:0; border-radius:8px; padding:9px 16px; font-weight:600; font-size:13px; cursor:pointer; }
    .pgs-btn.primary { background:#4f46e5; color:#fff; } .pgs-btn.primary:hover { background:#4338ca; }
    .pgs-btn.ghost { background:#fff; border:1px solid #e2e8f0; color:#64748b; }
    .pgs-sec-title { font-size:14px; font-weight:800; color:#0f172a; margin:2px 0 10px; }
    .pgs-help { font-size:12px; color:#94a3b8; margin-bottom:12px; line-height:1.45; }
    .pgs-ref pre { white-space:pre-wrap; word-break:break-word; font-size:11.5px; background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; padding:10px; max-height:260px; overflow:auto; color:#334155; margin:6px 0 0; }
    .pgs-lockgroup h4 { font-size:11px; text-transform:uppercase; letter-spacing:.08em; color:#6366f1; margin:12px 0 6px; font-weight:700; }
    .pgs-lockrow { display:flex; align-items:center; gap:10px; padding:7px 10px; border:1px solid #eef2f7; border-radius:8px; margin-bottom:5px; background:#fff; }
    .pgs-lockrow.locked { background:#f5f3ff; border-color:#c7d2fe; }
    .pgs-lockrow .lbl { flex:1; font-size:12.5px; color:#334155; }
    .pgs-lockrow .lockicon { font-size:15px; width:20px; text-align:center; }
    .pgs-lockrow input { width:16px; height:16px; cursor:pointer; margin:0; }
    .pgs-prompt { border:1px solid #e5e7eb; border-radius:10px; padding:12px; margin-bottom:12px; background:#fff; }
    .pgs-prompt-head { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:6px; flex-wrap:wrap; }
    .pgs-prompt-title { font-weight:700; font-size:13px; color:#0f172a; }
    .pgs-badge { font-size:9px; font-weight:800; padding:2px 6px; border-radius:999px; background:#eef2ff; color:#4338ca; border:1px solid #c7d2fe; margin-left:6px; }
    .pgs-prompt textarea { width:100%; border:1px solid #d1d5db; border-radius:8px; padding:9px 11px; font-size:12px; font-family:ui-monospace,Menlo,Consolas,monospace; line-height:1.5; min-height:180px; resize:vertical; }
    .pgs-verlist { margin-top:8px; }
    .pgs-verrow { display:flex; align-items:center; gap:8px; padding:6px 8px; border:1px solid #eef2f7; border-radius:8px; margin-bottom:4px; font-size:11.5px; color:#475569; }
    .pgs-verrow .vpreview { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .pgs-verrow .vmeta { color:#94a3b8; white-space:nowrap; }
    .pgs-btn.mini { padding:5px 10px; font-size:12px; }
  </style>

  @php $sec = $section ?? 'defaults'; @endphp

  <div class="pgs-wrap">
    <div class="pgs-topbar">
      <div class="pgs-sec-title" style="margin:0;">Prompt Generator Settings</div>
      <a href="{{ route('prompt.generator.index') }}" class="pgs-link">← Back to Generator</a>
    </div>

    <div class="pgs-subnav">
      <a href="{{ route('prompt.generator.settings.get') }}"        class="{{ $sec==='defaults' ? 'active' : '' }}">Default Values</a>
      <a href="{{ route('prompt.generator.settings.protection') }}" class="{{ $sec==='protection' ? 'active' : '' }}">🔒 Auto-Fill Protection</a>
      <a href="{{ route('prompt.generator.settings.prompts') }}"    class="{{ $sec==='prompts' ? 'active' : '' }}">📋 Generation Prompts</a>
    </div>

    @if($sec === 'defaults')
    <div class="pgs-card">
      <div class="pgs-help">Protected defaults — ito ang ginagamit na default sa prompt at sa Copy for Sheet. Naka-save sa database; may Reset to Default.</div>
      <div class="pgs-sec-title">Policies &amp; Delivery</div>
      <div id="policyFields"></div>
      <div class="pgs-sec-title" style="margin-top:16px;">Bot Flow — Loops (for Sheet)</div>
      <div class="pgs-help">LOOP 1 = order form, LOOP 2 = order confirmation. Ginagamit sa Copy for Sheet.</div>
      <div id="loopFields"></div>
      <div style="display:flex; align-items:center; gap:10px; margin-top:12px; flex-wrap:wrap;">
        <button class="pgs-btn primary" id="pgsSave" type="button">💾 Save Default Values</button>
        <button class="pgs-btn ghost" id="pgsReset" type="button">↩︎ Reset to Default</button>
        <span id="pgsStatus" style="font-size:12px; color:#94a3b8;"></span>
      </div>
    </div>
    @endif

    @if($sec === 'protection')
    <div class="pgs-card">
      <div class="pgs-sec-title">🔒 Auto-Fill Protection</div>
      <div class="pgs-help">I-lock (🔒) ang mga field na <strong>ayaw mong baguhin ng Analyze Image</strong>. Naka-lock = protektado (walang overwrite, walang REVIEW). Hindi naka-lock = pwedeng i-autofill.</div>
      <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px; flex-wrap:wrap;">
        <button class="pgs-btn primary" id="lockSave" type="button">💾 Save Protection</button>
        <button class="pgs-btn ghost" id="lockAll" type="button">🔒 Lock All</button>
        <button class="pgs-btn ghost" id="unlockAll" type="button">🔓 Unlock All</button>
        <span id="lockStatus" style="font-size:12px; color:#94a3b8;"></span>
      </div>
      <div id="lockList"></div>
    </div>
    @endif

    @if($sec === 'prompts')
    <div class="pgs-card">
      <div class="pgs-sec-title">✏️ Editable Prompts</div>
      <div class="pgs-help">I-edit, i-Save (naka-log na version), i-Restore sa naunang version, o Reset sa code default. <strong>Note:</strong> Sa Sales/After-Sales, ang <code>@{{OFFERS_AND_POLICY}}</code> marker ang awtomatikong pinapalitan ng PRICING/SHIPPING/DELIVERY sections (adaptive sa single vs bundle) — huwag itong burahin. Huwag ding burahin ang <code>@{{PLACEHOLDER}}</code> tokens (hal. <code>@{{STORE_NAME}}</code>). Pwede mong kopyahin ang buong template, ipa-edit kay ChatGPT, tapos i-paste pabalik.</div>
      <div id="editablePrompts"></div>
    </div>
    <div class="pgs-card pgs-ref">
      <div class="pgs-sec-title">📋 Deterministic Prompts (read-only)</div>
      <div class="pgs-help">Ang Sales Prompt at After-Sales ay binubuo client-side mula sa template + inputs; ang Test ay ginagamit ang na-generate na prompt. Info lang.</div>
      <div id="pgsRef"></div>
    </div>
    @endif
  </div>

  <script>
    window.PGS = {
      csrf:      '{{ csrf_token() }}',
      section:   @json($sec),
      saveUrl:   '{{ route('prompt.generator.settings.save') }}',
      resetUrl:  '{{ route('prompt.generator.settings.reset') }}',
      promptSaveUrl:    '{{ route('prompt.generator.prompts.save') }}',
      promptResetUrl:   '{{ route('prompt.generator.prompts.reset') }}',
      promptRestoreUrl: '{{ route('prompt.generator.prompts.restore') }}',
      editablePrompts:  @json($editablePrompts ?? []),
      infoPrompts:      @json($infoPrompts ?? []),
      settings:  @json($settings ?? []),
      defaults:  @json($defaults ?? []),
      promptRef: @json($promptRef ?? []),
      catalog:   @json($catalog ?? (object)[]),
      locked:    @json($locked ?? []),
    };
  </script>
  <script>
  (function(){
    const $=id=>document.getElementById(id);
    const jhead={'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':window.PGS.csrf};

    // ── Default Values ──
    const POLICY=[
      ['WARRANTY_POLICY','Warranty / Replacement Policy',2],
      ['COVERAGE_AREA','Coverage Area',2],
      ['DELIVERY_TIME','Delivery Time',2],
      ['PAYMENT_METHOD','Payment Method',1],
      ['OPEN_PARCEL_POLICY','Open Parcel Policy',2],
      ['LEGITIMACY_INFO','Legitimacy Information',2],
      ['AVAILABILITY_INFORMATION','Availability Information',2],
    ];
    const LOOPS=[['LOOP1','LOOP 1 — Order Form',7],['LOOP2','LOOP 2 — Order Confirmation',5]];
    function field(key,label,rows){
      const s=window.PGS.settings||{};
      const wrap=document.createElement('label'); wrap.className='pgs-field';
      const span=document.createElement('span'); span.textContent=label;
      const ta=document.createElement('textarea'); ta.setAttribute('data-setting',key); ta.rows=rows||2;
      ta.value = s[key]!=null ? s[key] : '';
      wrap.appendChild(span); wrap.appendChild(ta); return wrap;
    }
    function renderFields(){
      const pf=$('policyFields'), lf=$('loopFields'); if(!pf||!lf) return;
      pf.innerHTML=''; lf.innerHTML='';
      POLICY.forEach(([k,l,r])=>pf.appendChild(field(k,l,r)));
      LOOPS.forEach(([k,l,r])=>lf.appendChild(field(k,l,r)));
    }
    async function saveDefaults(){
      const st=$('pgsStatus'), btn=$('pgsSave'); btn.disabled=true; btn.textContent='Saving…';
      const payload={}; document.querySelectorAll('[data-setting]').forEach(el=>payload[el.dataset.setting]=el.value);
      try{
        const res=await fetch(window.PGS.saveUrl,{method:'POST',headers:jhead,body:JSON.stringify({settings:payload})});
        const j=await res.json();
        if(!j.ok){ st.style.color='#b91c1c'; st.textContent=j.message||'Save failed'; return; }
        window.PGS.settings=j.settings||payload; renderFields();
        st.style.color='#16a34a'; st.textContent='Saved ✅';
      }catch(e){ st.style.color='#b91c1c'; st.textContent='Error: '+e.message; }
      finally{ btn.disabled=false; btn.textContent='💾 Save Default Values'; setTimeout(()=>{ st.textContent=''; },4000); }
    }
    async function resetDefaults(){
      if(!confirm('Reset lahat ng Default Values sa original?')) return;
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

    // ── Auto-Fill Protection ──
    function renderLocks(){
      const box=$('lockList'); if(!box) return;
      const cat=window.PGS.catalog||{}; const locked=new Set(window.PGS.locked||[]);
      box.innerHTML='';
      Object.keys(cat).forEach(group=>{
        const g=document.createElement('div'); g.className='pgs-lockgroup';
        const h=document.createElement('h4'); h.textContent=group; g.appendChild(h);
        Object.keys(cat[group]).forEach(key=>{
          const isLocked=locked.has(key);
          const row=document.createElement('label'); row.className='pgs-lockrow'+(isLocked?' locked':'');
          const cb=document.createElement('input'); cb.type='checkbox'; cb.setAttribute('data-lock',key); cb.checked=isLocked;
          const ic=document.createElement('span'); ic.className='lockicon'; ic.textContent=isLocked?'🔒':'🔓';
          const lbl=document.createElement('span'); lbl.className='lbl'; lbl.textContent=cat[group][key];
          cb.addEventListener('change',()=>{ row.classList.toggle('locked',cb.checked); ic.textContent=cb.checked?'🔒':'🔓'; });
          row.appendChild(cb); row.appendChild(ic); row.appendChild(lbl); g.appendChild(row);
        });
        box.appendChild(g);
      });
    }
    function collectLocks(){ const out=[]; document.querySelectorAll('[data-lock]').forEach(cb=>{ if(cb.checked) out.push(cb.dataset.lock); }); return out; }
    function setAllLocks(on){ document.querySelectorAll('[data-lock]').forEach(cb=>{ cb.checked=on; cb.dispatchEvent(new Event('change')); }); }
    async function saveLocks(){
      const st=$('lockStatus'), btn=$('lockSave'); btn.disabled=true; btn.textContent='Saving…';
      try{
        const res=await fetch(window.PGS.saveUrl,{method:'POST',headers:jhead,body:JSON.stringify({locked:collectLocks()})});
        const j=await res.json();
        if(!j.ok){ st.style.color='#b91c1c'; st.textContent=j.message||'Save failed'; return; }
        if(j.locked) window.PGS.locked=j.locked; renderLocks();
        st.style.color='#16a34a'; st.textContent='Saved ✅';
      }catch(e){ st.style.color='#b91c1c'; st.textContent='Error: '+e.message; }
      finally{ btn.disabled=false; btn.textContent='💾 Save Protection'; setTimeout(()=>{ st.textContent=''; },4000); }
    }

    // ── Editable AI Prompts (+ version history / restore) ──
    function findPrompt(key){ return (window.PGS.editablePrompts||[]).find(x=>x.key===key); }
    function renderVersions(box,p){
      box.innerHTML=''; const vs=p.versions||[];
      if(!vs.length){ box.innerHTML='<div class="pgs-help" style="margin:4px 0;">Wala pang saved version.</div>'; return; }
      vs.forEach(v=>{
        const row=document.createElement('div'); row.className='pgs-verrow';
        const pv=document.createElement('span'); pv.className='vpreview'; pv.textContent=v.preview||'(blank)';
        const meta=document.createElement('span'); meta.className='vmeta'; meta.textContent=(v.at||'')+' · '+v.len+' chars';
        const rb=document.createElement('button'); rb.className='pgs-btn ghost mini'; rb.textContent='Restore';
        rb.onclick=()=>{ if(confirm('Restore ang '+p.label+' sa version na ito?')) restorePromptFn(p.key,v.id); };
        row.appendChild(pv); row.appendChild(meta); row.appendChild(rb); box.appendChild(row);
      });
    }
    function promptCard(p){
      const wrap=document.createElement('div'); wrap.className='pgs-prompt'; wrap.setAttribute('data-prompt',p.key);
      const head=document.createElement('div'); head.className='pgs-prompt-head';
      const title=document.createElement('div'); title.className='pgs-prompt-title'; title.textContent=p.label;
      if(p.isDefault){ const b=document.createElement('span'); b.className='pgs-badge'; b.textContent='DEFAULT'; title.appendChild(b); }
      const status=document.createElement('span'); status.className='pstatus'; status.style.cssText='font-size:12px;color:#94a3b8;';
      head.appendChild(title); head.appendChild(status);
      const ta=document.createElement('textarea'); ta.value=p.current||'';
      const actions=document.createElement('div'); actions.style.cssText='display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;';
      const saveB=document.createElement('button'); saveB.className='pgs-btn primary mini'; saveB.textContent='💾 Save';
      const resetB=document.createElement('button'); resetB.className='pgs-btn ghost mini'; resetB.textContent='↩︎ Reset default';
      const verB=document.createElement('button'); verB.className='pgs-btn ghost mini'; verB.textContent='🕘 Versions ('+((p.versions||[]).length)+')';
      actions.appendChild(saveB); actions.appendChild(resetB); actions.appendChild(verB);
      const verBox=document.createElement('div'); verBox.className='pgs-verlist'; verBox.style.display='none';
      saveB.onclick=()=>savePromptFn(p.key,ta.value,status);
      resetB.onclick=()=>{ if(confirm('Reset '+p.label+' sa code default?')) resetPromptFn(p.key,status); };
      verB.onclick=()=>{ verBox.style.display=verBox.style.display==='none'?'block':'none'; };
      renderVersions(verBox,p);
      wrap.appendChild(head); wrap.appendChild(ta); wrap.appendChild(actions); wrap.appendChild(verBox);
      return wrap;
    }
    function refreshCard(key,resp){
      const p=findPrompt(key); if(!p) return;
      if(resp.current!=null) p.current=resp.current;
      if(resp.versions) p.versions=resp.versions;
      p.isDefault = p.current === (p.default||'');
      const old=document.querySelector('[data-prompt="'+key+'"]');
      if(old){ const nw=promptCard(p); old.replaceWith(nw); const s=nw.querySelector('.pstatus'); if(s){ s.style.color='#16a34a'; s.textContent='Saved ✅'; setTimeout(()=>{ s.textContent=''; },3500); } }
    }
    async function savePromptFn(key,content,status){
      status.style.color='#94a3b8'; status.textContent='Saving…';
      try{
        const res=await fetch(window.PGS.promptSaveUrl,{method:'POST',headers:jhead,body:JSON.stringify({key,content})});
        const j=await res.json();
        if(!j.ok){ status.style.color='#b91c1c'; status.textContent=j.message||'Save failed'; return; }
        refreshCard(key,j);
      }catch(e){ status.style.color='#b91c1c'; status.textContent='Error: '+e.message; }
    }
    async function resetPromptFn(key,status){
      status.style.color='#94a3b8'; status.textContent='Resetting…';
      try{
        const res=await fetch(window.PGS.promptResetUrl,{method:'POST',headers:jhead,body:JSON.stringify({key})});
        const j=await res.json();
        if(!j.ok){ status.style.color='#b91c1c'; status.textContent=j.message||'Reset failed'; return; }
        refreshCard(key,j);
      }catch(e){ status.style.color='#b91c1c'; status.textContent='Error: '+e.message; }
    }
    async function restorePromptFn(key,versionId){
      try{
        const res=await fetch(window.PGS.promptRestoreUrl,{method:'POST',headers:jhead,body:JSON.stringify({key,version_id:versionId})});
        const j=await res.json();
        if(!j.ok){ alert(j.message||'Restore failed'); return; }
        refreshCard(key,j);
      }catch(e){ alert('Error: '+e.message); }
    }
    function renderEditablePrompts(){
      const box=$('editablePrompts'); if(!box) return; box.innerHTML='';
      (window.PGS.editablePrompts||[]).forEach(p=>box.appendChild(promptCard(p)));
    }
    function renderInfoPrompts(){
      const box=$('pgsRef'); if(!box) return; box.innerHTML='';
      (window.PGS.infoPrompts||[]).forEach(r=>{
        const d=document.createElement('div'); d.style.marginBottom='14px';
        const h=document.createElement('div'); h.style.cssText='font-weight:700;font-size:12.5px;color:#0f172a;'; h.textContent=r.name;
        const inp=document.createElement('div'); inp.style.cssText='font-size:11.5px;color:#94a3b8;margin:2px 0;'; inp.textContent='Inputs: '+(r.inputs||'');
        const pre=document.createElement('pre'); pre.textContent=r.prompt||'';
        d.appendChild(h); d.appendChild(inp); d.appendChild(pre); box.appendChild(d);
      });
    }

    // init per section
    const sec=window.PGS.section;
    if(sec==='defaults'){ renderFields(); if($('pgsSave')) $('pgsSave').onclick=saveDefaults; if($('pgsReset')) $('pgsReset').onclick=resetDefaults; }
    else if(sec==='protection'){ renderLocks(); if($('lockSave')) $('lockSave').onclick=saveLocks; if($('lockAll')) $('lockAll').onclick=()=>setAllLocks(true); if($('unlockAll')) $('unlockAll').onclick=()=>setAllLocks(false); }
    else if(sec==='prompts'){ renderEditablePrompts(); renderInfoPrompts(); }
  })();
  </script>
</x-layout>
