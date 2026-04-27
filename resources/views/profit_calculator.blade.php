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

      <label>Ad Spend (₱)</label>
      <input type="number" step="0.01" id="adSpend">

      <div class="row">
        <div class="column">
          <label>Number of Orders <span class="hint">(or leave blank, fill CPP)</span></label>
          <input type="number" id="orders">
        </div>
        <div class="column">
          <label>CPP <span class="hint">(or leave blank, fill Orders)</span></label>
          <input type="number" step="0.01" id="cpp">
        </div>
      </div>

      <label>Ship Rate (%) <span class="hint">orders → shipped (default 95)</span></label>
      <input type="number" step="0.01" id="shipRate" value="95">

      <label>RTS (%) <span class="hint">shipped → returned</span></label>
      <input type="number" step="0.01" id="rts">

      <label>Selling Price (₱)</label>
      <input type="number" step="0.01" id="price">

      <label>COGS (₱)</label>
      <input type="number" step="0.01" id="cogs">

      <label>COD Fee (%)</label>
      <input type="number" step="0.01" id="codFee" value="1.5">

      <label>Shipping Fee (₱ per shipped)</label>
      <input type="number" step="0.01" id="shipping" value="37">

      <button onclick="calculateProfit()">Compute Profit</button>
      <div class="reset-row">
        <button type="button" class="reset-link" onclick="resetDefaults()">↻ Reset to defaults</button>
      </div>

      <div class="result" id="output"></div>
    </div>
  </div>

  <script>
    function resetDefaults(){
      document.getElementById('adSpend').value = '';
      document.getElementById('orders').value = '';
      document.getElementById('cpp').value = '';
      document.getElementById('rts').value = '';
      document.getElementById('price').value = '';
      document.getElementById('cogs').value = '';
      // Keep these as their default operating values.
      document.getElementById('shipRate').value = '95';
      document.getElementById('codFee').value = '1.5';
      document.getElementById('shipping').value = '37';
      document.getElementById('output').innerHTML = '';
    }

    function calculateProfit() {
      const adSpend = parseFloat(document.getElementById('adSpend').value);
      const ordersInput = document.getElementById('orders').value;
      const cppInput = document.getElementById('cpp').value;
      // Ship rate is now editable; defaults to 95% but the user can override
      // (e.g. if a page has a different orders→shipped conversion).
      const shipRatePctRaw = parseFloat(document.getElementById('shipRate').value);
      const shipRate = (isNaN(shipRatePctRaw) ? 95 : shipRatePctRaw) / 100;
      const rts = parseFloat(document.getElementById('rts').value) / 100;
      const price = parseFloat(document.getElementById('price').value);
      const cogs = parseFloat(document.getElementById('cogs').value);
      const codFeePercent = parseFloat(document.getElementById('codFee').value) / 100;
      const shipping = parseFloat(document.getElementById('shipping').value);

      const outputDiv = document.getElementById('output');
      outputDiv.innerHTML = ''; // clear previous output

      // Validation
      if (isNaN(adSpend)) {
        outputDiv.innerHTML = `<strong class="error">Please enter Ad Spend.</strong>`;
        return;
      }
      if ((ordersInput && cppInput) || (!ordersInput && !cppInput)) {
        outputDiv.innerHTML = `
          <strong class="error">Please enter either <u>Number of Orders</u> or <u>CPP</u> — not both or none.</strong><br>
          <em>Leave one of them blank to compute properly.</em>
        `;
        return;
      }
      if (isNaN(shipRate) || shipRate < 0 || shipRate > 1) {
        outputDiv.innerHTML = `<strong class="error">Ship Rate must be between 0 and 100.</strong>`;
        return;
      }

      let orders, cpp;
      if (ordersInput) {
        orders = parseInt(ordersInput);
        cpp = adSpend / orders;
      } else {
        cpp = parseFloat(cppInput);
        orders = Math.floor(adSpend / cpp);
      }

      const shipped = Math.floor(orders * shipRate);
      const delivered = Math.floor(shipped * (1 - rts));
      const revenue = delivered * price;
      const totalCOGS = delivered * cogs;
      const totalShipping = shipped * shipping;
      const totalCODFee = delivered * (price * codFeePercent);
      const totalCost = adSpend + totalCOGS + totalShipping + totalCODFee;
      const profit = revenue - totalCost;
      const profitPerOrder = orders > 0 ? profit / orders : 0;
      const profitPercentage = (orders > 0 && price > 0) ? (profit / (orders * price)) * 100 : 0;

      const peso = v => '₱' + Number(v).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
      const profCls = profit >= 0 ? 'pos' : 'neg';

      outputDiv.innerHTML = `
        <strong>Total Orders:</strong> ${orders.toLocaleString('en-PH')}<br>
        <strong>Shipped Orders (${(shipRate*100).toFixed(1)}%):</strong> ${shipped.toLocaleString('en-PH')}<br>
        <strong>Delivered Orders:</strong> ${delivered.toLocaleString('en-PH')}<br>
        <strong>Net Profit:</strong> <span class="${profCls}">${peso(profit)}</span><br>
        <strong>CPP (Cost Per Purchase):</strong> ${peso(cpp)}<br>
        <strong>Net Profit per Order:</strong> ${peso(profitPerOrder)}<br>
        <strong>Profit Percentage:</strong> ${profitPercentage.toFixed(2)}%
      `;
    }
  </script>
</body>
</html>
