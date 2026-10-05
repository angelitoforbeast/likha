{{-- Fit-to-width ng main table: isang zoom factor sa [data-ow-fit] card para laging kasya sa lapad, walang sideways scroll.
     Isang beses lang i-include, sa toolbar. Plain script (walang Alpine); kapag pumalya, gaya pa rin ng dati ang page. --}}
<!-- fit-to-width-start -->
<button type="button" id="owFitSwitch" aria-pressed="true"
        title="Fit: laging kasya ang buong table sa lapad ng screen (walang sideways scroll). 100%: normal na laki, may scroll."
        style="background:#1e293b;color:#fcd34d;border:1px solid #475569;
               border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;
               cursor:pointer;margin-left:4px;">↔ Fit</button>
<style>
  /* Fit mode: walang sideways scroll; laging may vertical scrollbar para hindi mag-loop ang sukat. */
  .ow-fit-on { overflow-x:hidden !important; overflow-y:scroll !important; }
</style>
<script>
(function(){
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
        disableSwitch(btn);
        return;
      }

      // Kahit anong naka-store na hindi eksaktong 'full' (o blocked ang storage) = Fit.
      var mode = 'fit';
      try { if (window.localStorage.getItem('owFitMode') === 'full') mode = 'full'; } catch (e) {}

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
            card.style.zoom = '';
            label('↔ 100%');
            return;
          }
          box.classList.add('ow-fit-on');
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
          label('↔ Fit ' + Math.round(f * 100) + '%');
        } catch (e) {
          // Pumalya ang sukat: 100% na talaga ang switch (sa memory lang, hindi sinusulat sa storage).
          // Sa susunod na click, babalik sa Fit at susubok ulit.
          box.classList.remove('ow-fit-on');
          card.style.zoom = '';
          mode = 'full';
          label('↔ 100%');
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
        schedule();
      });

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
          attributes: true, attributeFilter: ['style', 'class']
        });
      }

      schedule();
    } catch (e) {
      // Pumalya ang init: hanapin ulit ang button dito para hindi na ito mag-throw.
      try {
        var b = document.getElementById('owFitSwitch');
        if (b) disableSwitch(b);
      } catch (e2) {}
    }
  }

  // Nasa toolbar ang script, bago pa ang table: hintayin ang DOM.
  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', init);
})();
</script>
<!-- fit-to-width-end -->
