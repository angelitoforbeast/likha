{{-- Fit-to-width ng main table: isang zoom factor sa [data-ow-fit] card para laging kasya sa lapad, walang sideways scroll.
     Isang beses lang i-include, sa toolbar. Plain script (walang Alpine); kapag pumalya, gaya pa rin ng dati ang page. --}}
<!-- fit-to-width-start -->
<button type="button" id="owFitSwitch" aria-pressed="true"
        title="Compact: siksik na layout, laging kasya sa lapad (walang sideways scroll); zoom lang kapag kulang pa. 100%: dating layout, normal na laki, may scroll."
        style="background:#1e293b;color:#fcd34d;border:1px solid #475569;
               border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;
               cursor:pointer;margin-left:4px;">↔ Compact</button>
@if(!empty($effectiveIsCEO))
{{-- Actions view (CEO lang): PAGE, ITEM, CPP, Prof.%, HOLD at ang mga text column lang. Sa browser lang naka-save; hindi ginagalaw ang column settings. --}}
<button type="button" id="owActionsBtn" aria-pressed="false"
        title="Actions view: ipakita lang ang PAGE, ITEM, CPP, Prof.%, HOLD, Action, Claude at CEO columns. Pindutin ulit para ibalik ang dating columns."
        style="background:#1e293b;color:#f9a8d4;border:1px solid #475569;
               border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;
               cursor:pointer;margin-left:4px;">☰ Actions</button>
