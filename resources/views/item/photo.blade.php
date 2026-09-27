<x-layout>
  <x-slot name="title">Item Photos</x-slot>
  <x-slot name="heading"><div class="text-xl font-bold">🖼 Item Photos</div></x-slot>

  <style>
    .ph-wrap { max-width:1100px; margin:16px auto; padding:0 16px; }
    .ph-top { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:12px; }
    .ph-search { flex:1; min-width:200px; border:1px solid #d1d5db; border-radius:8px; padding:8px 12px; font-size:13px; }
    .ph-count { font-size:12.5px; color:#64748b; }
    .ph-hint { font-size:11.5px; color:#94a3b8; margin-bottom:12px; }
    /* ROW layout — isang row kada item (dating card grid). */
    .ph-table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; }
    .ph-table th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#64748b; background:#f8fafc; padding:8px 10px; border-bottom:1px solid #e5e7eb; }
    .ph-table td { padding:8px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
    .ph-row { cursor:pointer; transition:background .12s, box-shadow .12s; }
    .ph-row:hover { background:#f5f3ff; }
    .ph-row.noimg { background:#fff7ed; }
    .ph-row.active { box-shadow:inset 4px 0 0 #4f46e5; background:#eef2ff; }
    .ph-row.saved { box-shadow:inset 4px 0 0 #10b981; background:#ecfdf5; }
    .ph-thumb { width:56px; height:56px; border-radius:8px; border:1px solid #e2e8f0; background:#f8fafc; display:flex; align-items:center; justify-content:center; color:#cbd5e1; font-size:24px; overflow:hidden; }
    .ph-thumb img { width:100%; height:100%; object-fit:cover; }
    .ph-name { font-size:12.5px; font-weight:700; color:#0f172a; line-height:1.3; word-break:break-word; }
    .ph-hold { align-self:flex-start; font-size:10px; font-weight:800; color:#7c2d12; background:#ffedd5; border:1px solid #fed7aa; border-radius:999px; padding:2px 8px; }
    .ph-badge-no { align-self:flex-start; font-size:10px; font-weight:700; color:#b45309; background:#ffedd5; border:1px solid #fed7aa; border-radius:999px; padding:2px 8px; }
    .ph-actions { display:flex; align-items:center; gap:6px; margin-top:auto; }
    .ph-btn { border:0; border-radius:6px; padding:5px 10px; font-size:11.5px; font-weight:700; cursor:pointer; background:#4f46e5; color:#fff; }
    .ph-btn:hover { background:#4338ca; }
    .ph-del { border:0; border-radius:6px; padding:5px 8px; font-size:11px; font-weight:700; cursor:pointer; background:#fee2e2; color:#b91c1c; }
    .ph-del:hover { background:#fca5a5; }
    .ph-saved { font-size:11px; color:#059669; font-weight:700; }
    .ph-status { text-align:center; color:#94a3b8; padding:40px; font-size:13px; }
    .ph-sort { cursor:pointer; user-select:none; white-space:nowrap; }
    .ph-sort:hover { background:#eef2ff; }
    .ph-sort .arr { color:#4f46e5; font-size:10px; margin-left:3px; }
  </style>

  <div class="ph-wrap" x-data="photoList()" x-init="init()">
    <a href="{{ route('item.index') }}" style="font-size:13px;color:#4f46e5;text-decoration:none;font-weight:600;">← Bumalik sa /item</a>

    <div class="ph-top" style="margin-top:10px;">
      <input class="ph-search" type="text" x-model="q" placeholder="Hanapin ang item…">
      <span class="ph-count">
        <span x-text="filtered().length"></span> item ·
        <b style="color:#b45309;" x-text="noImageCount()"></b> walang photo
        <span style="color:#cbd5e1;">·</span>
        <span x-text="startDate + ' → ' + endDate"></span>
      </span>
    </div>
    <div class="ph-hint">
      Mga item na nasa <b>/item</b> (sa range na iyon). I-<b>click</b> ang row para maging aktibo, tapos <b>Ctrl+V</b> para i-paste ang larawan doon — o pindutin ang <b>Upload</b>. Walang photo = nasa itaas.
    </div>

    <div class="ph-status" x-show="loading">Naglo-load ng items…</div>

    {{-- ROW layout: isang <tr> kada item. Same id ('card-<slug>') para gumana pa rin
         ang ?item= focus/scroll; same activeItem para sa Ctrl+V paste target. --}}
    <div x-show="!loading" style="overflow-x:auto;">
      <table class="ph-table">
        <thead>
          <tr>
            <th style="width:64px;" class="ph-sort" @click="sortBy('photo')" title="Walang photo muna / may photo muna">Photo<span class="arr" x-text="arrow('photo')"></span></th>
            <th class="ph-sort" @click="sortBy('item')" title="A–Z / Z–A">Item<span class="arr" x-text="arrow('item')"></span></th>
            <th style="width:90px;" class="ph-sort" @click="sortBy('hold')" title="Pinakamaraming hold muna">HOLD<span class="arr" x-text="arrow('hold')"></span></th>
            @if($isCEO)<th style="min-width:220px;" class="ph-sort" @click="sortBy('po')" title="Pinakamababang presyo sa PO muna; walang PO sa dulo">Supplier / Presyo<span class="arr" x-text="arrow('po')"></span></th>@endif
            @if($isCEO)<th style="min-width:300px;" class="ph-sort" @click="sortBy('quote')" title="WALANG supplier muna; ulitin ang click = pinakamaraming supplier muna">Suppliers (quote)<span class="arr" x-text="arrow('quote')"></span></th>@endif
            <th style="width:230px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <template x-for="it in filtered()" :key="it.item_name">
            <tr class="ph-row"
                :id="'card-'+slug(it.item_name)"
                :class="{ 'active': activeItem===it.item_name, 'saved': savedItem===it.item_name, 'noimg': !it.image_url }"
                @click="activeItem=it.item_name">
              <td>
                <div class="ph-thumb">
                  <template x-if="it.image_url"><img :src="it.image_url" :alt="it.item_name" loading="lazy"
                       style="cursor:zoom-in;" title="I-click: popup + auto-copy ng image" @click.stop="openPhoto(it)"></template>
                  <template x-if="!it.image_url"><span>🖼</span></template>
                </div>
              </td>
              <td>
                <div class="ph-name" x-text="it.item_name"></div>
                <template x-if="!it.image_url"><span class="ph-badge-no">wala pang photo</span></template>
              </td>
              <td><span class="ph-hold" x-text="Number(it.hold||0).toLocaleString()"></span></td>
              @if($isCEO)
              <td style="font-size:11px;line-height:1.4;">
                {{-- Supplier(s) + latest unit cost (Supply Finance) — CEO LANG, isang linya kada supplier. --}}
                <template x-if="(it.suppliers||[]).length">
                  <div style="color:#0f172a;">
                    <template x-for="(s, si) in it.suppliers" :key="'sup-'+it.item_name+'-'+si">
                      <div :title="'PO ' + (s.order_date||'') + (s.order_no ? ' · '+s.order_no : '')">
                        🏭 <b x-text="s.supplier"></b>
                        <span style="color:#065f46;font-weight:700;" x-text="peso(s.unit_cost)"></span>
                        <span style="color:#94a3b8;font-size:10px;" x-text="s.order_date ? '· '+s.order_date : ''"></span>
                      </div>
                    </template>
                  </div>
                </template>
                <template x-if="!(it.suppliers||[]).length">
                  <span style="color:#94a3b8;font-style:italic;">walang supplier</span>
                </template>
              </td>
              <td style="font-size:11px;line-height:1.5;" @click.stop>
                {{-- ✨ Suppliers (quote) — supplier na NAKAHANAP na (existing suppliers table) + sariling presyo/MOQ/link.
                     Hiwalay sa PO column sa kaliwa. CEO lang (data-layer gated din). --}}
                <template x-if="!hasSupplier(it)">
                  <span class="ph-badge-no" style="background:#fee2e2;color:#991b1b;border-color:#fecaca;">walang supplier</span>
                </template>
                <template x-for="q in (it.quotes||[])" :key="'q-'+q.id">
                  <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                    <span>🏷 <b x-text="q.supplier"></b>
                      <span style="color:#065f46;font-weight:700;" x-text="q.price!==null ? peso(q.price) : '—'"></span>
                      <span x-show="q.moq" style="color:#94a3b8;font-size:10px;" x-text="q.moq ? 'MOQ '+q.moq : ''"></span>
                    </span>
                    <template x-if="q.link"><a :href="q.link" target="_blank" rel="noopener" style="font-size:10px;color:#4f46e5;">link</a></template>
                    <button class="ph-btn" style="padding:0 6px;font-size:10px;" title="I-edit" @click.stop="openQuote(it, q)">✎</button>
                    <button class="ph-btn" style="padding:0 6px;font-size:10px;color:#b91c1c;" title="Tanggalin" @click.stop="deleteQuote(it, q)">✕</button>
                  </div>
                </template>
                <template x-if="it.qform">
                  <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;align-items:center;">
                    <select x-model="it.qform.supplier_id" style="font-size:11px;padding:2px;max-width:150px;">
                      <option value="">— supplier —</option>
                      <template x-for="s in supplierList" :key="'s-'+s.id"><option :value="String(s.id)" x-text="s.name"></option></template>
                    </select>
                    <input type="number" step="0.01" min="0" x-model="it.qform.price" placeholder="₱ presyo" style="width:90px;font-size:11px;padding:2px;">
                    <input type="number" min="0" x-model="it.qform.moq" placeholder="MOQ" style="width:64px;font-size:11px;padding:2px;">
                    <input type="text" x-model="it.qform.link" placeholder="link (opsyonal)" style="width:140px;font-size:11px;padding:2px;">
                    <button class="ph-btn" @click.stop="saveQuote(it)" x-text="it.qsaving ? '…' : 'Save'"></button>
                    <button class="ph-btn" @click.stop="it.qform=null">Cancel</button>
                  </div>
                </template>
                <button class="ph-btn" style="margin-top:4px;font-size:10px;" x-show="!it.qform" @click.stop="openQuote(it, null)">+ supplier</button>
              </td>
              @endif
              <td>
                <div class="ph-actions">
                  <input type="file" accept="image/*" :id="'file-'+slug(it.item_name)" style="display:none;"
                         @click.stop @change="onFileChange(it.item_name, $event)">
                  <button class="ph-btn" @click.stop="pickFile(it.item_name)"
                          x-text="savingItem===it.item_name ? '…' : (it.image_url ? 'Palitan' : 'Upload')"></button>
                  <template x-if="it.image_url">
                    <a :href="it.image_url" target="_blank" rel="noopener" @click.stop
                       style="font-size:11px;color:#4f46e5;text-decoration:none;">View</a>
                  </template>
                  <template x-if="it.image_url">
                    <button class="ph-del" @click.stop="deleteItem(it.item_name)"
                            x-text="deletingItem===it.item_name ? '…' : 'Delete'"></button>
                  </template>
                  <span class="ph-saved" x-show="savedItem===it.item_name">✓ Saved</span>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <div class="ph-status" x-show="!loading && filtered().length===0">
      Walang item na tumugma / walang laman sa range na ito.
    </div>

    {{-- 🖼 Photo popup (lightbox) + auto-copy ng image sa clipboard --}}
    <template x-if="photoModal.open">
      <div @click.self="closePhoto()" @keydown.escape.window="closePhoto()"
           style="position:fixed;inset:0;background:rgba(15,23,42,.72);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;">
        <div style="position:relative;background:#fff;border-radius:12px;padding:12px;max-width:min(92vw,900px);max-height:92vh;display:flex;flex-direction:column;gap:8px;box-shadow:0 20px 60px rgba(0,0,0,.4);">
          <button @click="closePhoto()" title="Isara (Esc)"
                  style="position:absolute;top:6px;right:8px;border:0;background:#f1f5f9;border-radius:999px;width:28px;height:28px;cursor:pointer;font-size:16px;line-height:1;">×</button>
          <img :src="photoModal.url" :alt="photoModal.name"
               style="max-width:100%;max-height:74vh;object-fit:contain;border-radius:8px;background:#f8fafc;">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <div style="font-weight:700;font-size:13px;color:#0f172a;" x-text="photoModal.name"></div>
            <span style="font-size:12px;font-weight:700;"
                  :style="photoModal.copied === true ? 'color:#059669' : (photoModal.copied === false ? 'color:#b91c1c' : 'color:#64748b')"
                  x-text="photoModal.msg"></span>
            <span style="flex:1"></span>
            <button class="ph-btn" @click="copyPhoto()">📋 Copy ulit</button>
            <a class="ph-btn" :href="photoModal.url" target="_blank" rel="noopener" style="text-decoration:none;">Buksan sa bagong tab</a>
          </div>
        </div>
      </div>
    </template>
  </div>

  <script>
    function photoList(){
      return {
        startDate: @json($startDate),
        endDate:   @json($endDate),
        focus:     @json($focus),
        items: [],
        loading: true,
        q: '',
        activeItem: null,
        savingItem: null,
        savedItem: null,
        deletingItem: null,
        supplierList: [],   // existing suppliers (id, name) para sa quote dropdown — CEO lang
        photoModal: { open:false, url:'', name:'', copied:null, msg:'' },   // popup + auto-copy
        sortKey: 'default',   // default | photo | item | hold | po | quote
        sortDir: 'asc',

        async init(){
          document.addEventListener('paste', (e) => this.onPaste(e));
          await this.loadAll();
        },

        slug(n){ return String(n).replace(/[^a-z0-9]+/gi, '-'); },
        // "1 x HAND GRIP" → "hand grip" (same normalization ng supply item_key).
        supKey(n){ return String(n||'').replace(/^\s*\d+\s*[x×]\s*/i,'').trim().toLowerCase().replace(/\s+/g,' '); },
        peso(v){ return '₱' + Number(v||0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2}); },

        // ── Photo popup + auto-copy ───────────────────────────────────
        openPhoto(it){
          if (!it || !it.image_url) return;
          this.activeItem = it.item_name;
          this.photoModal = { open:true, url:it.image_url, name:it.item_name, copied:null, msg:'kinokopya…' };
          this.copyPhoto();
        },
        closePhoto(){ this.photoModal.open = false; },
        // Clipboard API: PNG lang ang tinatanggap ng karamihan ng browser → i-convert sa canvas kung JPG/WebP.
        async copyPhoto(){
          const url = this.photoModal.url;
          try {
            if (!navigator.clipboard || typeof ClipboardItem === 'undefined') throw new Error('hindi suportado ng browser');
            const blob = await this.toPngBlob(url);
            await navigator.clipboard.write([ new ClipboardItem({ 'image/png': blob }) ]);
            this.photoModal.copied = true;  this.photoModal.msg = '✓ Na-copy ang image — i-paste na (Ctrl+V)';
          } catch (e) {
            // Fallback: kopyahin man lang ang link ng image
            try {
              await navigator.clipboard.writeText(url);
              this.photoModal.copied = false; this.photoModal.msg = 'Hindi ma-copy ang image (' + e.message + ') — link ang na-copy';
            } catch (e2) {
              this.photoModal.copied = false; this.photoModal.msg = 'Hindi ma-copy (' + e.message + ')';
            }
          }
        },
        async toPngBlob(url){
          const res  = await fetch(url, { cache:'no-store' });
          if (!res.ok) throw new Error('HTTP ' + res.status);
          const blob = await res.blob();
          if (blob.type === 'image/png') return blob;
          const bmp = await createImageBitmap(blob);
          const c = document.createElement('canvas'); c.width = bmp.width; c.height = bmp.height;
          c.getContext('2d').drawImage(bmp, 0, 0);
          return await new Promise((ok, no) => c.toBlob(b => b ? ok(b) : no(new Error('convert failed')), 'image/png'));
        },

        // ── Supplier quotes (CEO) ─────────────────────────────────────
        hasSupplier(it){ return ((it.suppliers||[]).length + (it.quotes||[]).length) > 0; },
        openQuote(it, q){
          it.qform = q ? { id:q.id, supplier_id:String(q.supplier_id), price:(q.price ?? ''), moq:(q.moq ?? ''), link:(q.link ?? '') }
                       : { id:null, supplier_id:'', price:'', moq:'', link:'' };
          this.activeItem = it.item_name;
        },
        async saveQuote(it){
          const f = it.qform; if (!f || !f.supplier_id) { alert('Pumili ng supplier.'); return; }
          it.qsaving = true;
          try {
            const r = await fetch('{{ route('item.quotes.save') }}', { method:'POST',
              headers:{ 'X-CSRF-TOKEN':'{{ csrf_token() }}', 'Accept':'application/json', 'Content-Type':'application/json' },
              body: JSON.stringify({ item_name: it.item_name, supplier_id: f.supplier_id, price: f.price === '' ? null : f.price, moq: f.moq === '' ? null : f.moq, link: f.link || null }) });
            const j = await r.json().catch(()=>({}));
            if (!r.ok || !j.ok) { alert(j.error || (j.message || ('HTTP ' + r.status))); return; }
            it.quotes = j.quotes || []; it.qform = null;
          } catch (e) { alert(e.message); }
          finally { it.qsaving = false; }
        },
        async deleteQuote(it, q){
          if (!confirm('Tanggalin si ' + q.supplier + ' sa ' + it.item_name + '?')) return;
          try {
            const r = await fetch('{{ route('item.quotes.delete') }}', { method:'POST',
              headers:{ 'X-CSRF-TOKEN':'{{ csrf_token() }}', 'Accept':'application/json', 'Content-Type':'application/json' },
              body: JSON.stringify({ id: q.id, item_name: it.item_name }) });
            const j = await r.json().catch(()=>({}));
            if (j.ok) it.quotes = j.quotes || []; else alert(j.error || ('HTTP ' + r.status));
          } catch (e) { alert(e.message); }
        },

        // Buuin ang SAME universe as /item: union ng owner/private running items
        // + jnt/hold>0 items, para sa range. No-image muna sa itaas.
        async loadAll(){
          this.loading = true;
          try {
            const range = this.startDate + ' to ' + this.endDate;
            const [holdJ, opJ, imgJ, supJ, qJ] = await Promise.all([
              fetch('{{ route('item.data') }}?date_range=' + encodeURIComponent(range), {headers:{Accept:'application/json'}}).then(r=>r.json()).catch(()=>({})),
              fetch('{{ route('owner.private.item-summary') }}?start_date=' + this.startDate + '&end_date=' + this.endDate, {headers:{Accept:'application/json'}}).then(r=>r.json()).catch(()=>({})),
              fetch('{{ route('item.images') }}', {headers:{Accept:'application/json'}}).then(r=>r.json()).catch(()=>({})),
              @if($isCEO)
              fetch('{{ route('item.suppliers') }}', {headers:{Accept:'application/json'}}).then(r=>r.json()).catch(()=>({})),
              fetch('{{ route('item.quotes') }}', {headers:{Accept:'application/json'}}).then(r=>r.json()).catch(()=>({})),
              @else
              Promise.resolve({}), // hindi CEO — walang supplier fetch
              Promise.resolve({}),
              @endif
            ]);
            const qMap = (qJ && qJ.quotes) ? qJ.quotes : {};   // item_key → [{id, supplier, price, moq, link}]
            this.supplierList = (qJ && qJ.suppliers) ? qJ.suppliers : [];
            const holdMap = {}; (holdJ.items || []).forEach(it => holdMap[it.item_name] = Number(it.total_hold||0));
            const imgMap  = (imgJ && imgJ.images) ? imgJ.images : {};
            const supMap  = (supJ && supJ.suppliers) ? supJ.suppliers : {}; // item_key → [{supplier, unit_cost, order_date}]
            // Union keyed by lowercased name → display name + hold.
            const uni = {};
            (opJ.rows || []).forEach(r => {
              const n = String(r.item_name||'').trim(); if (!n) return;
              const k = n.toLowerCase(); if (!uni[k]) uni[k] = { name:n, hold:0 };
            });
            for (const n in holdMap) {
              if (!(holdMap[n] > 0)) continue;
              const k = String(n).trim().toLowerCase();
              if (uni[k]) uni[k].hold = holdMap[n];
              else uni[k] = { name:String(n).trim(), hold:holdMap[n] };
            }
            // Fill hold for op items that also have hold (already handled above).
            const list = Object.values(uni).map(u => ({
              item_name: u.name,
              hold: u.hold,
              image_url: imgMap[u.name] || null,
              suppliers: supMap[this.supKey(u.name)] || supMap[String(u.name).trim().toLowerCase().replace(/\s+/g,' ')] || [],
              quotes: qMap[this.supKey(u.name)] || [],
              qform: null,
              qsaving: false,
            }));
            list.sort((a,b) => {
              const ai = a.image_url ? 1 : 0, bi = b.image_url ? 1 : 0;
              if (ai !== bi) return ai - bi;                  // no-image (0) muna
              if (b.hold !== a.hold) return b.hold - a.hold;  // tapos by HOLD desc
              return a.item_name.localeCompare(b.item_name);
            });
            this.items = list;
          } catch (e) { console.error(e); }
          finally {
            this.loading = false;
            if (this.focus) {
              this.activeItem = this.focus;
              this.$nextTick(() => { const el = document.getElementById('card-'+this.slug(this.focus)); if (el) el.scrollIntoView({behavior:'smooth', block:'center'}); });
            }
          }
        },

        filtered(){
          const q = (this.q || '').toLowerCase().trim();
          const list = q ? this.items.filter(it => it.item_name.toLowerCase().includes(q)) : this.items.slice();
          if (this.sortKey === 'default') return list;   // walang photo muna, tapos HOLD desc (gaya ng dati)
          const dir = this.sortDir === 'desc' ? -1 : 1;
          const val = (it) => {
            switch (this.sortKey) {
              case 'photo': return it.image_url ? 1 : 0;
              case 'item':  return it.item_name.toLowerCase();
              case 'hold':  return Number(it.hold || 0);
              case 'po': {   // pinakamababang unit cost sa PO; walang PO = sa dulo lagi
                const c = (it.suppliers || []).map(s => Number(s.unit_cost || 0)).filter(n => n > 0);
                return c.length ? Math.min(...c) : null;
              }
              case 'quote': return (it.suppliers || []).length + (it.quotes || []).length;   // 0 = walang supplier
              default: return 0;
            }
          };
          return list.sort((a, b) => {
            const va = val(a), vb = val(b);
            if (va === null && vb === null) return a.item_name.localeCompare(b.item_name);
            if (va === null) return 1;      // null laging nasa dulo
            if (vb === null) return -1;
            if (va < vb) return -1 * dir;
            if (va > vb) return  1 * dir;
            return (Number(b.hold || 0) - Number(a.hold || 0)) || a.item_name.localeCompare(b.item_name);
          });
        },
        sortBy(k){
          if (this.sortKey === k) {
            // asc → desc → default (balik sa orihinal na ayos)
            if (this.sortDir === (k === 'hold' ? 'desc' : 'asc')) this.sortDir = (k === 'hold' ? 'asc' : 'desc');
            else { this.sortKey = 'default'; this.sortDir = 'asc'; }
          } else {
            this.sortKey = k;
            this.sortDir = (k === 'hold') ? 'desc' : 'asc';   // HOLD: pinakamarami muna
          }
        },
        arrow(k){ return this.sortKey === k ? (this.sortDir === 'asc' ? '▲' : '▼') : ''; },        noImageCount(){ return this.items.filter(it => !it.image_url).length; },

        pickFile(name){
          this.activeItem = name;
          const inp = document.getElementById('file-'+this.slug(name));
          if (inp){ inp.value=''; inp.click(); }
        },
        onFileChange(name, ev){
          const f = ev.target.files && ev.target.files[0];
          ev.target.value = '';
          if (f) this.upload(name, f);
        },
        onPaste(e){
          const cd = e.clipboardData || window.clipboardData;
          if (!cd) return;
          for (const it of (cd.items || [])){
            if (it.kind === 'file' && it.type && it.type.startsWith('image/')){
              const blob = it.getAsFile();
              if (!this.activeItem){ alert('Pumili muna ng item — i-click ang card — bago mag-paste.'); return; }
              if (blob){ e.preventDefault(); this.upload(this.activeItem, blob); return; }
            }
          }
        },

        async upload(name, fileOrBlob){
          this.savingItem = name;
          try{
            const ext  = ((fileOrBlob.type && fileOrBlob.type.split('/')[1]) || 'png').replace('jpeg','jpg');
            const file = (fileOrBlob instanceof File) ? fileOrBlob : new File([fileOrBlob], 'pasted-'+Date.now()+'.'+ext, { type: fileOrBlob.type });
            const fd = new FormData();
            fd.append('item_name', name);
            fd.append('image', file);
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const res = await fetch('{{ route('item.image') }}', {
              method:'POST', headers:{ 'Accept':'application/json', 'X-CSRF-TOKEN': csrf }, body: fd,
            });
            const j = await res.json();
            if (j.ok && j.url){
              const it = this.items.find(x => x.item_name === name);
              if (it) it.image_url = j.url;
              this.savedItem = name;
              setTimeout(() => { if (this.savedItem === name) this.savedItem = null; }, 1600);
            } else {
              alert(j.message || 'Upload failed');
            }
          }catch(e){ alert('Upload error: ' + e.message); }
          finally{ this.savingItem = null; }
        },

        async deleteItem(name){
          if (!confirm('Tanggalin ang photo ng "' + name + '"?')) return;
          this.deletingItem = name;
          try{
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const res = await fetch('{{ route('item.image.delete') }}', {
              method:'POST',
              headers:{ 'Accept':'application/json', 'Content-Type':'application/json', 'X-CSRF-TOKEN': csrf },
              body: JSON.stringify({ item_name: name }),
            });
            const j = await res.json();
            if (j.ok){ const it = this.items.find(x => x.item_name === name); if (it) it.image_url = null; }
            else alert(j.message || 'Delete failed');
          }catch(e){ alert('Delete error: ' + e.message); }
          finally{ this.deletingItem = null; }
        },
      };
    }
  </script>
</x-layout>
