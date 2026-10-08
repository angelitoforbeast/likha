{{-- Styles ng suppliers view lang. Bawat selector ay nagsisimula sa .spl-table at gumagamit ng child combinator
     sa mga row / cell, para hindi tamaan ang ibang view o ang mga nested table sa loob ng expanded na page block. --}}
<style>
  /* ── Header: dalawang row ──────────────────────────────────────────────
     Sticky ang bawat th. Eksaktong 22px ang unang row (3 + 15 + 3 + 1px na border) para ang top:22px ng
     pangalawang row ay hindi pumatong o mag-iwan ng siwang kapag nag-scroll pababa. */
  .spl-table > thead > tr.spl-h1 > th { height:22px; padding:3px 10px; font-size:10px; line-height:15px; }
  .spl-table > thead > tr.spl-h2 > th { top:22px; font-size:10.5px; line-height:14px; padding:5px 8px; text-align:left; border-radius:0; }
  .spl-table > thead > tr.spl-h1 > th.spl-grp { color:#a5b4fc; text-align:center; letter-spacing:.12em; border-bottom:1px solid #475569; }
  /* Ang bilog na kanto sa kanan ay para sa totoong huling header ng unang row (hindi sa PO na huli sa pangalawang row). */
  .spl-table > thead > tr.spl-h1 > th:last-of-type { border-radius:0 10px 0 0; }

  /* ── Lapad ng mga column ───────────────────────────────────────────────
     width = min-width = max-width para gumana ang ellipsis sa loob ng nowrap na table at para pareho ang lapad
     sa bawat klase ng row. May inline na min-width ang mga header, kaya !important ang sa kanila. */
  .spl-table > thead > tr > th.spl-c1 { width:284px; min-width:284px !important; max-width:284px; }
  .spl-table > tbody > tr > td.spl-c1 { width:284px; min-width:284px; max-width:284px; }
  .spl-table > tbody > tr.page-col-header > th:first-child { width:284px; min-width:284px !important; max-width:284px; }
  .spl-table > thead > tr > th.spl-c2 { width:150px; min-width:150px !important; max-width:150px; }
  .spl-table > tbody > tr > td.spl-c2 { width:150px; min-width:150px; max-width:150px; white-space:normal; overflow-wrap:anywhere; }
  .spl-table > tbody > tr.page-col-header > th:nth-child(2) { width:150px; min-width:150px !important; max-width:150px; }
  /* Ang mga cell na sakop ang apat na column (placeholder, pulang band, ilalim ng grupo sa page row) ay walang fixed na lapad. */
  .spl-table > tbody > tr > td.spl-sc:not([colspan]) { width:118px; min-width:118px; max-width:118px; }
  .spl-table > tbody > tr > td.spl-sc.spl-po:not([colspan]) { width:104px; min-width:104px; max-width:104px; }
  /* Mahabang pangalan ng page na walang puwang: puputulin sa loob ng cell, hindi lalampas sa katabi. */
  .spl-table > tbody > tr > td.spl-c1 .page-cell-body { overflow-wrap:anywhere; }

  /* ── Compact na item row: 30px na photo + 7px na padding ───────────────── */
  .spl-table > tbody > tr.item-row > td { padding:7px 8px; }
  .spl-table > tbody > tr.item-row > td.spl-c1 { white-space:normal; }
  .spl-table > tbody > tr.item-row > td.spl-c1 .item-sq { width:30px; height:30px; font-size:13px; }
  /* Hanggang tatlong linya lang ang pangalan (buo ito sa title), at hindi nito pinalalapad ang column. */
  .spl-table > tbody > tr.item-row > td.spl-c1 .item-name {
    font-size:12.5px; line-height:1.2; max-width:150px; min-width:0; overflow-wrap:anywhere;
    display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden;
  }
  .spl-table > tbody > tr.item-row > td.spl-c1 .item-hold { font-size:10.5px; padding:1px 8px; white-space:nowrap; }

  /* ── Item column: bilang ng running page + Change / Copy ────────────────
     Nakatago ang Change / Copy hangga't walang pointer o keyboard focus sa row; nafo-focus pa rin kahit nakatago. */
  .spl-table .spl-cnt { font-size:11px; font-weight:600; color:#475569; line-height:1.3; }
  .spl-table .spl-rowact { display:flex; gap:4px; justify-content:center; margin-top:2px; opacity:0; transition:opacity .12s; }
  .spl-table > tbody > tr.item-row:hover .spl-rowact { opacity:1; }
  .spl-table > tbody > tr.item-row:focus-within .spl-rowact { opacity:1; }
  /* Sariling line-height: kung hindi, mamanahin ng mga button ang 1.5 ng page at tataas ang row. */
  .spl-table .spl-rowact .item-photo-btn { line-height:1.2; }
  .spl-table .spl-rowact .item-copy-btn { line-height:1.2; }
  .spl-table .spl-rowact :focus-visible { outline:2px solid #2563eb; outline-offset:1px; }

  /* ── Supplier cell ─────────────────────────────────────────────────────
     Walang padding ang td; nasa .spl-cell ang padding para sakop ng hover nito ang buong lapad ng cell.
     Walang z-index ang td: ang card sa loob nito (position:fixed) ay dapat pumatong sa sticky na header at TOTAL. */
  .spl-table > tbody > tr.item-row > td.spl-sc {
    position:relative; padding:0; text-align:left; font-weight:400; white-space:normal; cursor:default;
  }
  .spl-table .spl-cell { display:block; padding:5px 8px; min-width:0; overflow:hidden; }
  /* Fixed din ang lapad ng laman (lapad ng column bawas ang 1px na border sa kanan), para ang mahabang
     pangalan o presyo ay hindi kailanman magpalapad ng column. */
  .spl-table > tbody > tr > td.spl-sc:not([colspan]) > .spl-cell { width:117px; }
  .spl-table > tbody > tr > td.spl-sc.spl-po:not([colspan]) > .spl-cell { width:103px; }
  .spl-table .spl-q { display:block; min-width:0; line-height:1.25; }
  .spl-table .spl-name {
    display:block; max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    font:inherit; font-size:11.5px; font-weight:700; line-height:1.25; color:#0f172a;
    background:none; border:0; padding:0; margin:0; text-align:left; cursor:pointer;
  }
  /* May "+N" chip sa kanang itaas ng cell: mag-iwan ng puwang para hindi ito matakpan ng pangalan. */
  .spl-table .spl-cell:has(> .spl-more) .spl-name { max-width:calc(100% - 30px); }
  .spl-table .spl-l2 { display:flex; align-items:baseline; gap:5px; min-width:0; overflow:hidden; white-space:nowrap; line-height:1.25; }
  .spl-table .spl-price {
    flex:0 1 auto; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    font-size:12px; font-weight:600; color:#1d4ed8; font-variant-numeric:tabular-nums;
  }
  .spl-table .spl-price.spl-low { font-weight:800; text-decoration:underline; text-decoration-color:#93c5fd; text-underline-offset:2px; text-decoration-thickness:1.5px; }
  /* Walang presyo: abo ang dash. Ang marker ay nauuna sa presyo at hindi nakikita. */
  .spl-table .spl-nil { display:none; }
  .spl-table .spl-nil ~ .spl-price { color:#94a3b8; font-weight:500; }
  /* Kapag hindi kasya ang presyo at MOQ, ang MOQ ang unang pinuputol; buo ang dalawa sa card. */
  .spl-table .spl-moq {
    flex:0 1000 auto; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    font-size:10px; font-weight:500; color:#475569;
  }
  .spl-table .spl-pocost {
    flex:0 1 auto; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    font-size:12px; font-weight:700; color:#065f46; font-variant-numeric:tabular-nums;
  }
  .spl-table .spl-add {
    width:24px; height:24px; border:1.5px dashed #94a3b8; border-radius:6px; background:transparent; color:#64748b;
    font-size:15px; font-weight:700; line-height:1; cursor:pointer; display:flex; align-items:center; justify-content:center; padding:0;
  }
  .spl-table .spl-add:hover { border-color:#93c5fd; border-style:solid; color:#2563eb; background:#eff6ff; }
  .spl-table .spl-empty { color:#cbd5e1; font-size:12px; }
  .spl-table .spl-more {
    position:absolute; right:6px; top:6px; border:0; border-radius:999px; background:#c7d2fe; color:#3730a3;
    font-size:10px; font-weight:800; padding:1px 6px; cursor:pointer; line-height:1.4;
  }
  .spl-table .spl-name:focus-visible { outline:2px solid #2563eb; outline-offset:1px; border-radius:3px; }
  .spl-table .spl-add:focus-visible { outline:2px solid #2563eb; outline-offset:1px; }
  .spl-table .spl-more:focus-visible { outline:2px solid #2563eb; outline-offset:1px; }

  /* Placeholder habang wala pang sagot ang mga listahan: neutral na abo, hindi pula (hindi pa alam kung may supplier). */
  .spl-table > tbody > tr.item-row > td.spl-wait { padding:7px 12px; }
  .spl-table .spl-waittext { color:#94a3b8; font-size:11.5px; font-weight:500; }

  /* Pulang band: !important para hindi ito mapalitan ng hover o ng kulay ng row na walang running page. */
  .spl-table > tbody > tr.item-row > td.spl-nosup { background:#fef2f2 !important; }
  .spl-table .spl-band {
    display:flex; align-items:center; gap:10px; padding:7px 16px; white-space:nowrap;
    color:#b91c1c; font-size:11.5px; font-weight:700;
  }
  .spl-table .spl-band > .item-photo-btn { line-height:1.2; }
  .spl-table .spl-band :focus-visible { outline:2px solid #2563eb; outline-offset:1px; }

  /* ── Ang card (detalye ng quote, listahan, form) ───────────────────────
     Ang puwesto, lapad at z-index ay galing sa inline style na kinukuwenta ng script; itsura lang ang narito. */
  .spl-table .spl-card {
    background:#fff; border:1px solid #cbd5e1; border-radius:8px; box-shadow:0 8px 22px rgba(15,23,42,.2);
    padding:9px 11px; text-align:left; white-space:normal; cursor:default;
    font-size:11px; font-weight:400; line-height:1.4; color:#334155;
    max-height:calc(100vh - 16px); overflow-y:auto;
  }
  .spl-table .spl-card :focus-visible { outline:2px solid #2563eb; outline-offset:1px; }
  .spl-table .spl-card-name { font-size:12.5px; font-weight:800; color:#0f172a; white-space:normal; overflow-wrap:anywhere; }
  .spl-table .spl-card-row { display:flex; flex-wrap:wrap; align-items:baseline; gap:2px 8px; margin-top:2px; font-size:12px; }
  .spl-table .spl-card-sub { color:#475569; font-size:10.5px; overflow-wrap:anywhere; }
  .spl-table .spl-card-act { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin-top:7px; padding-top:6px; border-top:1px solid #e2e8f0; }
  .spl-table .spl-card-act > a { color:#2563eb; font-weight:600; text-decoration:underline; }
  .spl-table .spl-card-list { display:grid; gap:5px; margin-top:4px; }
  .spl-table .spl-card-li { display:flex; align-items:baseline; gap:6px; font-size:11px; }
  .spl-table .spl-card > .spl-card-li { margin-top:4px; }
  .spl-table .spl-card-li > b { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#0f172a; }
  /* Sa loob ng card, buo ang presyo, MOQ at cost: walang putol. */
  .spl-table .spl-card .spl-price { flex:0 0 auto; overflow:visible; }
  .spl-table .spl-card .spl-moq { flex:0 0 auto; overflow:visible; }
  .spl-table .spl-card .spl-pocost { flex:0 0 auto; overflow:visible; }
  .spl-table .spl-card-row > .spl-price { font-size:13px; }
  .spl-table .spl-card-photo { border:0; background:none; padding:0; margin:0; cursor:pointer; line-height:0; border-radius:7px; }
  .spl-table .spl-card-photo > .item-sq { width:44px; height:44px; }
  /* Ang form sa loob ng card: kasya sa 262px — supplier, tapos presyo at MOQ na magkatabi, link, photo, Save / Cancel. */
  .spl-table .spl-form { display:flex; flex-wrap:wrap; gap:5px; align-items:center; font-size:11px; }
  .spl-table .spl-form > select {
    flex:1 1 100%; width:100%; min-width:0; font-size:11px; font-weight:400; color:#0f172a;
    background:#fff; border:1px solid #cbd5e1; border-radius:5px; padding:3px 6px;
  }
  .spl-table .spl-form > input {
    flex:1 1 100%; width:100%; min-width:0; font-size:11px; font-weight:400; color:#0f172a;
    background:#fff; border:1px solid #cbd5e1; border-radius:5px; padding:3px 6px;
  }
  .spl-table .spl-form > input[type=number] { flex:1 1 0; width:0; }
  .spl-table .spl-form > input[type=file] { border:0; border-radius:0; padding:0; background:none; }
  .spl-table .spl-form > .spl-card-act { flex:1 1 100%; margin-top:2px; }

  /* ── RTS / DEL / INT sa isang linya (item row lang) ────────────────────
     Pantay ang hati kahit isa o dalawa lang ang naka-check. "~" at hindi "+": may <template> sa pagitan ng mga value. */
  .spl-table .spl-rdt { display:grid; grid-auto-flow:column; grid-auto-columns:1fr; font-size:11.5px; font-variant-numeric:tabular-nums; line-height:1.3; }
  .spl-table .spl-rdt > span { padding:0 3px; }
  .spl-table .spl-rdt > span ~ span { border-left:1px solid #cbd5e1; }

  /* Touch o makitid na screen: walang hover, kaya laging nakikita ang Change / Copy. */
  @media (hover:none), (max-width:767px) {
    .spl-table .spl-rowact { opacity:1; }
  }

  /* ── Sticky na item column (768px pataas lang) ─────────────────────────
     Kailangang opaque ang bawat sticky cell, kapareho ng kulay ng ibang cell ng row nito; kung hindi, tatagos ang
     mga column na dumadaan sa ilalim. Ang unang shadow (-16px) ay takip sa gilid ng scroll area sa kaliwa;
     ang pangalawa ay ang guhit sa kanang gilid ng column. */
  @media (min-width:768px) {
    .spl-table > thead > tr > th.spl-c1 { position:sticky; left:0; z-index:40; box-shadow:-16px 0 0 #f1f5f9; }
    .spl-table > tbody > tr > td.spl-c1 {
      position:sticky; left:0; z-index:6; background:#fff; box-shadow:-16px 0 0 #f1f5f9, 1px 0 0 #c7d2fe;
    }
    .spl-table > tbody > tr:hover > td.spl-c1 { background:#f8fafc; }
    .spl-table > tbody > tr.item-row > td.spl-c1 { background:#eef2ff; }
    .spl-table > tbody > tr.item-row:hover > td.spl-c1 { background:#e0e7ff; }
    .spl-table > tbody > tr.item-row.item-row-nopage > td.spl-c1 { background:#fff7ed; }
    .spl-table > tbody > tr.item-row.item-row-nopage:hover > td.spl-c1 { background:#ffedd5; }
    /* Naka-expand na page row: puti kahit naka-hover, at buo pa rin ang asul na bar sa kaliwa. */
    .spl-table > tbody.page-section-expanded > tr.page-row-expanded > td.spl-c1 {
      background:#fff; box-shadow:inset 3px 0 0 #2563eb, -16px 0 0 #f1f5f9, 1px 0 0 #c7d2fe;
    }
    /* (Ang row na ine-edit ay may sarili nang opaque na kulay na !important sa buong row, kaya walang rule dito.) */
    /* Ang inuulit na header ng bawat page. */
    .spl-table > tbody > tr.page-col-header > th:first-child {
      position:sticky; left:0; z-index:6; background:#334155; box-shadow:-16px 0 0 #f1f5f9;
    }
    /* TOTAL: sticky na sa baba at may sarili nang opaque na kulay; dito, sticky din sa kaliwa at nasa ibabaw ng ibang cell ng TOTAL. */
    .spl-table > tbody > tr.total-row > td:first-child { left:0; z-index:25; box-shadow:-16px 0 0 #f1f5f9, 1px 0 0 #cbd5e1; }
  }
</style>
