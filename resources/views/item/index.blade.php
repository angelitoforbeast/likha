<x-layout>
  <x-slot name="title">Item Hold</x-slot>
  <x-slot name="heading"><div class="text-xl font-bold">📦 Item Hold</div></x-slot>

  <style>
    .itx-wrap { padding:16px; max-width:1100px; margin:0 auto; }
    .itx-controls { display:flex; gap:8px; align-items:end; flex-wrap:wrap; margin-bottom:14px; }
    .itx-fld { display:flex; flex-direction:column; gap:3px; }
    .itx-fld span { font-size:11px; font-weight:600; color:#475569; }
    .itx-fld input { border:1px solid #d1d5db; border-radius:8px; padding:7px 9px; font-size:13px; }
    .itx-btn { border:0; border-radius:8px; padding:8px 14px; font-weight:600; font-size:13px; cursor:pointer; background:#4f46e5; color:#fff; }
    .itx-btn.ghost { background:#fff; border:1px solid #e2e8f0; color:#64748b; }
    .itx-summary { font-size:12.5px; color:#64748b; margin-left:auto; }
    .itx-item { border:1px solid #e5e7eb; border-radius:10px; background:#fff; margin-bottom:8px; overflow:hidden; }
    .itx-row { display:flex; align-items:center; gap:10px; padding:8px 12px; cursor:pointer; }
    .itx-row:hover { background:#f8fafc; }
    .itx-chev { width:14px; color:#94a3b8; transition:transform .15s; flex:0 0 auto; font-size:12px; }
    .itx-chev.open { transform:rotate(90deg); }
    .itx-sq { width:42px; height:42px; border-radius:8px; border:1px solid #e2e8f0; background:#f1f5f9; object-fit:cover; flex:0 0 auto; display:flex; align-items:center; justify-content:center; color:#cbd5e1; font-size:16px; }
    .itx-name { flex:1; min-width:0; font-weight:600; font-size:13px; color:#0f172a; word-break:break-word; }
    .itx-hold { flex:0 0 auto; font-weight:800; font-size:13px; color:#4338ca; background:#eef2ff; border:1px solid #c7d2fe; border-radius:999px; padding:3px 10px; }
    .itx-imgbtns { display:flex; gap:4px; flex:0 0 auto; }
    .itx-mini { border:0; border-radius:6px; padding:4px 8px; font-size:11px; font-weight:600; cursor:pointer; background:#f1f5f9; color:#475569; text-decoration:none; }
    .itx-mini:hover { background:#e2e8f0; }
    .itx-pages { display:none; border-top:1px solid #f1f5f9; background:#fbfcfe; padding:4px 8px 8px 34px; }
    .itx-page { border:1px solid #eef2f7; border-radius:8px; background:#fff; margin-top:6px; overflow:hidden; }
    .itx-prow { display:flex; align-items:center; gap:10px; padding:7px 10px; cursor:pointer; }
    .itx-prow:hover { background:#f8fafc; }
    .itx-pname { flex:1; min-width:0; font-size:12.5px; color:#334155; word-break:break-word; }
    .itx-camp { display:none; border-top:1px solid #f1f5f9; }
    .itx-camp iframe { width:100%; height:640px; border:0; display:block; background:#fff; }
    .itx-status { font-size:12.5px; color:#94a3b8; padding:20px; text-align:center; }
    .itx-help { font-size:11.5px; color:#94a3b8; margin-bottom:10px; }
  </style>

  <div class="itx-wrap">
    <div class="itx-help">Item-first HOLD view. Total hold galing sa parehong source ng <strong>/jnt/hold</strong>. I-expand ang item → mga pages → campaigns (mula sa /owner/private). Bawat item pwedeng lagyan ng photo.</div>
    <div class="itx-controls">
      <label class="itx-fld"><span>Start</span><input type="date" id="itxStart" value="{{ $defaultStart }}"></label>
      <label class="itx-fld"><span>End</span><input type="date" id="itxEnd" value="{{ $defaultEnd }}"></label>
      <label class="itx-fld" style="flex:1;min-width:160px;"><span>Search item / page</span><input type="text" id="itxQ" placeholder="hal. UGAT DAHON…"></label>
      <button class="itx-btn" id="itxLoad" type="button">Load</button>
      <span class="itx-summary" id="itxSummary"></span>
    </div>
    <div id="itxList"></div>
    <div id="itxStatus" class="itx-status">Pindutin ang Load para makita ang item hold.</div>
    <input id="itxFile" type="file" accept="image/*" hidden>
  </div>

  <script>
    window.ITX = {
      csrf:         '{{ csrf_token() }}',
      dataUrl:      '{{ route('item.data') }}',
      imageUrl:     '{{ route('item.image') }}',
      breakdownUrl: '{{ route('owner.private.breakdown') }}',
    };
  </script>
  <script>
  (function(){
    const $=id=>document.getElementById(id);
    let uploadTarget=null; // {item_name, sqEl}

    function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }
    function dateRange(){ const s=$('itxStart').value, e=$('itxEnd').value; return (s&&e)? (s+' to '+e) : (s||e||''); }

    async function load(){
      const st=$('itxStatus'); st.style.display='block'; st.textContent='Loading…'; $('itxList').innerHTML=''; $('itxSummary').textContent='';
      try{
        const u=new URL(window.ITX.dataUrl, location.origin);
        u.searchParams.set('date_range', dateRange());
        if($('itxQ').value.trim()) u.searchParams.set('q', $('itxQ').value.trim());
        const res=await fetch(u, {headers:{'Accept':'application/json'}});
        const j=await res.json();
        if(!j.ok){ st.textContent=j.message||'Failed'; return; }
        render(j.items||[]);
        $('itxSummary').textContent=(j.total_items||0)+' items · '+(j.total_hold||0).toLocaleString()+' total hold';
        st.style.display = (j.items&&j.items.length)?'none':'block';
        if(!(j.items&&j.items.length)) st.textContent='Walang hold sa date range na ito.';
      }catch(e){ st.textContent='Error: '+e.message; }
    }

    function render(items){
      const list=$('itxList'); list.innerHTML='';
      const start=$('itxStart').value, end=$('itxEnd').value;
      items.forEach(it=>{
        const box=document.createElement('div'); box.className='itx-item';

        // ITEM ROW
        const row=document.createElement('div'); row.className='itx-row';
        const chev=document.createElement('span'); chev.className='itx-chev'; chev.textContent='▶';
        // photo square
        let sq;
        if(it.image_url){ sq=document.createElement('img'); sq.className='itx-sq'; sq.src=it.image_url; sq.alt=it.item_name; }
        else { sq=document.createElement('div'); sq.className='itx-sq'; sq.textContent='🖼'; }
        const nm=document.createElement('div'); nm.className='itx-name'; nm.textContent=it.item_name;
        const hold=document.createElement('div'); hold.className='itx-hold'; hold.textContent='HOLD '+Number(it.total_hold||0).toLocaleString();
        const btns=document.createElement('div'); btns.className='itx-imgbtns';
        const viewB=document.createElement('a'); viewB.className='itx-mini'; viewB.textContent='View'; viewB.target='_blank'; viewB.rel='noopener';
        viewB.style.display = it.image_url ? '' : 'none'; if(it.image_url) viewB.href=it.image_url;
        const chB=document.createElement('button'); chB.className='itx-mini'; chB.type='button'; chB.textContent=it.image_url?'Change':'Add photo';
        chB.onclick=(e)=>{ e.stopPropagation(); uploadTarget={item_name:it.item_name, sqEl:sq, viewEl:viewB, btnEl:chB}; $('itxFile').click(); };
        // stop row toggle when clicking buttons
        viewB.onclick=(e)=>e.stopPropagation();
        btns.appendChild(viewB); btns.appendChild(chB);
        row.appendChild(chev); row.appendChild(sq); row.appendChild(nm); row.appendChild(hold); row.appendChild(btns);

        // PAGES container
        const pages=document.createElement('div'); pages.className='itx-pages';
        (it.pages||[]).forEach(pg=>{
          const pbox=document.createElement('div'); pbox.className='itx-page';
          const prow=document.createElement('div'); prow.className='itx-prow';
          const pchev=document.createElement('span'); pchev.className='itx-chev'; pchev.textContent='▶';
          const pnm=document.createElement('div'); pnm.className='itx-pname'; pnm.textContent=pg.page;
          const phold=document.createElement('div'); phold.className='itx-hold'; phold.textContent='HOLD '+Number(pg.total_hold||0).toLocaleString();
          prow.appendChild(pchev); prow.appendChild(pnm); prow.appendChild(phold);
          const camp=document.createElement('div'); camp.className='itx-camp';
          let loaded=false;
          prow.onclick=()=>{
            const open=camp.style.display==='block';
            camp.style.display=open?'none':'block'; pchev.classList.toggle('open',!open);
            if(!open && !loaded){
              loaded=true;
              const u=new URL(window.ITX.breakdownUrl, location.origin);
              u.searchParams.set('page_key', pg.page_key);
              if(start) u.searchParams.set('start_date', start);
              if(end) u.searchParams.set('end_date', end);
              const ifr=document.createElement('iframe'); ifr.src=u.toString(); ifr.loading='lazy';
              camp.appendChild(ifr);
            }
          };
          pbox.appendChild(prow); pbox.appendChild(camp); pages.appendChild(pbox);
        });

        row.onclick=()=>{ const open=pages.style.display==='block'; pages.style.display=open?'none':'block'; chev.classList.toggle('open',!open); };

        box.appendChild(row); box.appendChild(pages); list.appendChild(box);
      });
    }

    // Upload handler
    $('itxFile').onchange=async(e)=>{
      const file=e.target.files[0]; e.target.value='';
      if(!file || !uploadTarget) return;
      const t=uploadTarget; t.btnEl.textContent='…'; t.btnEl.disabled=true;
      try{
        const fd=new FormData(); fd.append('item_name', t.item_name); fd.append('image', file);
        const res=await fetch(window.ITX.imageUrl,{method:'POST',headers:{'Accept':'application/json','X-CSRF-TOKEN':window.ITX.csrf},body:fd});
        const j=await res.json();
        if(!j.ok){ t.btnEl.textContent='Failed'; alert(j.message||'Upload failed'); }
        else {
          // swap placeholder → img (or update src)
          if(t.sqEl.tagName==='IMG'){ t.sqEl.src=j.url; }
          else { const img=document.createElement('img'); img.className='itx-sq'; img.src=j.url; img.alt=t.item_name; t.sqEl.replaceWith(img); t.sqEl=img; }
          t.viewEl.href=j.url; t.viewEl.style.display='';
          t.btnEl.textContent='Change';
        }
      }catch(err){ t.btnEl.textContent='Error'; }
      finally{ t.btnEl.disabled=false; }
    };

    $('itxLoad').onclick=load;
    // auto-load on first open
    load();
  })();
  </script>
</x-layout>
