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
