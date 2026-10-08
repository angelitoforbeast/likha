      // ── Suppliers group (suppliers view lang) ─────────────────────────────
      // Hangga't hindi pa sumasagot nang ok ang dalawang listahan (quotes at PO suppliers), hindi puwedeng
      // sabihing "wala pang supplier" ang isang item — kaya may sariling loaded flag ang bawat isa.
      splLoaded: { quotes:false, po:false },
      splFailed: false,
      splReady(){ return this.splLoaded.quotes && this.splLoaded.po; },
      // Ang server na ang nag-ayos (pinakamura muna) at nagmarka ng pinakamura; dito, kunin lang ang unang tatlo at bilangin ang sobra.
      splTop3(name){ return this.quotesFor(name).slice(0, 3); },
      splRest(name){ return Math.max(0, this.quotesFor(name).length - 3); },
      splNone(name){ return !this.quotesFor(name).length && !this.suppliersFor(name).length; },

      // ── Ang card ng detalye (isa lang ang bukas sa buong table) ────────────
      // cell: 0|1|2 (quote), 'more', 'po', 'band'. mode: 'quote' | 'list' | 'po' | 'form'.
      // pinned = binuksan ng click / tap / keyboard (o may form), kaya hindi ito isinasara ng pag-alis ng pointer.
      splCard: { item:null, cell:null, mode:null, pinned:false, style:'' },
      splAnchor: null,   // ang laman ng cell na may-ari ng card: dito sinusukat ang puwesto
      splOpener: null,   // ang button na nagbukas: dito ibinabalik ang focus pagsara
      splIs(name, cell, mode){ return this.splCard.item === name && this.splCard.cell === cell && this.splCard.mode === mode; },
      // May card pa bang talagang nakikita? (Puwedeng nawala na ang cell nito pagkatapos mag-reload ng rows.)
      splLive(){ return this.splCard.mode !== null && !!this.splAnchor && this.splAnchor.isConnected; },
      splOpen(name, cell, mode, el, pinned){
        // Habang may buhay na form, hindi nagbubukas ang ibang card (pangalan, "+N", PO): mawawala ang tina-type.
        // Ang "+" at ✎ lang ang pumapalit sa form, dahil form din ang binubuksan nila.
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
          const first = mode === 'form' && this.splAnchor ? this.splAnchor.querySelector('.spl-form select') : null;
          if (first) first.focus();
        });
      },
      // Click / tap / Enter / Space sa pangalan o sa "+N": buksan nang naka-pin; ang pangalawang pindot ay nagsasara.
      splToggle(name, cell, mode, el){
        if (this.splCard.pinned && this.splIs(name, cell, mode)) { this.splClose(false); return; }
        this.splOpen(name, cell, mode, el, true);
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
      // Kapag may form na binuksan habang naghihintay, hindi iyon ginagalaw.
      async splRemove(name, q){
        const n = this.quotesFor(name).length;
        await this.deleteQuote(name, q);
        if (this.quotesFor(name).length === n) return;
        await this.$nextTick();
        if (this.splCard.mode !== 'form') this.splClose(true);
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
