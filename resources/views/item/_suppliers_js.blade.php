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
