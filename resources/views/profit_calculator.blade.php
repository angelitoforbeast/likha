<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Profit Calculator</title>
  <style>
    html, body {
      margin: 0;
      padding: 0;
      min-height: 100%;
      font-family: Arial, sans-serif;
      background-color: #f4f4f4;
    }

    body {
      display: flex;
      justify-content: center;
      align-items: flex-start;
      padding: 30px 16px;
      box-sizing: border-box;
    }

    .container {
      width: 520px;
      max-width: 100%;
      padding: 18px 22px;
      background: white;
      border: 1px solid #ccc;
      border-radius: 10px;
      box-sizing: border-box;
      font-size: 14px;
    }

    h2 {
      text-align: center;
      font-size: 18px;
      margin: 0 0 4px 0;
    }

    .subtitle {
      text-align: center;
      font-size: 11px;
      color: #6b7280;
      margin-bottom: 14px;
    }

    label {
      margin-top: 6px;
      font-weight: bold;
      display: block;
      font-size: 12px;
      color: #374151;
    }

    label .hint {
      font-weight: normal;
      color: #94a3b8;
      font-size: 11px;
    }

    input {
      width: 100%;
      padding: 6px 8px;
      margin-bottom: 6px;
      font-size: 14px;
      box-sizing: border-box;
      border: 1px solid #cbd5e1;
      border-radius: 4px;
    }

    input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37,99,235,.15); }

    .row {
      display: flex;
      justify-content: space-between;
      gap: 10px;
    }

    .row .column {
      flex: 1;
    }

    button {
      padding: 10px;
      width: 100%;
      background: #007bff;
      color: white;
      border: none;
      border-radius: 4px;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      margin-top: 6px;
    }

    button:hover { background: #0056b3; }

    .reset-row { margin-top: 6px; text-align: right; }
    .reset-link { font-size: 11px; color: #64748b; cursor: pointer; text-decoration: underline; background: none; border: none; padding: 0; width: auto; display: inline-block; }
    .reset-link:hover { color: #0f172a; background: none; }

    .result {
      margin-top: 12px;
      padding: 12px;
      background: #f9f9f9;
      border: 1px solid #ccc;
      border-radius: 6px;
      font-size: 13px;
      line-height: 1.7;
    }

    .result strong { color: #0f172a; }
    .result .pos { color: #16a34a; font-weight: 700; }
    .result .neg { color: #dc2626; font-weight: 700; }

    .error { color: red; }

    .nav-row { width: 520px; max-width: 100%; margin-bottom: 8px; }
    .nav-row a { font-size: 12px; color: #2563eb; text-decoration: none; }
    .nav-row a:hover { text-decoration: underline; }
  </style>
</head>
<body>
  <div>
    <div class="nav-row">
      <a href="{{ url('/owner/private') }}">← Back to /owner/private</a>
    </div>

    <div class="container">
      <h2>Profit Calculator</h2>
      <div class="subtitle">Quick what-if tool · standalone (walang DB save)</div>

      {{-- 3-input row: ad_spend = orders × cpp.
           Fill any 2, the 3rd auto-derives on-input.
           Track lastEdited so editing one of the derived ones reassigns
           which field is the "blank" target. --}}
      <div class="row">
        <div class="column">
          <label>Ad Spend (₱)</label>
          <input type="number" step="0.01" id="adSpend" data-tri-input>
        </div>
        <div class="column">
          <label>Number of Orders</label>
          <input type="number" step="0.01" id="orders" data-tri-input>
        </div>
        <div class="column">
          <label>CPP</label>
          <input type="number" step="0.01" id="cpp" data-tri-input>
        </div>
      </div>
      <div class="hint" id="triHint" style="margin-top:-2px;margin-bottom:6px;font-size:11px;color:#94a3b8;">
        Fill any 2 — the 3rd auto-fills (formula: <code>ad_spend = orders × cpp</code>).
      </div>

      {{-- Proceed ↔ Ship Rate: bidirectional auto-derive via
             ship_rate = proceed / orders × 100
           Orders is independent — does NOT react to changes here. But pag
           nagbago Orders, yung derived (non-recently-edited) ng dalawa
           ay nire-recompute so they stay in sync. --}}
      <div class="row">
        <div class="column">
          <label>Proceed <span class="hint">count of orders that shipped</span></label>
          <input type="number" step="0.01" id="proceed" data-pair-input>
        </div>
        <div class="column">
          <label>Ship Rate (%) <span class="hint">= proceed / orders × 100</span></label>
          <input type="number" step="0.01" id="shipRate" value="95" data-pair-input>
        </div>
        <div class="column">
          <label>RTS (%) <span class="hint">shipped → returned</span></label>
          <input type="number" step="0.01" id="rts">
        </div>
      </div>

      <div class="row">
        <div class="column">
          <label>Selling Price (₱)</label>
          <input type="number" step="0.01" id="price">
        </div>
        <div class="column">
          <label>COGS (₱)</label>
          <input type="number" step="0.01" id="cogs">
        </div>
      </div>

      <div class="row">
        <div class="column">
          <label>COD Fee (%)</label>
          <input type="number" step="0.01" id="codFee" value="1.5">
        </div>
        <div class="column">
          <label>Shipping Fee (₱ per shipped)</label>
          <input type="number" step="0.01" id="shipping" value="37">
        </div>
      </div>

      {{-- Truncate fractional values toggle. ON by default = current behavior
           (orders/shipped/delivered all floored to integers). --}}
      <label style="display:flex;align-items:center;gap:8px;font-weight:normal;margin-top:8px;font-size:12px;color:#374151;">
        <input type="checkbox" id="truncate" checked style="width:auto;margin:0;">
        <span>Truncate fractional values <span class="hint">(orders / shipped / delivered → whole numbers)</span></span>
      </label>

      <button onclick="calculateProfit()">Compute Profit</button>
      <div class="reset-row">
        <button type="button" class="reset-link" onclick="resetDefaults()">↻ Reset to defaults</button>
      </div>

      <div class="result" id="output"></div>
    </div>
  </div>

  <script>
    // ── Tri-input auto-derive (ad_spend = orders × cpp) ────────────────────
    // History tracks which 2 fields were edited most recently. The 3rd field
    // (the "blank one") gets auto-computed. If the user edits the auto-derived
    // field, it bumps the oldest out of the history and the new "stale" field
    // becomes the derived one.
    const TRI_IDS = ['adSpend', 'orders', 'cpp'];
    let triHistory = [];   // most-recent first (e.g. ['orders','adSpend'])
    let suppressDerive = false;  // prevents recursion when JS sets a field

    function recordEdit(id) {
      // Move/insert this id at the front of the history.
      triHistory = [id, ...triHistory.filter(x => x !== id)];
      // Keep at most 2 — that's what we need to derive the 3rd.
      if (triHistory.length > 2) triHistory.length = 2;
    }

    function deriveTriField() {
      if (suppressDerive) return;
      // Only derive when exactly 2 of the 3 have been edited.
      if (triHistory.length < 2) return;
      const targetId = TRI_IDS.find(id => !triHistory.includes(id));
      if (!targetId) return;
      const [a, b] = triHistory;
      const aV = parseFloat(document.getElementById(a).value);
      const bV = parseFloat(document.getElementById(b).value);
      if (isNaN(aV) || isNaN(bV)) return;

      const truncate = document.getElementById('truncate')?.checked;
      let derived = null;
      if (targetId === 'adSpend')      derived = aV * bV * (a === 'orders' ? 1 : 1); // orders × cpp regardless of order
      // Compute properly based on which field is missing:
      //   ad_spend = orders × cpp
      //   orders   = ad_spend / cpp
      //   cpp      = ad_spend / orders
      if (targetId === 'adSpend') {
        // Need orders × cpp; pick the right values regardless of history order.
        const o = parseFloat(document.getElementById('orders').value);
        const c = parseFloat(document.getElementById('cpp').value);
        if (!isNaN(o) && !isNaN(c)) derived = o * c;
      } else if (targetId === 'orders') {
        const ad = parseFloat(document.getElementById('adSpend').value);
        const c  = parseFloat(document.getElementById('cpp').value);
        if (!isNaN(ad) && !isNaN(c) && c !== 0) {
          derived = ad / c;
          if (truncate) derived = Math.floor(derived);
        }
      } else if (targetId === 'cpp') {
        const ad = parseFloat(document.getElementById('adSpend').value);
        const o  = parseFloat(document.getElementById('orders').value);
        if (!isNaN(ad) && !isNaN(o) && o !== 0) derived = ad / o;
      }

      if (derived === null || isNaN(derived) || !isFinite(derived)) return;

      // Format: orders → integer (if truncate), others → 2 decimals.
      let formatted;
      if (targetId === 'orders') {
        formatted = truncate ? String(Math.floor(derived)) : String(Number(derived.toFixed(4)));
      } else {
        formatted = String(Number(derived.toFixed(2)));
      }

      suppressDerive = true;
      const el = document.getElementById(targetId);
      el.value = formatted;
      el.dataset.derived = '1';   // mark visually so user knows it's auto
      el.style.background = '#f0f9ff';   // subtle blue tint
      suppressDerive = false;
    }

    document.querySelectorAll('[data-tri-input]').forEach(el => {
      el.addEventListener('input', e => {
        if (suppressDerive) return;
        // User-entered input loses the "derived" marker.
        e.target.dataset.derived = '';
        e.target.style.background = '';
        recordEdit(e.target.id);
        deriveTriField();
      });
    });

    // Re-derive when truncate toggle changes (orders rounding may differ).
    document.getElementById('truncate').addEventListener('change', deriveTriField);

    // ── Proceed ↔ Ship Rate pair (bidirectional, anchored on Orders) ──────
    // Tracks which of the two was edited most recently — the other is the
    // "derived" one that auto-updates. Orders is independent: editing Orders
    // does NOT change either, but it triggers a re-derive of the
    // non-recently-edited one so they stay consistent.
    let pairLastEdited = 'shipRate';   // initial state (default value 95)

    function derivePair() {
      if (suppressDerive) return;
      const orders = parseFloat(document.getElementById('orders').value);
      if (isNaN(orders) || orders === 0) return;
      const truncate = document.getElementById('truncate').checked;
      const procEl = document.getElementById('proceed');
      const rateEl = document.getElementById('shipRate');

      if (pairLastEdited === 'shipRate') {
        // Derive Proceed from Ship Rate.
        const rate = parseFloat(rateEl.value);
        if (isNaN(rate)) return;
        let proc = orders * (rate / 100);
        if (truncate) proc = Math.floor(proc);
        suppressDerive = true;
        procEl.value = String(Number(proc.toFixed(truncate ? 0 : 4)));
        procEl.dataset.derived = '1';
        procEl.style.background = '#f0f9ff';
        suppressDerive = false;
      } else {
        // Derive Ship Rate from Proceed.
        const proc = parseFloat(procEl.value);
        if (isNaN(proc)) return;
        const rate = (proc / orders) * 100;
        suppressDerive = true;
        rateEl.value = String(Number(rate.toFixed(2)));
        rateEl.dataset.derived = '1';
        rateEl.style.background = '#f0f9ff';
        suppressDerive = false;
      }
    }

    document.querySelectorAll('[data-pair-input]').forEach(el => {
      el.addEventListener('input', e => {
        if (suppressDerive) return;
        e.target.dataset.derived = '';
        e.target.style.background = '';
        pairLastEdited = e.target.id === 'proceed' ? 'proceed' : 'shipRate';
        derivePair();
      });
    });

    // When Orders changes (via tri-input edit OR tri-derive), re-derive the
    // pair so they stay consistent. Hooked here AFTER tri-derive runs.
    document.getElementById('orders').addEventListener('input', () => {
      // Slight delay so tri-derive sets the value before we read it.
      setTimeout(derivePair, 0);
    });
    // Truncate toggle also reshapes Proceed integer rounding.
    document.getElementById('truncate').addEventListener('change', () => {
      derivePair();
      deriveTriField();
    });

    function resetDefaults(){
      ['adSpend','orders','cpp','rts','price','cogs','proceed'].forEach(id => {
        const el = document.getElementById(id);
        el.value = '';
        el.dataset.derived = '';
        el.style.background = '';
      });
      // Keep operating defaults.
      document.getElementById('shipRate').value = '95';
      document.getElementById('shipRate').dataset.derived = '';
      document.getElementById('shipRate').style.background = '';
      document.getElementById('codFee').value = '1.5';
      document.getElementById('shipping').value = '37';
      document.getElementById('truncate').checked = true;
      document.getElementById('output').innerHTML = '';
      triHistory = [];
      pairLastEdited = 'shipRate';
    }

    function calculateProfit() {
      const adSpend = parseFloat(document.getElementById('adSpend').value);
      const ordersRaw = parseFloat(document.getElementById('orders').value);
      const cppRaw = parseFloat(document.getElementById('cpp').value);
      const proceedRaw  = parseFloat(document.getElementById('proceed').value);
      const shipRatePct = parseFloat(document.getElementById('shipRate').value);
      const rts = parseFloat(document.getElementById('rts').value) / 100;
      const price = parseFloat(document.getElementById('price').value);
      const cogs = parseFloat(document.getElementById('cogs').value);
      const codFeePercent = parseFloat(document.getElementById('codFee').value) / 100;
      const shipping = parseFloat(document.getElementById('shipping').value);
      const truncate = document.getElementById('truncate').checked;

      const outputDiv = document.getElementById('output');
      outputDiv.innerHTML = '';

      // Tri-input validation (Ad Spend / Orders / CPP).
      const triFilled = [!isNaN(adSpend), !isNaN(ordersRaw), !isNaN(cppRaw)].filter(Boolean).length;
      if (triFilled < 2) {
        outputDiv.innerHTML = `
          <strong class="error">Please fill at least 2 of: Ad Spend, Orders, CPP.</strong><br>
          <em>The 3rd auto-fills using <code>ad_spend = orders × cpp</code>.</em>
        `;
        return;
      }
      // Need either Proceed OR Ship Rate (the other auto-derives via Orders).
      if (isNaN(proceedRaw) && isNaN(shipRatePct)) {
        outputDiv.innerHTML = `<strong class="error">Please fill Proceed or Ship Rate.</strong>`;
        return;
      }
      if (!isNaN(shipRatePct) && (shipRatePct < 0 || shipRatePct > 100)) {
        outputDiv.innerHTML = `<strong class="error">Ship Rate must be 0–100.</strong>`;
        return;
      }
      if (isNaN(price) || isNaN(cogs) || isNaN(rts)) {
        outputDiv.innerHTML = `<strong class="error">Please fill Selling Price, COGS, and RTS.</strong>`;
        return;
      }

      // Resolve missing 3rd tri-field.
      let adS = adSpend, ord = ordersRaw, cppV = cppRaw;
      if (isNaN(adS))  adS  = ord * cppV;
      if (isNaN(ord))  ord  = cppV !== 0 ? adS / cppV : 0;
      if (isNaN(cppV)) cppV = ord !== 0 ? adS / ord  : 0;

      const round = v => truncate ? Math.floor(v) : v;
      const orders = round(ord);

      // Resolve Proceed from whichever the user provided.
      let proceed;
      if (!isNaN(proceedRaw)) {
        proceed = round(proceedRaw);
      } else {
        proceed = round(orders * (shipRatePct / 100));
      }
      const effectiveShipRate = orders > 0 ? proceed / orders : 0;

      // Math (proceed = shipped — same value in this calc).
      const delivered = round(proceed * (1 - rts));
      const revenue = delivered * price;
      const totalCOGS = delivered * cogs;
      const totalShipping = proceed * shipping;
      const totalCODFee = delivered * (price * codFeePercent);
      const totalCost = adS + totalCOGS + totalShipping + totalCODFee;
      const profit = revenue - totalCost;
      const profitPerOrder = orders > 0 ? profit / orders : 0;
      const profitPercentage = (orders > 0 && price > 0) ? (profit / (orders * price)) * 100 : 0;

      const peso = v => '₱' + Number(v).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
      const intFmt = v => truncate
        ? Number(v).toLocaleString('en-PH')
        : Number(v).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
      const profCls = profit >= 0 ? 'pos' : 'neg';

      outputDiv.innerHTML = `
        <strong>Total Orders:</strong> ${intFmt(orders)}<br>
        <strong>Proceed (${(effectiveShipRate*100).toFixed(1)}%):</strong> ${intFmt(proceed)}<br>
        <strong>Delivered Orders:</strong> ${intFmt(delivered)}<br>
        <strong>Net Profit:</strong> <span class="${profCls}">${peso(profit)}</span><br>
        <strong>CPP (Cost Per Purchase):</strong> ${peso(cppV)}<br>
        <strong>Net Profit per Order:</strong> ${peso(profitPerOrder)}<br>
        <strong>Profit Percentage:</strong> ${profitPercentage.toFixed(2)}%
      `;
    }
  </script>
</body>
</html>