@endif
<style>
  /* Fit mode: walang sideways scroll; laging may vertical scrollbar para hindi mag-loop ang sukat. */
  .ow-fit-on { overflow-x:hidden !important; overflow-y:scroll !important; }

  /* Compact layout (013): lahat nasa ilalim ng .ow-compact; ang data-col ay nilalagay lang ng Alpine kapag compact. */
  .ow-compact > table > tbody > tr:not(.page-expand-row) > td { padding:2px 2px; font-size:11px; }
  .ow-compact > table > thead > tr > th,
  .ow-compact > table > tbody > tr.page-col-header > th { padding:3px 2px; font-size:9.5px; letter-spacing:0; line-height:1.15; white-space:normal; }
  .ow-compact > table > thead > tr > th[data-col],
  .ow-compact > table > tbody > tr.page-col-header > th[data-col] { min-width:0 !important; }
  /* Fixed-width column = sukat ng laman, hindi kumukuha ng sobrang lapad; ang sobra ay sa text columns lang. */
  .ow-compact > table > thead > tr > th[data-col],
  .ow-compact > table > tbody > tr.page-col-header > th[data-col] { width:1px; }
  /* Page at Item: tiyak na lapad (naiiwan ang inline min-width ng Page; ino-override ang 160 ng Item) */
  .ow-compact > table > thead > tr > th:nth-child(1),
  .ow-compact > table > tbody > tr.page-col-header > th:nth-child(1) { width:115px; }
  .ow-compact > table > thead > tr > th:nth-child(2),
  .ow-compact > table > tbody > tr.page-col-header > th:nth-child(2) { width:115px; min-width:0 !important; }
  /* Promo: puwedeng mag-wrap */
  .ow-compact > table > thead > tr > th[data-col="promo"],
  .ow-compact > table > tbody > tr.page-col-header > th[data-col="promo"] { width:100px !important; min-width:0 !important; }
  .ow-compact > table > tbody > tr > td[data-col="promo"] { white-space:normal; overflow-wrap:anywhere; }
  /* RTS block: dikit ang tatlong linya (ang 9px na label cells ay hindi ginagalaw; sa table lang ang font-size) */
  .ow-compact > table > tbody > tr > td[data-col="jnt_rdt"] > table td { padding:0 2px !important; line-height:1.2; }
  .ow-compact > table > tbody > tr > td[data-col="jnt_rdt"] > table { font-size:10px !important; }
  /* Set RTS%: dalawang linya lang -- porsyento sa una; "from <date>" at ang note magkatabi sa pangalawa, putol na may "..." (buo sa title). */
  .ow-compact > table > tbody > tr > td[data-col="rts_set"] > span > div > template + div { display:block; max-width:64px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; line-height:1.2; }
  .ow-compact > table > tbody > tr > td[data-col="rts_set"] > span > div > template + div > span { display:block; }
  .ow-compact > table > tbody > tr > td[data-col="rts_set"] > span > div > template + div > div { display:inline !important; white-space:nowrap !important; max-width:none !important; margin:0 3px 0 0 !important; }
  /* Item value: isang linya ang bawat note, putol; ang halaga mismo ay hindi pinuputol. */
  .ow-compact > table > tbody > tr > td[data-col="item_val"] > span > div > template + div > div { max-width:44px !important; white-space:nowrap !important; overflow:hidden; text-overflow:ellipsis; line-height:1.15; margin-top:0 !important; }
  /* Pencil icons: maliit na gap at padding sa number cells. */
  .ow-compact > table > tbody > tr > td:is([data-col="rts_set"],[data-col="promo"],[data-col="item_val"],[data-col="item_val_ceo"]) > span { gap:2px !important; }
  .ow-compact > table > tbody > tr > td:is([data-col="rts_set"],[data-col="promo"],[data-col="item_val"],[data-col="item_val_ceo"]) > span > button.cell-edit-icon { padding:1px 2px; }
  /* Page cell: makitid ang lapad; ang mga note ay isang linya na may "...". */
  .ow-compact > table > tbody.page-row-tbody > tr:not(.page-expand-row) > td:nth-child(1) .page-cell-body { max-width:82px; overflow:hidden; }
  .ow-compact > table > tbody.page-row-tbody > tr:not(.page-expand-row) > td:nth-child(1) .page-cell-body > :is(a,span) { line-height:1.2 !important; overflow-wrap:anywhere; }
  .ow-compact > table > tbody.page-row-tbody > tr:not(.page-expand-row) > td:nth-child(1) .page-cell-body div { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  /* Item cell: siksik ang mga linya (pangalan at secondary items); ellipsis sa nowrap na secondary. */
  .ow-compact > table > tbody.page-row-tbody > tr:not(.page-expand-row) > td:nth-child(2) > div { line-height:1.2 !important; max-width:110px; overflow:hidden; text-overflow:ellipsis; overflow-wrap:anywhere; }
  /* Pangalan ng item: hanggang 2 linya, buo sa title. */
  .ow-compact > table > tbody.page-row-tbody > tr:not(.page-expand-row) > td:nth-child(2) > div:first-child { display:-webkit-box; -webkit-box-orient:vertical; -webkit-line-clamp:2; line-clamp:2; }
  /* Text columns: sila ang kumukuha ng natitirang lapad; hanggang 3 linya */
  .ow-compact > table > thead > tr > th:is([data-col="action"],[data-col="claude_action"],[data-col="ceo_action"]),
  .ow-compact > table > tbody > tr.page-col-header > th:is([data-col="action"],[data-col="claude_action"],[data-col="ceo_action"]) { width:auto !important; min-width:135px !important; }
  .ow-compact > table > thead > tr > th:is([data-col="claude_reason"],[data-col="ceo_reason"]),
  .ow-compact > table > tbody > tr.page-col-header > th:is([data-col="claude_reason"],[data-col="ceo_reason"]) { width:auto !important; min-width:155px !important; }
  .ow-compact > table > tbody > tr > td:is([data-col="action"],[data-col="claude_action"],[data-col="claude_reason"],[data-col="ceo_action"],[data-col="ceo_reason"]) { white-space:normal; }
  .ow-compact > table > tbody > tr > td:is([data-col="action"],[data-col="claude_action"],[data-col="claude_reason"],[data-col="ceo_action"],[data-col="ceo_reason"]) > span > div > div[title] > div:first-child { max-width:none !important; white-space:normal !important; overflow-wrap:anywhere; line-height:1.2 !important; }
  .ow-compact > table > tbody > tr > td:is([data-col="action"],[data-col="claude_action"],[data-col="claude_reason"],[data-col="ceo_action"],[data-col="ceo_reason"]) > span > div > div[title] > div:first-child[style*="ellipsis"] { display:-webkit-box !important; -webkit-box-orient:vertical; -webkit-line-clamp:3; line-clamp:3; overflow:hidden; }
  /* author/time line: isang linya pa rin, katabi ang "more" */
  .ow-compact > table > tbody > tr > td:is([data-col="action"],[data-col="claude_action"],[data-col="claude_reason"],[data-col="ceo_action"],[data-col="ceo_reason"]) > span > div > div[title] > template + div { display:inline-block; width:0; min-width:calc(100% - 46px); max-width:none !important; line-height:1.1; vertical-align:bottom; }
  /* width:0 + min-width na % para hindi palaparin ng mahabang pangalan/oras ang column (ang % max-width ay walang bisa sa sukat ng table cell). Nowrap + ellipsis pa rin galing sa inline style. */
  /* Pencil chip: mas maliit ang padding. */
  .ow-compact > table > tbody > tr > td:is([data-col="action"],[data-col="claude_action"],[data-col="claude_reason"],[data-col="ceo_action"],[data-col="ceo_reason"]) > span > button { padding:2px 3px !important; }

  /* Actions view (CEO lang): itago ang lahat ng column maliban sa PAGE, ITEM, CPP, Prof.%, HOLD at text columns. */
  .ow-actions > table > thead > tr > th[data-col]:not([data-col="cpp"]):not([data-col="proj_pct"]):not([data-col="proj_pct_1d"]):not([data-col="proj_pct_3d"]):not([data-col="proj_pct_7d"]):not([data-col="hold"]):not([data-col="action"]):not([data-col="claude_action"]):not([data-col="claude_reason"]):not([data-col="ceo_action"]):not([data-col="ceo_reason"]),
  .ow-actions > table > tbody > tr > th[data-col]:not([data-col="cpp"]):not([data-col="proj_pct"]):not([data-col="proj_pct_1d"]):not([data-col="proj_pct_3d"]):not([data-col="proj_pct_7d"]):not([data-col="hold"]):not([data-col="action"]):not([data-col="claude_action"]):not([data-col="claude_reason"]):not([data-col="ceo_action"]):not([data-col="ceo_reason"]),
  .ow-actions > table > tbody > tr > td[data-col]:not([data-col="cpp"]):not([data-col="proj_pct"]):not([data-col="proj_pct_1d"]):not([data-col="proj_pct_3d"]):not([data-col="proj_pct_7d"]):not([data-col="hold"]):not([data-col="action"]):not([data-col="claude_action"]):not([data-col="claude_reason"]):not([data-col="ceo_action"]):not([data-col="ceo_reason"]) { display:none; }
</style>
<script>
(function(){
  // Una sa lahat, habang nagpa-parse pa ang page (bago mag-start ang Alpine): compact ba? Blocked ang storage = compact.
  try {
    var stored = null;
    try { stored = window.localStorage.getItem('owFitMode'); } catch (e) {}
    window.__owCompact = (stored !== 'full');
  } catch (e) {}

  // Sabihan ang Alpine (privateUI) kung compact o hindi.
  function announce(on){
    try {
      window.__owCompact = !!on;
      window.dispatchEvent(new CustomEvent('ow-fit-mode', {detail: !!on}));
    } catch (e) {}
  }

  // Dito lang kino-compute ang factor. Pure: lapad ng container at natural na lapad ng table lang ang input.
  // fit-factor-start
  function owFitFactor(containerWidth, naturalWidth){
    var c = Number(containerWidth), n = Number(naturalWidth);
    if (!isFinite(c) || !isFinite(n) || c <= 0 || n <= 0) return 1;
    var f = Math.floor((c / n) * 1000) / 1000;   // pababa ang round para hindi lumampas kahit 1px
    if (f >= 1) return 1;
    return f > 0 ? f : 0.001;
  }
  // fit-factor-end

  // Hindi magagamit ang Fit: 100% ang label, naka-disable at mukhang naka-disable.
  function disableSwitch(b){
    b.textContent = '↔ 100%';
    b.setAttribute('aria-pressed', 'false');
    b.disabled = true;
    b.style.cursor = 'default';
    b.style.opacity = '.5';
  }

  // Dating layout: naka-disable din ang Actions button (kung meron).
  function disableAll(b){
    announce(false);
    disableSwitch(b);
    try {
      var a = document.getElementById('owActionsBtn');
      if (a) {
        a.setAttribute('aria-pressed', 'false');
        a.disabled = true;
        a.style.cursor = 'default';
        a.style.opacity = '.5';
      }
    } catch (e) {}
  }

  function init(){
    try {
      var btn = document.getElementById('owFitSwitch');
      if (!btn) return;
      var card = document.querySelector('[data-ow-fit]');
      var box = card ? card.parentElement : null;          // ang #scroll container
      var table = card ? card.querySelector('table') : null;
      var ok = !!(window.CSS && CSS.supports && CSS.supports('zoom', '0.5'));
      if (!card || !box || !table || !ok) {
        // Walang table o walang zoom support: 100% lang, naka-disable ang switch.
        disableAll(btn);
        return;
      }

      // Kahit anong naka-store na hindi eksaktong 'full' (o blocked ang storage) = Fit.
      var mode = 'fit';
      try { if (window.localStorage.getItem('owFitMode') === 'full') mode = 'full'; } catch (e) {}

      // Actions view (CEO lang, kung may button): '1' lang ang on. Walang ibang key na sinusulat.
      var actionsBtn = document.getElementById('owActionsBtn');
      var actionsOn = false;
      try { actionsOn = (window.localStorage.getItem('owActionsView') === '1'); } catch (e) {}

      function paintActions(){
        if (!actionsBtn) return;
        var fit = (mode === 'fit');
        var pressed = actionsOn && fit;
        actionsBtn.setAttribute('aria-pressed', pressed ? 'true' : 'false');
        actionsBtn.disabled = !fit;
        actionsBtn.style.opacity = fit ? '1' : '.5';
        actionsBtn.style.cursor = fit ? 'pointer' : 'default';
        actionsBtn.style.background = pressed ? '#f9a8d4' : '#1e293b';
        actionsBtn.style.color = pressed ? '#1e293b' : '#f9a8d4';
      }

      var queued = false, lastLabel = null;

      function label(text){
        if (text === lastLabel) return;
        lastLabel = text;
        btn.textContent = text;
        btn.setAttribute('aria-pressed', mode === 'fit' ? 'true' : 'false');
      }

      // Sa loob lang ng requestAnimationFrame tumatakbo, tuloy-tuloy, kaya walang napi-paint sa pagitan.
      function measure(){
        queued = false;
        try {
          if (mode !== 'fit') {
            box.classList.remove('ow-fit-on');
            card.classList.remove('ow-compact', 'ow-actions');
            card.style.zoom = '';
            label('↔ 100%');
            return;
          }
          box.classList.add('ow-fit-on');
          // Compact muna bago sukatin: masikip na layout muna, zoom lang para sa kulang pa.
          card.classList.add('ow-compact');
          card.classList.toggle('ow-actions', actionsOn && !!actionsBtn);
          // Laging sinusukat sa zoom 1, kaya hindi nakadepende sa dating factor (walang loop).
          card.style.zoom = '1';
          var cs = window.getComputedStyle(box);
          var container = box.clientWidth - (parseFloat(cs.paddingLeft) || 0) - (parseFloat(cs.paddingRight) || 0);
          var natural = Math.max(card.offsetWidth, table.offsetWidth);
          var f = owFitFactor(container, natural);
          card.style.zoom = (f < 1 ? String(f) : '');
          // fit-verify-start
          // Hindi eksaktong linear ang pagliit (1px borders, maliit na text): hanggang 3 beses na pagwawasto, tapos tigil.
          for (var i = 0; i < 3 && f < 1; i++) {
            var cw = card.offsetWidth, tw = table.offsetWidth;   // parehong nasa zoom ng card, kaya ratio lang ang mahalaga
            if (!(cw > 0) || tw <= cw) break;
            natural = (container / f) * tw / cw;   // mula sa lapad na talagang in-apply, hindi sa unang tantiya
            f = owFitFactor(container, natural);
            card.style.zoom = String(f);
          }
          // fit-verify-end
          label('↔ Compact ' + Math.round(f * 100) + '%');
        } catch (e) {
          // Pumalya ang sukat: 100% na talaga ang switch (sa memory lang, hindi sinusulat sa storage).
          // Sa susunod na click, babalik sa Fit at susubok ulit.
          box.classList.remove('ow-fit-on');
          card.classList.remove('ow-compact', 'ow-actions');
          card.style.zoom = '';
          mode = 'full';
          announce(false);
          label('↔ 100%');
          paintActions();
        }
      }

      // Maraming tawag sa isang frame = isang measure() lang.
      function schedule(){
        if (queued) return;
        queued = true;
        window.requestAnimationFrame(measure);
      }

      btn.addEventListener('click', function(){
        mode = (mode === 'fit' ? 'full' : 'fit');
        try { window.localStorage.setItem('owFitMode', mode); } catch (e) {}
        announce(mode === 'fit');
        paintActions();
        schedule();
      });

      if (actionsBtn) {
        actionsBtn.addEventListener('click', function(){
          actionsOn = !actionsOn;
          try { window.localStorage.setItem('owActionsView', actionsOn ? '1' : '0'); } catch (e) {}
          paintActions();
          schedule();
        });
      }

      // resize: kasama na ang page zoom ng browser.
      window.addEventListener('resize', schedule);
      if (window.ResizeObserver) {
        var ro = new ResizeObserver(schedule);
        ro.observe(box);
        ro.observe(table);
      }
      // Rows, x-show ng columns, expand/collapse at x-text ng Alpine: lahat dumadaan dito.
      // Sa card at box lang sumusulat ang helper (wala sa loob ng table), kaya hindi nito nati-trigger ang sarili.
      if (window.MutationObserver) {
        new MutationObserver(schedule).observe(table, {
          childList: true, subtree: true, characterData: true,
          attributes: true, attributeFilter: ['style', 'class', 'data-col']
        });
      }

      announce(mode === 'fit');
      paintActions();
      schedule();
    } catch (e) {
      // Pumalya ang init: hanapin ulit ang button dito para hindi na ito mag-throw.
      try {
        var b = document.getElementById('owFitSwitch');
        if (b) disableAll(b);
      } catch (e2) {}
    }
  }

  // Nasa toolbar ang script, bago pa ang table: hintayin ang DOM.
  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', init);
})();
</script>
<!-- fit-to-width-end -->
