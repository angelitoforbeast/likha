<x-layout>
  <x-slot name="heading">RTS Monitoring</x-slot>

  <div class="mb-6">
    <input type="file" id="fileInput" accept=".xlsx,.xls,.csv,.zip" class="block w-full border border-gray-300 rounded p-2" />
  </div>

  <div class="overflow-auto">
    <table id="summaryTable" class="display w-full text-sm">
      <thead>
        <tr id="headerRow"></tr>
        <tr id="filterRow"></tr>
      </thead>
      <tbody></tbody>
    </table>
  </div>

  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" />
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

  <style>
    td.red { background-color: #ffcccc; }
    td.orange { background-color: #ffe5b4; }
    td.green { background-color: #ccffcc; }
    td.cyan { background-color: #ccffff; }
    thead input { width: 100%; box-sizing: border-box; }
  </style>

  <script>
    document.getElementById("fileInput").addEventListener("change", function (e) {
      const file = e.target.files[0];
      if (!file) return;
      const ext = file.name.split('.').pop().toLowerCase();
      const reader = new FileReader();

      reader.onload = function (evt) {
        if (ext === 'csv') {
          const wb = XLSX.read(evt.target.result, { type: 'string' });
          processSheet(wb);
        } else if (ext === 'xlsx' || ext === 'xls') {
          const data = new Uint8Array(evt.target.result);
          const wb = XLSX.read(data, { type: 'array' });
          processSheet(wb);
        } else if (ext === 'zip') {
          JSZip.loadAsync(evt.target.result).then(zip => {
            zip.forEach((_, file) => {
              const fExt = file.name.split('.').pop().toLowerCase();
              if (fExt === 'csv') {
                file.async('string').then(csv => {
                  const wb = XLSX.read(csv, { type: 'string' });
                  processSheet(wb);
                });
              } else if (fExt === 'xlsx' || fExt === 'xls') {
                file.async('arraybuffer').then(data => {
                  const wb = XLSX.read(data, { type: 'array' });
                  processSheet(wb);
                });
              }
            });
          });
        }
      };

      if (ext === 'csv') reader.readAsText(file);
      else reader.readAsArrayBuffer(file);
    });

    function processSheet(workbook) {
      const sheet = workbook.Sheets[workbook.SheetNames[0]];
      const json = XLSX.utils.sheet_to_json(sheet, { header: 1 });
      if (json.length === 0) return;

      const headers = json[0];
      const senderIdx = headers.findIndex(h => h.toLowerCase().includes("sender"));
      const codIdx = headers.findIndex(h => h.toLowerCase() === "cod");
      const statusIdx = headers.findIndex(h => h.toLowerCase().includes("status"));
      const itemIdx = headers.findIndex(h => h.toLowerCase().includes("item name"));
      const submissionIdx = headers.findIndex(h => h.toLowerCase().includes("submission time"));

      if ([senderIdx, codIdx, statusIdx, itemIdx, submissionIdx].includes(-1)) {
        alert("Missing required columns.");
        return;
      }

      const raw = json.slice(1).map(row => ({
        sender: row[senderIdx],
        cod: row[codIdx],
        status: row[statusIdx],
        itemName: row[itemIdx],
        submissionTime: new Date(row[submissionIdx])
      })).filter(r => r.sender && !isNaN(r.submissionTime));

      const uniqueStatuses = [...new Set(raw.map(r => r.status))];

      const grouped = {};
      raw.forEach(r => {
        const key = `${r.sender}|${r.itemName}|${r.cod}`;
        if (!grouped[key]) {
          grouped[key] = {
            sender: r.sender,
            itemName: r.itemName,
            cod: r.cod,
            count: 0,
            dates: [],
            statuses: Object.fromEntries(uniqueStatuses.map(s => [s, 0]))
          };
        }
        grouped[key].count++;
        grouped[key].dates.push(r.submissionTime);
        grouped[key].statuses[r.status]++;
      });

      const dataRows = [];
      const allHeaders = ["Date Range", "Sender", "Item Name", "COD", "Quantity", ...uniqueStatuses, "RTS%", "Delivered%", "In Transit%", "Current RTS (%)", "MAX RTS (%)"];

      Object.values(grouped).forEach(g => {
        const min = new Date(Math.min(...g.dates));
        const max = new Date(Math.max(...g.dates));
        const dateRange = `${min.toISOString().split("T")[0]} to ${max.toISOString().split("T")[0]}`;

        const rts = (g.statuses["Returned"] || 0) + (g.statuses["For Return"] || 0);
        const delivered = g.statuses["Delivered"] || 0;
        const problematic = g.statuses["Problematic Processing"] || 0;
        const detained = g.statuses["Detained"] || 0;

        const rtsPercent = ((rts / g.count) * 100).toFixed(2);
        const delPercent = ((delivered / g.count) * 100).toFixed(2);
        const transitPercent = (100 - rtsPercent - delPercent).toFixed(2);
        const currRTS = (rts + delivered) > 0 ? ((rts / (rts + delivered)) * 100).toFixed(2) : "N/A";
        const maxRTS = ((rts + problematic + detained) / (rts + problematic + detained + delivered) * 100).toFixed(2);

        const row = [
          dateRange, g.sender, g.itemName, g.cod, g.count,
          ...uniqueStatuses.map(s => g.statuses[s] || 0),
          rtsPercent + "%", delPercent + "%", transitPercent + "%",
          currRTS === "N/A" ? "N/A" : currRTS + "%",
          maxRTS + "%"
        ];

        dataRows.push(row);
      });

      const thead1 = document.getElementById("headerRow");
      const thead2 = document.getElementById("filterRow");
      const tbody = document.querySelector("#summaryTable tbody");
      thead1.innerHTML = "";
      thead2.innerHTML = "";
      tbody.innerHTML = "";

      allHeaders.forEach(h => {
        const th = document.createElement("th");
        th.textContent = h;
        thead1.appendChild(th);

        const filterTh = document.createElement("th");
        filterTh.innerHTML = `<input type="text" placeholder="Filter ${h}" />`;
        thead2.appendChild(filterTh);
      });

      dataRows.forEach(rowData => {
        const tr = document.createElement("tr");
        rowData.forEach((val, i) => {
          const td = document.createElement("td");
          td.textContent = val;
          if (allHeaders[i].includes("RTS") && val !== "N/A") {
            const valNum = parseFloat(val);
            if (valNum > 25) td.className = "red";
            else if (valNum > 20) td.className = "orange";
            else if (valNum > 15) td.className = "green";
            else td.className = "cyan";
          }
          tr.appendChild(td);
        });
        tbody.appendChild(tr);
      });

      if ($.fn.DataTable.isDataTable("#summaryTable")) {
        $("#summaryTable").DataTable().destroy();
      }

      const table = $("#summaryTable").DataTable({
        orderCellsTop: true,
        fixedHeader: true,
        paging: false,
        lengthChange: false,
        pageLength: -1
      });

      table.columns().every(function (i) {
        const column = this;
        $('input', thead2.children[i]).on('keyup change clear', function () {
          if (column.search() !== this.value) {
            column.search(this.value).draw();
          }
        });
      });
    }
  </script>
</x-layout>
