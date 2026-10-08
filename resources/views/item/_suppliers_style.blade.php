{{-- Styles ng suppliers view lang. Bawat selector ay nagsisimula sa .spl-table at gumagamit ng child combinator
     sa mga row / cell, para hindi tamaan ang ibang view o ang mga nested table sa loob ng expanded na page block. --}}
<style>
  /* ── Ang bar ng mga column (sa itaas ng table) ─────────────────────────
     Ang dalawang set, at ang button na nagsasabi kung ilang column ang isang hakbang ang layo. */
  .spl-bar {
    background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:5px 12px; display:flex; flex-wrap:wrap; gap:6px;
    align-items:center; justify-content:flex-end; position:relative; z-index:45;
  }
  .spl-bar-l { font-size:11px; color:#475569; font-weight:700; }
  .spl-seg { display:inline-flex; background:#fff; border:1px solid #cbd5e1; border-radius:6px; padding:2px; gap:2px; }
  .spl-seg-b { border:0; background:transparent; font-size:11px; font-weight:700; color:#475569; padding:3px 10px; border-radius:4px; cursor:pointer; line-height:1.4; }
  .spl-seg-b.spl-seg-on { background:#1e293b; color:#e2e8f0; }
  .spl-more-wrap { position:relative; display:inline-block; }
  .spl-more {
    background:#1e293b; color:#a5b4fc; border:1px solid #475569; border-radius:6px; padding:4px 10px; font-size:12px;
    font-weight:700; cursor:pointer; line-height:1.4; display:inline-flex; align-items:center; gap:5px;
  }
  .spl-more-n { background:#4f46e5; color:#fff; border-radius:999px; padding:0 7px; font-size:11px; font-weight:800; line-height:1.5; }
  .spl-seg-b:focus-visible { outline:2px solid #2563eb; outline-offset:1px; }
  .spl-more:focus-visible { outline:2px solid #2563eb; outline-offset:1px; }
  /* Ang panel: nakadikit sa kanan ng button; sa makitid na screen, hindi lalampas sa lapad ng window. */
  .spl-cpanel {
    position:absolute; right:0; top:calc(100% + 6px); width:372px; max-width:calc(100vw - 24px); max-height:calc(100vh - 140px);
    overflow-y:auto; background:#fff; border:1px solid #cbd5e1; border-radius:10px; box-shadow:0 12px 30px rgba(15,23,42,.25);
    padding:12px 14px; color:#334155; font-size:12px; text-align:left;
  }
  .spl-cp-h { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; }
  .spl-cp-h > b { font-size:13px; color:#0f172a; }
  .spl-cp-x { border:0; background:none; cursor:pointer; color:#64748b; font-size:13px; padding:2px 6px; }
  .spl-cp-meter { font-size:11px; font-weight:700; color:#334155; }
  .spl-cp-msg { margin-top:6px; padding:4px 8px; border-radius:6px; background:#fef3c7; color:#92400e; font-size:11.5px; font-weight:700; }
  .spl-cp-t { margin:10px 0 4px; font-size:11px; font-weight:700; color:#475569; }
  .spl-cp-list { list-style:none; margin:0; padding:0; display:grid; grid-template-columns:1fr 1fr; gap:3px 8px; }
  .spl-cp-i {
    width:100%; display:flex; align-items:center; gap:6px; padding:3px 6px; border:1px solid #e2e8f0; border-radius:6px;
    font-size:11.5px; color:#334155; background:#f8fafc; cursor:pointer; text-align:left;
  }
  .spl-cp-i.spl-cp-on { background:#eef2ff; border-color:#c7d2fe; }
  .spl-cp-n { flex:1; min-width:0; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .spl-cp-w { font-size:11px; color:#64748b; font-variant-numeric:tabular-nums; }
  .spl-cp-f { margin-top:8px; padding-top:6px; border-top:1px solid #e2e8f0; font-size:11px; }
  .spl-cp-f > a { color:#4f46e5; font-weight:700; text-decoration:underline; }
  .spl-cp-x:focus-visible { outline:2px solid #2563eb; outline-offset:1px; }
  .spl-cp-i:focus-visible { outline:2px solid #2563eb; outline-offset:1px; }
  .spl-cp-f > a:focus-visible { outline:2px solid #2563eb; outline-offset:1px; }

  /* ── Header: dalawang row ──────────────────────────────────────────────
     Ang buong thead ang sticky, hindi ang bawat th: dalawa o tatlong linya ang ilang header sa makitid na column,
     kaya hindi tiyak ang taas ng unang row at hindi puwedeng nakasulat ang puwesto ng pangalawa. */
  .spl-table > thead { position:sticky; top:0; z-index:30; }
  .spl-table > thead > tr > th { position:static; }
  .spl-table > thead > tr.spl-h1 > th {
    padding:5px 4px; font-size:11px; line-height:1.25; letter-spacing:.02em; white-space:normal; overflow-wrap:anywhere; overflow:hidden;
  }
  .spl-table > thead > tr.spl-h2 > th { font-size:11px; line-height:1.25; letter-spacing:.02em; text-align:left; border-radius:0; }
  .spl-table > thead > tr.spl-h1 > th.spl-grp { color:#a5b4fc; text-align:center; letter-spacing:.12em; border-bottom:1px solid #475569; padding:3px 6px; }
  /* Ang bilog na kanto sa kanan ay para sa totoong huling header ng unang row (hindi sa huling supplier ng pangalawang row). */
  .spl-table > thead > tr.spl-h1 > th:last-of-type { border-radius:0 10px 0 0; }
  /* Ang switch ng period ng Prof.%: apat na maliit na button sa ilalim ng pangalan ng column. */
  .spl-table .spl-per { display:flex; justify-content:center; gap:1px; margin-top:2px; }
  .spl-table .spl-per-b {
    border:0; background:transparent; color:#94a3b8; font-size:11px; font-weight:700; line-height:1.2; padding:1px 3px;
    border-radius:3px; cursor:pointer; letter-spacing:0;
  }
  .spl-table .spl-per-b.spl-per-on { background:#334155; color:#60a5fa; }
  .spl-table .spl-per-b:focus-visible { outline:2px solid #93c5fd; outline-offset:1px; }

  /* ── Lapad ng mga column ───────────────────────────────────────────────
     Fixed ang layout ng table at ang lapad ng bawat column ay galing sa <colgroup>, na kinukuwenta mula sa sukat
     ng scroll area. Walang lapad na nakasulat dito: ang CSS ay nagpuputol lang ng laman na lumalampas sa cell. */
  .spl-table > tbody > tr > td { overflow:hidden; padding-left:6px; padding-right:6px; }
  .spl-table > tbody > tr.page-expand-row > td { overflow:visible; padding:0; }
  .spl-table > tbody > tr > td.spl-c1 { padding-left:8px; }
  .spl-table > tbody > tr > td.spl-c2 { white-space:normal; overflow-wrap:anywhere; }
  /* Ang sub-header ng bawat supplier: ang mahabang unang salita ay pinuputol sa loob ng header (buo ito sa
     title) at hindi nagpapalapad ng column. */
  .spl-table > thead > tr.spl-h2 > th.spl-sh { padding:4px 6px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  /* Walang supplier sa listahan: ang nag-iisang column ay may link papunta sa Finance → Supply. */
  .spl-table > thead > tr.spl-h2 > th.spl-sh.spl-sh-none { white-space:normal; text-transform:none; letter-spacing:0; }
  .spl-table .spl-sh-none > a { color:#a5b4fc; font-weight:600; text-decoration:underline; }
  .spl-table .spl-sh-none > a:focus-visible { outline:2px solid #93c5fd; outline-offset:1px; }
  /* Walang text na mas maliit sa 11px sa table na ito: ang maliliit na linya ng mga cell na kahati ng ibang view
     (may nakasulat na 9 – 10.5px sa mismong element) ay itinataas dito, sa table na ito lang. Hindi kasama ang
     naka-expand na block ng page (ibang table iyon). */
  .spl-table > tbody > tr:not(.page-expand-row) [style*="font-size:9px"] { font-size:11px !important; }
  .spl-table > tbody > tr:not(.page-expand-row) [style*="font-size:9.5px"] { font-size:11px !important; }
  .spl-table > tbody > tr:not(.page-expand-row) [style*="font-size:10px"] { font-size:11px !important; }
  .spl-table > tbody > tr:not(.page-expand-row) [style*="font-size:10.5px"] { font-size:11px !important; }
  /* Mahabang pangalan ng page na walang puwang: puputulin sa loob ng cell, hindi lalampas sa katabi. */
  .spl-table > tbody > tr > td.spl-c1 .page-cell-body { overflow-wrap:anywhere; }

  /* ── Compact na item row: mga 50px ang taas, 28px na photo ─────────────
     Grid ang item cell: arrow, photo, tapos ang pangalan na may HOLD sa ilalim nito, at ang babala sa kanang dulo
     — para kasya sa makitid na column at pare-pareho ang puwesto ng babala sa bawat row. */
  .spl-table > tbody > tr.item-row > td { padding-top:5px; padding-bottom:5px; height:50px; }
  .spl-table > tbody > tr.item-row > td.spl-c1 { white-space:normal; }
  .spl-table > tbody > tr.item-row > td.spl-c1 .item-cell { display:grid; grid-template-columns:18px 28px minmax(0,1fr) auto; column-gap:6px; row-gap:2px; align-items:center; }
  .spl-table > tbody > tr.item-row > td.spl-c1 .expand-chev { grid-column:1; grid-row:1 / span 2; }
  .spl-table > tbody > tr.item-row > td.spl-c1 .expand-chev-empty { grid-column:1; grid-row:1 / span 2; }
  .spl-table > tbody > tr.item-row > td.spl-c1 .item-sq { grid-column:2; grid-row:1 / span 2; width:28px; height:28px; font-size:13px; }
  /* Hanggang tatlong linya lang ang pangalan (buo ito sa title), at hindi nito pinalalapad ang column. */
  .spl-table > tbody > tr.item-row > td.spl-c1 .item-name {
    grid-column:3; grid-row:1; font-size:12px; line-height:1.15; min-width:0; overflow-wrap:anywhere;
    display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden;
  }
  .spl-table > tbody > tr.item-row > td.spl-c1 .item-hold { grid-column:3; grid-row:2; justify-self:start; font-size:11px; padding:0 7px; line-height:1.35; white-space:nowrap; }

  /* Ang babala ng item na walang kahit isang supplier: maliit na bilog sa kanang dulo ng item cell, sa parehong
     puwesto sa bawat row. Ang ibig sabihin nito ay nasa title at aria-label, hindi sa kulay lang. */
  .spl-table .spl-warn {
    grid-column:4; grid-row:1 / span 2; width:20px; height:20px; border-radius:50%; background:#fee2e2; color:#b91c1c;
    font-size:12px; font-weight:800; line-height:20px; text-align:center; cursor:default;
  }

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
  .spl-table .spl-cell { display:block; padding:5px 6px; min-width:0; overflow:hidden; }
  .spl-table .spl-q { display:block; min-width:0; line-height:1.25; }
  /* Ang halaga ng cell ay button: ito ang nagbubukas ng card sa click, tap o keyboard. */
  .spl-table .spl-val {
    display:block; max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    font:inherit; line-height:1.25; background:none; border:0; padding:0; margin:0; text-align:left; cursor:pointer;
  }
  .spl-table .spl-price {
    font-size:12px; font-weight:600; color:#1d4ed8; font-variant-numeric:tabular-nums;
  }
  .spl-table .spl-price.spl-low { font-weight:800; text-decoration:underline; text-decoration-color:#93c5fd; text-underline-offset:2px; text-decoration-thickness:1.5px; }
  /* Quote na walang presyo: abo ang dash. */
  .spl-table .spl-price.spl-nil { color:#94a3b8; font-weight:500; }
  /* Pangalawang linya ng cell. Kapag hindi kasya, pinuputol; buo ito sa card. */
  .spl-table .spl-moq {
    display:block; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    font-size:11px; font-weight:500; color:#475569; line-height:1.25;
  }
  .spl-table .spl-pocost { font-size:12px; font-weight:700; color:#065f46; font-variant-numeric:tabular-nums; }
  .spl-table .spl-potag {
    display:inline-block; padding:0 4px; border-radius:3px; background:#d1fae5; color:#065f46;
    font-size:11px; font-weight:700; line-height:1.25;
  }
  /* May PO rin ang supplier na may quote: maliit na berdeng tuldok sa kaliwang itaas ng cell. */
  .spl-table .spl-dot { position:absolute; left:1px; top:3px; width:6px; height:6px; border-radius:50%; background:#059669; }
  /* Tahimik na "+" ng blangkong cell. */
  .spl-table .spl-add {
    width:20px; height:20px; border:1px dashed #cbd5e1; border-radius:50%; background:transparent; color:#94a3b8;
    font-size:13px; font-weight:700; line-height:1; cursor:pointer; display:flex; align-items:center; justify-content:center; padding:0;
  }
  .spl-table .spl-add:hover { border-color:#93c5fd; border-style:solid; color:#2563eb; background:#eff6ff; }
  /* Ang ✎ ng may-quote na cell at ang "+" ng PO-only na cell: nasa kanang itaas, nakatago hangga't walang
     pointer o keyboard focus sa cell; nafo-focus pa rin kahit nakatago. */
  .spl-table .spl-edit {
    position:absolute; right:2px; top:2px; width:18px; height:18px; border:1px solid #cbd5e1; border-radius:5px;
    background:#fff; color:#334155; font-size:11px; line-height:1; padding:0; cursor:pointer; opacity:0; transition:opacity .12s;
  }
  .spl-table .spl-add.spl-add-po { position:absolute; right:2px; top:2px; width:18px; height:18px; background:#fff; opacity:0; transition:opacity .12s; }
  .spl-table .spl-cell:hover .spl-edit { opacity:1; }
  .spl-table .spl-cell:focus-within .spl-edit { opacity:1; }
  .spl-table .spl-cell:hover .spl-add-po { opacity:1; }
  .spl-table .spl-cell:focus-within .spl-add-po { opacity:1; }
  .spl-table .spl-val:focus-visible { outline:2px solid #2563eb; outline-offset:1px; border-radius:3px; }
  .spl-table .spl-add:focus-visible { outline:2px solid #2563eb; outline-offset:1px; }
  .spl-table .spl-edit:focus-visible { outline:2px solid #2563eb; outline-offset:1px; }

  /* Placeholder habang wala pang sagot ang mga listahan: neutral na abo, hindi pula (hindi pa alam kung may supplier). */
  .spl-table > tbody > tr.item-row > td.spl-wait { padding:7px 12px; }
  .spl-table .spl-waittext { color:#94a3b8; font-size:11.5px; font-weight:500; }

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
  .spl-table .spl-card-sub { color:#475569; font-size:11px; overflow-wrap:anywhere; }
  .spl-table .spl-card-act { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin-top:7px; padding-top:6px; border-top:1px solid #e2e8f0; }
  .spl-table .spl-card-act > a { color:#2563eb; font-weight:600; text-decoration:underline; }
  /* Ang huling PO ng supplier, sa berde ng PO cost. */
  .spl-table .spl-card-po { color:#065f46; font-weight:600; margin-top:4px; }
  /* Sa loob ng card, buo ang presyo, MOQ at cost: walang putol. */
  .spl-table .spl-card .spl-price { flex:0 0 auto; overflow:visible; }
  .spl-table .spl-card .spl-moq { flex:0 0 auto; overflow:visible; }
  .spl-table .spl-card .spl-pocost { flex:0 0 auto; overflow:visible; }
  .spl-table .spl-card-row > .spl-price { font-size:13px; }
  .spl-table .spl-card-photo { border:0; background:none; padding:0; margin:0; cursor:pointer; line-height:0; border-radius:7px; }
  .spl-table .spl-card-photo > .item-sq { width:44px; height:44px; }
  /* Ang form sa loob ng card: kasya sa 262px — ang supplier (text lang), tapos presyo at MOQ na magkatabi, link, photo, Save / Cancel. */
  .spl-table .spl-form { display:flex; flex-wrap:wrap; gap:5px; align-items:center; font-size:11px; }
  .spl-table .spl-form-sup { flex:1 1 100%; font-size:12.5px; font-weight:800; color:#0f172a; overflow-wrap:anywhere; }
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

  /* Touch o makitid na screen: walang hover, kaya laging nakikita ang Change / Copy, ang ✎ at ang "+" ng
     PO-only na cell; ang halaga ay nag-iiwan ng puwang para sa kanila. */
  @media (hover:none), (max-width:767px) {
    .spl-table .spl-rowact { opacity:1; }
    .spl-table .spl-edit { opacity:1; }
    .spl-table .spl-add-po { opacity:1; }
    .spl-table .spl-val { max-width:calc(100% - 20px); }
  }

  /* ── Sticky na item column (768px hanggang 1279px) ─────────────────────
     Mula 1280px, kasya ang buong table at walang pahalang na scroll, kaya walang kailangang dumikit. Sa ilalim
     noon, nag-i-scroll pahalang ang table gaya ng dati at nakadikit ang item column sa kaliwa.
     Ang scroll area ng page ay may 16px na padding sa magkabilang gilid, at doon sinusukat ang left:0 ng sticky
     cell: sa loob ng padding. Kaya may puwang sa kaliwa ng column na hindi sakop ng kahit anong cell, at doon
     sumisilip ang mga column na nag-i-scroll. Kaya dito, tinatanggal ang padding sa kaliwa: ang sticky cell mismo
     ang nasa gilid ng scroll area, at ang sarili niyang opaque na kulay ang takip — kapareho ng kulay ng ibang
     cell ng row nito. Ang anino sa kanan ay ang guhit sa gilid ng column. */
  @media (min-width:768px) and (max-width:1279px) {
    .spl-scroll { padding-left:0 !important; }
    .spl-table > thead > tr > th.spl-c1 { position:sticky; left:0; z-index:40; }
    .spl-table > tbody > tr > td.spl-c1 {
      position:sticky; left:0; z-index:6; background:#fff; box-shadow:1px 0 0 #c7d2fe;
    }
    .spl-table > tbody > tr:hover > td.spl-c1 { background:#f8fafc; }
    .spl-table > tbody > tr.item-row > td.spl-c1 { background:#eef2ff; }
    .spl-table > tbody > tr.item-row:hover > td.spl-c1 { background:#e0e7ff; }
    .spl-table > tbody > tr.item-row.item-row-nopage > td.spl-c1 { background:#fff7ed; }
    .spl-table > tbody > tr.item-row.item-row-nopage:hover > td.spl-c1 { background:#ffedd5; }
    /* Naka-expand na page row: puti kahit naka-hover, at buo pa rin ang asul na bar sa kaliwa. */
    .spl-table > tbody.page-section-expanded > tr.page-row-expanded > td.spl-c1 {
      background:#fff; box-shadow:inset 3px 0 0 #2563eb, 1px 0 0 #c7d2fe;
    }
    /* Ang row na ine-edit: kapareho ng kulay na ibinibigay ng page sa buong row. */
    .spl-table > tbody > tr.editing-row > td.spl-c1 { background:#eff6ff; }
    /* Ang inuulit na header ng bawat page. */
    .spl-table > tbody > tr.page-col-header > th:first-child {
      position:sticky; left:0; z-index:6; background:#334155;
    }
    /* TOTAL: sticky na sa baba; dito, sticky din sa kaliwa, nasa ibabaw ng ibang cell ng TOTAL, at may sariling opaque na kulay. */
    .spl-table > tbody > tr.total-row > td:first-child { left:0; z-index:25; background:#f1f5f9; box-shadow:1px 0 0 #cbd5e1; }
  }

  /* ── Mula 1280px: kasya ang table sa kahon ─────────────────────────────
     Laging nakalaan ang puwang ng patayong scrollbar: kung hindi, ang paglitaw o pagkawala nito (dahil nag-iba ang
     taas ng mga row) ay magbabago sa lapad na sinusukat ng fit. */
  @media (min-width:1280px) {
    .spl-scroll { scrollbar-gutter:stable; }
  }
</style>
