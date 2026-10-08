      // ── Suppliers group (suppliers view lang) ─────────────────────────────
      // Hangga't hindi pa sumasagot nang ok ang dalawang listahan (quotes at PO suppliers), hindi puwedeng
      // sabihing "wala pang supplier" ang isang item — kaya may sariling loaded flag ang bawat isa.
      splLoaded: { quotes:false, po:false },
      splFailed: false,
      splReady(){ return this.splLoaded.quotes && this.splLoaded.po; },
      // Isang column kada supplier ng listahan (ayon sa id, may bilang ayon sa puwesto), at ang laman ng cell ng
      // isang supplier para sa isang item. Ang lohika ay nasa ItemTableFit (public/js/item-table-fit.js), kung saan
      // ito nasusubok; dito, ibinibigay lang ang mga listahang na-load na. Walang request at walang paghahambing
      // ng halaga rito: ang server ang nagmamarka ng pinakamura.
      splCols(){ return ItemTableFit.supplierColumns(this.supplierList); },
      splSpan(){ return ItemTableFit.groupSpan(this.splCols().length); },
      splCell(name, supplierId){ return ItemTableFit.supplierCell(this.quotesFor(name), this.suppliersFor(name), supplierId); },
      splNone(name){ return ItemTableFit.noSupplier(this.quotesFor(name), this.suppliersFor(name)); },

      // ── Ang card ng detalye (isa lang ang bukas sa buong table) ────────────
      // cell: ang id ng supplier ng column. mode: 'quote' (ang card ng detalye) | 'form'.
      // pinned = binuksan ng click / tap / keyboard (o may form), kaya hindi ito isinasara ng pag-alis ng pointer.
      splCard: { item:null, cell:null, mode:null, pinned:false, style:'' },
      splAnchor: null,   // ang laman ng cell na may-ari ng card: dito sinusukat ang puwesto
      splOpener: null,   // ang button na nagbukas: dito ibinabalik ang focus pagsara
      splIs(name, cell, mode){ return this.splCard.item === name && this.splCard.cell === cell && this.splCard.mode === mode; },
      // May card pa bang talagang nakikita? (Puwedeng nawala na ang cell nito pagkatapos mag-reload ng rows.)
      splLive(){ return this.splCard.mode !== null && !!this.splAnchor && this.splAnchor.isConnected; },
      splOpen(name, cell, mode, el, pinned){
        // Habang may buhay na form, hindi nagbubukas ang ibang card: mawawala ang tina-type.
        // Ang "+" at ✎ ay may sariling harang sa splForm.
        if (mode !== 'form' && this.splCard.mode === 'form' && this.splLive()) return;
        // Form na nawala na ang cell (hal. nag-reload ang rows): wala nang nakakakita, kaya isara na rin — pero
        // hindi habang may save na hindi pa sumasagot, dahil sa key ng form itinatabi ang sagot.
        if (mode !== 'form' && !this.quoteForm.saving) this.quoteForm.key = null;
        // Kapag ang pinindot ay nasa loob ng card na mapapalitan (hal. ✎), ang unang nagbukas pa rin ang babalikan ng focus.
        if (!pinned) this.splOpener = null;
        else if (!el.closest('.spl-card')) this.splOpener = el;
        this.splAnchor = el.closest('.spl-cell');
        this.splCard = { item:name, cell:cell, mode:mode, pinned:pinned, style:'' };
        this.splPlace();
        // Ulitin kapag nasa DOM na ang card: saka lang alam ang tunay na taas nito (para sa pagbaliktad pataas).
        // Sa form, ilipat ang focus sa unang field: ang ✎ na pinindot ay nawala na kasama ng card nito.
        this.$nextTick(() => {
          this.splPlace();
          const first = mode === 'form' && this.splAnchor ? this.splAnchor.querySelector('.spl-form input') : null;
          if (first) first.focus();
        });
      },
      // Click / tap / Enter / Space sa halaga ng cell: buksan nang naka-pin; ang pangalawang pindot ay nagsasara.
      splToggle(name, cell, mode, el){
        if (this.splCard.pinned && this.splIs(name, cell, mode)) { this.splClose(false); return; }
        this.splOpen(name, cell, mode, el, true);
      },
      // Ang "+" at ✎: buksan ang add / edit form sa card ng cell na pinindutan. Walang ginagawa habang may buhay na
      // form — hindi dapat mawala ang tina-type, kaya Cancel, Esc o Save muna — at habang may save na hindi pa
      // sumasagot, dahil buburahin ng sagot na iyon ang form na kabubukas lang.
      // Ang supplier ng form ay laging ang supplier ng column na pinindutan (cell = id nito), hindi napipili.
      splForm(name, q, cell, el){
        if (this.quoteForm.saving) return;
        if (this.splCard.mode === 'form' && this.splLive()) return;
        this.openQuote(name, q);
        Object.assign(this.quoteForm, ItemTableFit.formPreset(cell, q));
        this.splOpen(name, cell, 'form', el, true);
      },
      // Hover: sa device na may totoong hover lang, at hindi kailanman pumapalit sa naka-pin na card o bukas na form.
      splHover(name, cell, mode, el){
        if (!window.matchMedia('(hover: hover)').matches) return;
        if (this.splCard.pinned && this.splLive()) return;
        this.splOpen(name, cell, mode, el, false);
      },
      splLeave(name, cell){
        if (!this.splCard.pinned && this.splCard.item === name && this.splCard.cell === cell) this.splClose(false);
      },
      splClose(refocus){
        // Ang nagbukas ang babalikan ng focus; kung wala na ito sa page (napalitan ang laman ng cell), ang unang button ng cell.
        let el = this.splOpener;
        if (!el || !el.isConnected) el = this.splAnchor ? this.splAnchor.querySelector('button') : null;
        this.splCard = { item:null, cell:null, mode:null, pinned:false, style:'' };
        this.splAnchor = null;
        this.splOpener = null;
        if (refocus && el && el.isConnected) el.focus();
      },
      // Cancel (at Esc) ng form. Walang ginagawa habang may save na hindi pa sumasagot: sa key ng form itinatabi
      // ang sagot, kaya kapag binura ito nang maaga, mapupunta ang sagot sa maling item.
      splCancel(){
        if (this.quoteForm.saving) return;
        this.quoteForm.key = null;
        this.splClose(true);
      },
      // ✕ ng quote: isara lang ang card kapag talagang may nabura (umikli ang listahan) — hindi kapag umatras
      // sa tanong o pumalya ang bura. Hinihintay muna ang bagong laman ng cell para may mababalikan ang focus.
      // Ang card na pinindutan lang ang isinasara: kapag ibang card o form na ang bukas pagdating ng sagot,
      // hindi iyon ginagalaw (kung hindi, masasara ang binabasa o tina-type ng user sa ibang cell).
      // Nasa loob ng form ang bura, kaya iisang flag ang humaharang sa Save at sa bura habang may hindi pa
      // sumasagot: kung hindi, puwedeng mabura at maisulat ulit ang parehong quote nang sabay.
      async splRemove(name, q){
        if (this.quoteForm.saving) return;
        const n = this.quotesFor(name).length;
        const item = this.splCard.item, cell = this.splCard.cell, mode = this.splCard.mode;
        this.quoteForm.saving = true;
        try { await this.deleteQuote(name, q); } finally { this.quoteForm.saving = false; }
        if (this.quotesFor(name).length === n) return;
        await this.$nextTick();
        if (this.splIs(item, cell, mode)) this.splClose(true);
      },
      // position:fixed para hindi maputol ng scroll area; sa ilalim ng cell, o pataas kapag kulang ang espasyo
      // sa ibaba (hindi kasama ang sticky na TOTAL row), at hindi lalampas sa lapad ng window.
      splPlace(){
        const a = this.splAnchor;
        if (!a || !a.isConnected) return;
        const r = a.getBoundingClientRect();
        const card = a.querySelector('.spl-card');
        const h = card ? card.offsetHeight : 0;
        const total = document.querySelector('tr.total-row');
        const below = window.innerHeight - (total ? total.offsetHeight : 0) - r.bottom;
        let left = r.left;
        if (left > window.innerWidth - 262 - 4) left = window.innerWidth - 262 - 4;
        if (left < 4) left = 4;
        // May 6px na patong sa cell para makalipat ang pointer papasok sa card nang hindi ito nagsasara.
        const vertical = (below < h && r.top > below)
          ? 'bottom:' + Math.round(window.innerHeight - r.top - 6) + 'px;'
          : 'top:' + Math.round(r.bottom - 6) + 'px;';
        this.splCard.style = 'position:fixed;z-index:60;width:262px;left:' + Math.round(left) + 'px;' + vertical;
      },
      // Kapag nag-scroll ang table o nagbago ang laki ng window: ang hover card ay nagsasara; ang naka-pin ay sumusunod sa cell nito.
      splScrolled(){
        if (this.splCard.mode === null) return;
        if (this.splCard.pinned) this.splPlace(); else this.splClose(false);
      },
      // Esc: isara ang card; ang form ay dumadaan sa Cancel. Kapag bukas ang photo popup, ito muna ang isasara ng Esc.
      // Ang focus ay ibinabalik lang kapag naka-pin ang card: ang hover card ay hindi binuksan ng keyboard,
      // kaya hindi nito dapat agawin ang focus mula sa kung saan nagta-type ang user.
      splEsc(){
        if (this.splCard.mode === null || this.photoModal.open) return;
        if (this.splCard.mode === 'form') { this.splCancel(); return; }
        this.splClose(this.splCard.pinned);
      },
      // Click o tap sa labas ng cell na may-ari: isara — maliban kung may bukas na form (para hindi mawala ang tina-type).
      splOutside(e){
        if (this.splCard.mode === null || this.splCard.mode === 'form' || this.photoModal.open) return;
        const cell = this.splAnchor ? this.splAnchor.closest('td') : null;
        if (cell && cell.contains(e.target)) return;
        this.splClose(false);
      },
      // Pagkatapos ng matagumpay na save, binubura ng page ang quoteForm.key: wala nang form, kaya wala na ring card.
      // Ibinabalik ang focus sa cell para hindi maligaw ang keyboard user pagkawala ng form.
      splSync(){
        if (this.splCard.mode === 'form' && this.quoteForm.key === null) this.splClose(true);
      },

      // ── Ang fit: aling mga column ang kasya sa lapad ng kahon ng table ─────────────────────────────────
      // Ang this.cols ay hindi ginagalaw dito (ito pa rin ang lahat ng column na hindi nakatago sa settings, ayon
      // sa naka-save na ayos). Ang table na ito ay umiikot sa fitCols: ang mga column na kasya ngayon. Ang
      // pagpapasya (set, fit, panel) at ang mga lapad ay nasa ItemTableFit, kung saan nasusubok ang mga ito.
      fitSet: 'Sourcing',      // ang set na naka-save sa browser na ito (item_col_set_v1)
      fitPeriod: '1m',         // ang period ng Prof.% column
      fitOps: [],              // ang mga pinili sa panel sa pagbisitang ito: [{ id, on }], ayon sa pagkakasunod. Hindi sine-save.
      fitMsg: '',              // ang dahilan kapag tinanggihan ang pagbabalik ng isang column
      fitPanel: false,
      fitBox: 0, fitWin: 0,    // ang huling sukat ng kahon at ng window na ginamit ng fit
      fitPrevBox: 0, fitAt: 0, // ang sukat bago iyon at kung kailan: para sa bantay laban sa pabalik-balik na sukat
      fitRes: { set:'Sourcing', mode:'scroll', shown:[], away:[], plusN:0, used:0, spare:null, scrolls:true, refused:[], box:0 },
      fitW: { page:168, item:96, supplier:72, cols:{}, table:0 },
      fitCols: [],
      // Lahat ng column na puwedeng ipakita, iisa na ang Prof.% (iisa na rin ang RTS / DEL / INT mula sa initCols).
      fitAll(){ return ItemTableFit.mergeProfPct(this.cols); },
      fitState(ops){
        return { box:this.fitBox, win:this.fitWin, suppliers:this.splSpan(), cols:this.fitAll(), set:this.fitSet, ops:ops };
      },
      // Ang lapad na puwedeng gamitin ng table: ang loob ng scroll area, bawas ang padding nito at ang border ng
      // card. Bawas pa ng 1px: buong numero ang clientWidth, kaya ang kalahating pixel ay hindi dapat magpalabas ng
      // scrollbar sa ilalim.
      fitMeasure(el){
        const cs = getComputedStyle(el);
        const card = el.querySelector('.card');
        const cc = card ? getComputedStyle(card) : null;
        const edge = (parseFloat(cs.paddingLeft) || 0) + (parseFloat(cs.paddingRight) || 0)
          + (cc ? (parseFloat(cc.borderLeftWidth) || 0) + (parseFloat(cc.borderRightWidth) || 0) : 0);
        return Math.max(0, Math.floor(el.clientWidth - edge) - 1);
      },
      // Isang beses sa simula (x-init ng scroll area). Pagkatapos: kapag nagbago ang laki ng scroll area (window,
      // zoom, scrollbar) at kapag dumating ang listahan ng supplier. Hindi ito effect: tinatawag lang ito ng mga
      // pangyayaring iyon, kaya ang sarili nitong sinusulat ay hindi nagpapatakbo nito ulit.
      fitInit(el){
        let stored = null;
        try { stored = localStorage.getItem('item_col_set_v1'); } catch (e) { /* walang storage: Sourcing */ }
        this.fitSet = ItemTableFit.readSet(stored);
        this.fitBox = this.fitMeasure(el);
        this.fitWin = window.innerWidth;
        this.fitRun();
        if (typeof ResizeObserver === 'function') new ResizeObserver(() => this.fitResized(el)).observe(el);
        else window.addEventListener('resize', () => this.fitResized(el));
        this.$watch('supplierList', () => this.fitRun());
      },
      // Ang scroll area ang sinusukat, hindi ang table: ang laki nito ay galing sa window, hindi sa laman nito.
      // Ang nag-iisang paraan para baguhin ito ng fit mismo ay ang paglitaw o pagkawala ng patayong scrollbar
      // (nag-iiba ang taas ng mga row). Dalawang bantay: (1) walang ginagawa kapag pareho ang sukat; (2) kapag
      // bumalik agad ang sukat sa mas malapad na katatapos lang iwan, nananatili ang fit ng mas makitid — kasya
      // iyon sa dalawang sukat, kaya hindi ito puwedeng magpabalik-balik.
      fitResized(el){
        const box = this.fitMeasure(el), win = window.innerWidth, now = Date.now();
        if (box === this.fitBox && win === this.fitWin) return;
        if (win === this.fitWin && box === this.fitPrevBox && box > this.fitBox && now - this.fitAt < 600) return;
        this.fitPrevBox = this.fitBox;
        this.fitBox = box; this.fitWin = win; this.fitAt = now;
        this.fitRun();
      },
      fitRun(){
        const all = this.fitAll();
        const byId = Object.fromEntries(all.map(c => [c.id, c]));
        const res = ItemTableFit.layout(this.fitState(this.fitOps));
        this.fitRes = res;
        this.fitW = ItemTableFit.widths(res, { suppliers:this.splSpan(), cols:all });
        // Ang Prof.% column ay may dalang period, at ang sort nito ay ang dati nang field ng period na iyon.
        this.fitCols = res.shown.filter(id => byId[id]).map(id => {
          const c = byId[id];
          if (id !== 'prof_pct') return c;
          const period = ItemTableFit.pickPeriod(c.members, this.fitPeriod);
          return Object.assign({}, c, { period:period, sort:ItemTableFit.profPct({}, period).sortKey });
        });
      },
      fitLabel(id){
        const c = this.fitAll().find(x => x.id === id);
        return c ? c.label : id;
      },
      // Ang dalawang set: sa browser lang ito naaalala. Walang request, at hindi ginagalaw ang setting sa server.
      fitPick(name){
        this.fitSet = ItemTableFit.readSet(name);
        this.fitOps = []; this.fitMsg = '';
        try { localStorage.setItem('item_col_set_v1', this.fitSet); } catch (e) { /* walang storage: para sa pagbisitang ito lang */ }
        this.fitRun();
      },
      // Panel: itago ang nakikitang column, o ibalik ang wala. Ang pagbabalik ay tinatanggihan kapag hindi kasya
      // (sinasabi ang dalawang numero) at walang ibang gumagalaw. Para sa pagbisitang ito lang; walang request.
      fitToggle(id){
        const ops = this.fitOps.concat([{ id:id, on:!this.fitRes.shown.includes(id) }]);
        const no = ItemTableFit.layout(this.fitState(ops)).refused.find(r => r.id === id);
        if (no) { this.fitMsg = this.fitLabel(id) + ': ' + no.reason; return; }
        this.fitMsg = ''; this.fitOps = ops;
        this.fitRun();
      },
      // Ang period ng Prof.%: pinapalitan lang ang ipinapakitang value. Kapag sa Prof.% nakaayos ang table,
      // sumusunod ang ayos sa bagong period. Walang request.
      fitSetPeriod(key){
        const was = this.fitCols.find(c => c.id === 'prof_pct');
        this.fitPeriod = key;
        this.fitRun();
        const now = this.fitCols.find(c => c.id === 'prof_pct');
        if (was && now && this.sortCol === was.sort) this.sortCol = now.sort;
      },
      fitPct(row, col, kind){ return ItemTableFit.profPct(row, col.period, kind).text; },
      // Ang mga panuntunan ng kulay ng column settings ay nakatali sa apat na catalog id ng Prof.%, hindi sa
      // pinagsamang column: para sa mga iyon, ang column ng aktibong period ang ginagamit sa paghahanap.
      fitRuleCol(col){ return col.id === 'prof_pct' ? { id:ItemTableFit.periodId(col.period) } : col; },
      // Ang format ng pera ng table na ito lang; ang money() at md() ng page ay hindi ginagalaw.
      tmoney(v, total){ return ItemTableFit.tableMoney(v, total === true).text; },
      tmd(v, total){ return (v == null || isNaN(Number(v))) ? '—' : this.tmoney(v, total); },
      tmoneyTitle(v){ return (v == null || isNaN(Number(v))) ? '' : ItemTableFit.tableMoney(v, true).title; },
      // ── Drag ng header: sa mga nakikitang column lang ──────────────────────────────────────────────────
      // Ang ise-save ay ang BUONG ayos: ang mga nakikita lang ang nagpapalitan ng puwesto; ang mga lumipat at ang
      // mga nakatago ay nananatili sa puwesto nila, at walang catalog id na nawawala. Kahati ng ibang page ang
      // setting na ito, kaya hindi puwedeng ang nakikita lang ang ipadala bilang buong ayos.
      fitDrop(e, targetId){
        const shown = this.fitCols.map(c => c.id);
        const from = shown.indexOf(this.dragSrc), to = shown.indexOf(targetId);
        this.dragSrc = this.dragOver = null;
        if (from < 0 || to < 0 || from === to) return;
        shown.splice(to, 0, shown.splice(from, 1)[0]);
        this.fitSaveOrder(shown);
      },
      fitSaveOrder(shown){
        const byId = Object.fromEntries(this.fitAll().map(c => [c.id, c]));
        const cfg = window.__OWNER_PRIVATE_COLS__ || {};
        let saved = Array.isArray(cfg.order) && cfg.order.length ? cfg.order : null;
        if (!saved) { try { saved = JSON.parse(localStorage.getItem('private_col_order_v1')); } catch (e) { saved = null; } }
        const order = ItemTableFit.orderToSave(
          Array.isArray(saved) ? saved : [],
          shown.map(id => (byId[id] && byId[id].members) ? byId[id].members : id),
          this.defaultCols().map(c => c.id));
        // Dito muna (para sumunod agad ang table), tapos sa server. Pareho ang ayos na ibinabalik ng reload.
        window.__OWNER_PRIVATE_COLS__ = Object.assign({}, cfg, { order:order });
        try { localStorage.setItem('private_col_order_v1', JSON.stringify(order)); } catch (e) { /* walang storage */ }
        this.initCols();
        this.fitRun();
        fetch('{{ route('owner.column-settings.save') }}', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this._csrf() },
          body: JSON.stringify({ table: 'owner_private', order: order }),
        }).catch(e => console.warn('column order save failed', e));
      },
