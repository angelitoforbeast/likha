<x-layout>
  <x-slot name="heading" class="flex justify-center">CAMPAIGN SUMMARY (GROUPED)</x-slot>

  @if(session('success'))
    <div class="text-green-600 font-semibold mb-4">{{ session('success') }}</div>
  @endif

<div class="flex gap-4 items-center">
  <input type="file" id="fileInput" multiple accept=".xlsx,.xls" class="block w-full border border-gray-300 rounded p-2" />
  <button onclick="processFiles()" type="button" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">Generate Summary</button>
  <a href="/ads_manager/view" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded">View Ads Manager</a>
</div>


    <!-- SAVE BUTTON FORM -->
    <form id="uploadForm" method="POST" action="/ads_manager/index">
      @csrf
      <input type="hidden" name="jsonData" id="groupedDataHidden">
      <button id="saveBtn" type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded mt-2" disabled>SAVE TO DATABASE</button>
    </form>
  </div>

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
    let groupedResults = [];

    function parseNumber(value) {
      if (typeof value === 'string') {
        value = value.replace(/[₱,]/g, '').trim();
      }
      return parseFloat(value);
    }

    function parseDate(value) {
      const d = new Date(value);
      return isNaN(d.getTime()) ? null : d;
    }

    function formatDate(date) {
      return date ? date.toISOString().split('T')[0] : '';
    }

    function processFiles() {
      const files = document.getElementById('fileInput').files;
      const output = document.getElementById('output');
      output.innerHTML = '';
      groupedResults = [];

      if (!files.length) {
        alert("Please select at least one Excel file.");
        return;
      }

      const allData = [];
      let filesProcessed = 0;

      for (let file of files) {
        const reader = new FileReader();
        reader.onload = (e) => {
          const data = new Uint8Array(e.target.result);
          const workbook = XLSX.read(data, { type: 'array' });
          const sheet = workbook.Sheets[workbook.SheetNames[0]];
          const jsonData = XLSX.utils.sheet_to_json(sheet, { defval: "" });

          jsonData.forEach(row => {
            const rawSpent = parseNumber(row["Amount spent (PHP)"]);
            const spent = rawSpent > 0 ? rawSpent * 1.12 : 0;
            if (!isNaN(spent) && spent > 0) {
              const rawName = row["Campaign name"] || row["Ad Set Name"] || '';
              const cleanCampaign = rawName.split('|')[0].trim();

              allData.push({
                campaign: cleanCampaign,
                spent: spent,
                costPerMessage: parseNumber(row["Cost per messaging conversation started (PHP)"]) || 0, // custom CPM
                impressionsCost: parseNumber(row["CPM (cost per 1,000 impressions) (PHP)"]) || 0,         // custom CPI
                start: parseDate(row["Reporting starts"]),
              });
            }
          });

          filesProcessed++;
          if (filesProcessed === files.length) {
            renderGroupedTable(allData);
          }
        };
        reader.readAsArrayBuffer(file);
      }
    }

    function renderGroupedTable(data) {
      const output = document.getElementById('output');
      if (data.length === 0) {
        output.innerHTML = '<p style="color:red;">No valid rows with Amount Spent found.</p>';
        return;
      }

      const grouped = {};
      data.forEach(row => {
        const groupKey = `${row.campaign}__${formatDate(row.start)}`;
        if (!grouped[groupKey]) grouped[groupKey] = [];
        grouped[groupKey].push(row);
      });

      groupedResults = [];

      let html = '<table><thead><tr>' +
        '<th>Reporting Starts</th>' +
        '<th>Page</th>' +
        '<th>Amount Spent</th>' +
        '<th>CPM</th>' +
        '<th>CPI</th>' +
        '</tr></thead><tbody>';

      Object.keys(grouped).sort().forEach(key => {
        const rows = grouped[key];
        const campaign = rows[0].campaign;

        let totalSpent = 0;
        let totalMessages = 0;
        let weightedCpmSum = 0;
        let minStart = null;

        rows.forEach(r => {
          totalSpent += r.spent;
          const messages = r.costPerMessage > 0 ? r.spent / r.costPerMessage : 0;
          totalMessages += messages;
          weightedCpmSum += r.spent * r.impressionsCost;
          if (r.start && (!minStart || r.start < minStart)) minStart = r.start;
        });

        const weightedCpi = totalMessages > 0 ? totalSpent / totalMessages : 0;
        const weightedCpm = totalSpent > 0 ? weightedCpmSum / totalSpent : 0;

        const finalRow = {
          reporting_starts: formatDate(minStart),
          page: campaign,
          amount_spent: totalSpent.toFixed(2),
          cpm: weightedCpi.toFixed(2), // cost per message (CPM)
          cpi: weightedCpm.toFixed(2), // cost per 1,000 impressions (CPI)
        };

        groupedResults.push(finalRow);

        html += `<tr>
          <td>${finalRow.reporting_starts}</td>
          <td>${finalRow.page}</td>
          <td>₱${parseFloat(finalRow.amount_spent).toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
          <td>₱${finalRow.cpm}</td>
          <td>₱${finalRow.cpi}</td>
        </tr>`;
      });

      html += '</tbody></table>';
      output.innerHTML = html;
      document.getElementById('groupedDataHidden').value = JSON.stringify(groupedResults);
      document.getElementById('saveBtn').disabled = false;
    }
  </script>
</x-layout>
