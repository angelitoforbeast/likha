<x-layout>
  <x-slot name="title">Image Host</x-slot>
  <x-slot name="heading"><div class="text-xl font-bold">🖼️ Image Host</div></x-slot>

  <style>
    .ih-wrap { padding:20px; max-width:1000px; margin:0 auto; }
    .ih-card { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:20px; margin-bottom:16px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    .ih-drop { border:2px dashed #a5b4fc; border-radius:12px; background:#faf5ff; padding:26px; text-align:center; cursor:pointer; color:#4338ca; font-weight:600; }
    .ih-drop:hover { background:#f5f3ff; border-color:#6366f1; }
    .ih-drop.drag { background:#eef2ff; border-color:#4f46e5; }
    .ih-btn { border:0; border-radius:8px; padding:8px 14px; font-weight:600; font-size:13px; cursor:pointer; background:#eef2ff; color:#4338ca; }
    .ih-btn.primary { background:#4f46e5; color:#fff; }
    .ih-btn.danger { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }
    .ih-btn.mini { padding:5px 10px; font-size:12px; }
    .ih-status { font-size:12.5px; color:#64748b; margin-top:10px; }
    .ih-result { margin-top:14px; display:none; }
    .ih-urlrow { display:flex; gap:8px; align-items:center; }
    .ih-url { flex:1; min-width:0; border:1px solid #d1d5db; border-radius:8px; padding:8px 10px; font-size:12.5px; font-family:ui-monospace,Menlo,Consolas,monospace; background:#f8fafc; }
    .ih-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px; }
    .ih-item { border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; background:#fff; display:flex; flex-direction:column; }
    .ih-thumb { width:100%; height:130px; object-fit:cover; background:#f1f5f9; }
    .ih-meta { padding:8px; display:flex; flex-direction:column; gap:6px; }
    .ih-fname { font-size:11px; color:#64748b; word-break:break-all; line-height:1.3; }
    .ih-actions { display:flex; gap:6px; }
    .ih-help { font-size:12px; color:#94a3b8; line-height:1.5; }
  </style>

  <div class="ih-wrap">
    <div class="ih-card">
      <div class="ih-help" style="margin-bottom:12px;">Mag-upload ng picture → makakakuha ka ng <strong>public URL</strong> na pwede mong gamitin bilang image_url (hal. sa BotCake). Ang link ay tuluyang publiko (kahit walang login) para ma-load ng mga external service.</div>
      <input id="ihFile" type="file" accept="image/*" hidden>
      <div id="ihDrop" class="ih-drop">📤 Click o i-drop ang image dito<div style="font-size:11.5px;color:#94a3b8;font-weight:400;margin-top:4px;">JPG, PNG, WEBP, GIF — hanggang 10 MB</div></div>
      <div id="ihStatus" class="ih-status"></div>
      <div id="ihResult" class="ih-result">
        <div style="font-size:12px;font-weight:700;color:#166534;margin-bottom:5px;">✅ Public URL:</div>
        <div class="ih-urlrow">
          <input id="ihResultUrl" class="ih-url" readonly>
          <button id="ihCopyResult" class="ih-btn primary mini" type="button">Copy</button>
          <a id="ihOpen" class="ih-btn mini" target="_blank" rel="noopener">Open</a>
        </div>
      </div>
    </div>

    <div class="ih-card">
      <div style="font-size:14px;font-weight:800;color:#0f172a;margin-bottom:12px;">Mga na-upload (<span id="ihCount">0</span>)</div>
      <div id="ihGrid" class="ih-grid"></div>
      <div id="ihEmpty" class="ih-help" style="display:none;">Wala pang na-upload na image.</div>
    </div>
  </div>

  <script>
    window.IH = {
      csrf:      '{{ csrf_token() }}',
      uploadUrl: '{{ route('image.host.upload') }}',
      deleteUrl: '{{ route('image.host.delete') }}',
      images:    @json($images ?? []),
    };
  </script>
  <script>
  (function(){
    const $=id=>document.getElementById(id);
    const jhead={'Accept':'application/json','X-CSRF-TOKEN':window.IH.csrf};

    async function copyText(t){
      try{ await navigator.clipboard.writeText(t); return true; }
      catch(e){ try{ const ta=document.createElement('textarea'); ta.value=t; ta.style.cssText='position:fixed;opacity:0'; document.body.appendChild(ta); ta.select(); const ok=document.execCommand('copy'); document.body.removeChild(ta); return ok; }catch(e2){ return false; } }
    }
    function flash(btn,txt){ const o=btn.textContent; btn.textContent=txt; setTimeout(()=>btn.textContent=o,1400); }

    function makeItem(img){
      const it=document.createElement('div'); it.className='ih-item'; it.setAttribute('data-name',img.name);
      const th=document.createElement('img'); th.className='ih-thumb'; th.src=img.url; th.loading='lazy'; th.alt=img.name;
      const meta=document.createElement('div'); meta.className='ih-meta';
      const fn=document.createElement('div'); fn.className='ih-fname'; fn.textContent=img.name;
      const acts=document.createElement('div'); acts.className='ih-actions';
      const cp=document.createElement('button'); cp.className='ih-btn mini'; cp.type='button'; cp.textContent='Copy URL';
      cp.onclick=async()=>{ const ok=await copyText(img.url); flash(cp, ok?'Copied ✓':'Failed'); };
      const op=document.createElement('a'); op.className='ih-btn mini'; op.textContent='Open'; op.href=img.url; op.target='_blank'; op.rel='noopener';
      const del=document.createElement('button'); del.className='ih-btn danger mini'; del.type='button'; del.textContent='Delete';
      del.onclick=()=>removeImage(img.name, it);
      acts.appendChild(cp); acts.appendChild(op); acts.appendChild(del);
      meta.appendChild(fn); meta.appendChild(acts);
      it.appendChild(th); it.appendChild(meta);
      return it;
    }
    function renderGrid(){
      const grid=$('ihGrid'); grid.innerHTML='';
      (window.IH.images||[]).forEach(img=>grid.appendChild(makeItem(img)));
      $('ihCount').textContent=(window.IH.images||[]).length;
      $('ihEmpty').style.display=(window.IH.images||[]).length?'none':'block';
    }
    async function removeImage(name, el){
      if(!confirm('Delete ang image na ito? (permanenteng mabubura)')) return;
      try{
        const fd=new FormData(); fd.append('name',name);
        const res=await fetch(window.IH.deleteUrl,{method:'POST',headers:jhead,body:fd});
        const j=await res.json();
        if(j.ok){ window.IH.images=(window.IH.images||[]).filter(x=>x.name!==name); el.remove(); $('ihCount').textContent=window.IH.images.length; $('ihEmpty').style.display=window.IH.images.length?'none':'block'; }
      }catch(e){}
    }
    async function uploadFile(file){
      if(!file){ return; }
      $('ihStatus').style.color='#4338ca'; $('ihStatus').textContent='Uploading '+file.name+'…';
      try{
        const fd=new FormData(); fd.append('image',file);
        const res=await fetch(window.IH.uploadUrl,{method:'POST',headers:jhead,body:fd});
        const j=await res.json();
        if(!j.ok){ $('ihStatus').style.color='#b91c1c'; $('ihStatus').textContent=(j.message||'Upload failed'); return; }
        $('ihStatus').style.color='#16a34a'; $('ihStatus').textContent='Tapos ✓';
        $('ihResult').style.display='block'; $('ihResultUrl').value=j.url; $('ihOpen').href=j.url;
        window.IH.images=[{name:j.name,url:j.url,size:file.size}, ...(window.IH.images||[])];
        renderGrid();
      }catch(e){ $('ihStatus').style.color='#b91c1c'; $('ihStatus').textContent='Error: '+e.message; }
    }

    // wiring
    const drop=$('ihDrop'), fileInput=$('ihFile');
    drop.onclick=()=>fileInput.click();
    fileInput.onchange=e=>{ if(e.target.files[0]) uploadFile(e.target.files[0]); fileInput.value=''; };
    drop.addEventListener('dragover',e=>{ e.preventDefault(); drop.classList.add('drag'); });
    drop.addEventListener('dragleave',()=>drop.classList.remove('drag'));
    drop.addEventListener('drop',e=>{ e.preventDefault(); drop.classList.remove('drag'); if(e.dataTransfer.files[0]) uploadFile(e.dataTransfer.files[0]); });
    $('ihCopyResult').onclick=async()=>{ const ok=await copyText($('ihResultUrl').value); flash($('ihCopyResult'), ok?'Copied ✓':'Failed'); };

    renderGrid();
  })();
  </script>
</x-layout>
