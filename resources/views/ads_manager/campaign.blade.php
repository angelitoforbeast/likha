<x-layout>
  <x-slot name="heading" class="flex justify-center">
    CAMPAIGN FROM ADS MANAGER (EXCEL)
  </x-slot>

  {{-- Flash message (unescaped for bold counts) --}}
  @if(session('success'))
    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-2 rounded mb-4">
      {!! session('success') !!}
    </div>
  @endif

  <div class="flex gap-4 items-center mb-4">
    <input
      type="file"
      id="fileInput"
      multiple
      accept=".xlsx,.xls"
      class="block w-full border border-gray-300 rounded p-2"
    />
    <button
      onclick="processFiles()"
      type="button"
      class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded"
    >
      Generate Summary
    </button>
    <a
      href="{{ route('offline_ads.campaign_view') }}"
      class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded"
    >
      View Saved Campaigns
    </a>
  </div>

  <form
    id="uploadForm"
    method="POST"
    action="{{ route('offline_ads.campaign') }}"
  >
    @csrf
    <input type="hidden" name="jsonData" id="groupedDataHidden">
    <button
      id="saveBtn"
      type="submit"
      class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded mb-4"
      disabled
    >
      SAVE TO DATABASE
    </button>
  </form>

  <div id="stats" class="text-sm text-gray-800 mt-4"></div>
  <div id="output" class="text-sm text-gray-800 mt-4"></div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

  <style>
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 20px;
    }
    th, td {
      border: 1px solid #ccc;
      padding: 8px;
      text-align: left;
    }
    th {
      background: #f2f2f2;
    }
  </style>

  <script>
    let flatResults = [];

    function parseNumber(value) {
      if (typeof value === 'string') {
        return parseFloat(value.replace(/[₱,%]/g, '').trim()) || 0;
      }
      return Number(value) || 0;
    }

    function parseDate(value) {
      const d = new Date(value);
      return isNaN(d.getTime())
        ? ''
        : d.toISOString().split('T')[0];
    }

    function processFiles() {
      const files = document.getElementById('fileInput').files;
      const output = document.getElementById('output');
      document.getElementById('stats').innerHTML = '';
      output.innerHTML = '';
      flatResults = [];

      if (!files.length) {
        alert("Please select at least one Excel file.");
        return;
      }

      let processed = 0;
      for (const file of files) {
        const reader = new FileReader();
        reader.onload = e => {
          const data = new Uint8Array(e.target.result);
          const wb = XLSX.read(data, { type: 'array' });
          const ws = wb.Sheets[wb.SheetNames[0]];
          const rows = XLSX.utils.sheet_to_json(ws, {
            defval: "",
            raw: false,
            dateNF: "yyyy-mm-dd"
          });

          rows.forEach(r => {
            const rs  = parseDate(r["Reporting starts"] || r["reporting starts"]);
            const cid = r["Campaign ID"]        || r["campaign id"];
            const aid = r["Ad ID"]              || r["ad id"];
            const amt = parseNumber(r["Amount spent (PHP)"] || r["amount spent (php)"]);

            // skip if missing required fields or if amount_spent is zero/null
            if (!rs || !cid || !aid || amt === 0) return;

            flatResults.push({
              reporting_starts: rs,
              campaign_id:      cid,
              ad_id:            aid,
              campaign_name:    r["Campaign name"]                     || r["campaign name"]    || '',
              adset_name:       r["Ad Set Name"]                      || r["ad set name"]       || '',
              amount_spent:     amt,
              impressions:      parseNumber(r["Impressions"]),
              messages:         parseNumber(r["Messaging conversations started"]),
              budget:           parseNumber(r["Ad set budget"] || r["budget"]),
              ad_delivery:      r["Ad delivery"]                      || '',
              reach:            parseNumber(r["Reach"]),
              hook_rate:        r["HOOK RATE"]                        || r["hook rate"]         || '',
              hold_rate:        r["HOLD RATE"]                        || r["hold rate"]         || ''
            });
          });

          processed++;
          if (processed === files.length) {
            renderStats();
            renderFlatTable();
          }
        };
        reader.readAsArrayBuffer(file);
      }
    }

    function renderStats() {
      const statsDiv = document.getElementById('stats');
      const totalCount = flatResults.length;
      const uniqueKeys = new Set(
        flatResults.map(r => `${r.reporting_starts}|${r.campaign_id}|${r.ad_id}`)
      ).size;
      statsDiv.innerHTML = `<p><strong>Total Rows:</strong> ${totalCount}</p>` +
                           `<p><strong>Unique Combinations (Reporting Start + Campaign ID + Ad ID):</strong> ${uniqueKeys}</p>`;
    }

    function renderFlatTable() {
      const output = document.getElementById('output');
      if (!flatResults.length) {
        output.innerHTML = '<p class="text-red-600">No valid rows found.</p>';
        return;
      }

      let html = '<table><thead><tr>' +
        '<th>Reporting Start</th>' +
        '<th>Campaign ID</th>' +
        '<th>Ad ID</th>' +
        '<th>Campaign name</th>' +
        '<th>Ad Set Name</th>' +
        '<th>Amount spent (PHP)</th>' +
        '<th>Impressions</th>' +
        '<th>Messaging conversations started</th>' +
        '<th>Ad set budget</th>' +
        '<th>Ad delivery</th>' +
        '<th>Reach</th>' +
        '<th>HOOK RATE</th>' +
        '<th>HOLD RATE</th>' +
      '</tr></thead><tbody>';

      flatResults.forEach(r => {
        html += `<tr>
          <td>${r.reporting_starts}</td>
          <td>${r.campaign_id}</td>
          <td>${r.ad_id}</td>
          <td>${r.campaign_name}</td>
          <td>${r.adset_name}</td>
          <td>₱${r.amount_spent.toLocaleString(undefined,{minimumFractionDigits:2})}</td>
          <td>${r.impressions.toLocaleString()}</td>
          <td>${r.messages.toLocaleString()}</td>
          <td>₱${r.budget.toLocaleString(undefined,{minimumFractionDigits:2})}</td>
          <td>${r.ad_delivery}</td>
          <td>${r.reach.toLocaleString()}</td>
          <td>${r.hook_rate}</td>
          <td>${r.hold_rate}</td>
        </tr>`;
      });

      html += '</tbody></table>';
      output.innerHTML = html;

      document.getElementById('groupedDataHidden').value = JSON.stringify(flatResults);
      document.getElementById('saveBtn').disabled = flatResults.length === 0;
    }
  </script>
</x-layout>
