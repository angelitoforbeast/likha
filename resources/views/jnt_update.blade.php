<x-layout>
  <x-slot name="heading" class="flex justify-center">UPDATE FROM JNT</x-slot>

  <div class="mb-6 flex flex-col gap-2">
    <div class="flex gap-4 items-center">
      <input type="file" id="fileInput" accept=".xlsx,.xls,.csv,.zip" class="block w-full border border-gray-300 rounded p-2" />
      <button id="saveButton" type="button" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded" disabled>SAVE TO DATABASE</button>
      <span id="processingIndicator" class="text-gray-600 hidden">Processing...</span>
    </div>

    <!-- 🔄 Upload progress -->
    <div id="uploadStatus" class="text-sm text-gray-800 mt-2 hidden"></div>
  </div>

  <form id="uploadForm" method="POST">
    @csrf
    <input type="hidden" name="jsonData" id="jsonDataInput">

    <div id="rowCounter" class="text-sm text-gray-600 mb-2 hidden"></div>

    <table id="summaryTable" class="display w-full text-sm">
      <thead><tr id="headerRow"></tr></thead>
      <tbody></tbody>
    </table>
  </form>

  {{-- Dependencies --}}
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" />
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

  <script>
    let loadedWorkbook = null;
    let parsedData = [];

    document.getElementById("fileInput").addEventListener("change", function (e) {
      const file = e.target.files[0];
      if (!file) return;

      document.getElementById("processingIndicator").classList.remove("hidden");
      document.getElementById("saveButton").disabled = true;

      const ext = file.name.split('.').pop().toLowerCase();
      const reader = new FileReader();

      reader.onload = function (evt) {
        if (ext === 'csv') {
          loadedWorkbook = XLSX.read(evt.target.result, { type: 'string' });
          showAndPrepareTable(loadedWorkbook);
        } else if (ext === 'xlsx' || ext === 'xls') {
          const data = new Uint8Array(evt.target.result);
          loadedWorkbook = XLSX.read(data, { type: 'array' });
          showAndPrepareTable(loadedWorkbook);
        } else if (ext === 'zip') {
          JSZip.loadAsync(evt.target.result).then(zip => {
            zip.forEach((_, file) => {
              const fExt = file.name.split('.').pop().toLowerCase();
              if (fExt === 'csv') {
                file.async('string').then(csv => {
                  loadedWorkbook = XLSX.read(csv, { type: 'string' });
                  showAndPrepareTable(loadedWorkbook);
                });
              } else if (fExt === 'xlsx' || fExt === 'xls') {
                file.async('arraybuffer').then(data => {
                  loadedWorkbook = XLSX.read(data, { type: 'array' });
                  showAndPrepareTable(loadedWorkbook);
                });
              }
            });
          });
        }
      };

      if (ext === 'csv') reader.readAsText(file);
      else reader.readAsArrayBuffer(file);
    });

    function showAndPrepareTable(workbook) {
      const sheet = workbook.Sheets[workbook.SheetNames[0]];
      const json = XLSX.utils.sheet_to_json(sheet, { header: 1 });
      if (json.length === 0) return;

      const headers = json[0].map(h => h ? h.toString() : '');
      const selectedCols = [
        { name: "Sender", index: headers.findIndex(h => h.toLowerCase().includes("sender")) },
        { name: "COD", index: headers.findIndex(h => h.toLowerCase() === "cod") },
        { name: "Status", index: headers.findIndex(h => h.toLowerCase().includes("status")) },
        { name: "Item Name", index: headers.findIndex(h => h.toLowerCase().includes("item name")) },
        { name: "Submission Time", index: headers.findIndex(h => h.toLowerCase().includes("submission time")) },
        { name: "Receiver", index: headers.findIndex(h => h.toLowerCase().includes("receiver")) },
        { name: "Receiver Cellphone", index: headers.findIndex(h => h.toLowerCase().includes("receiver cellphone")) },
        { name: "Waybill Number", index: headers.findIndex(h => h.toLowerCase().includes("waybill")) },
        { name: "SigningTime", index: headers.findIndex(h => h.toLowerCase().includes("signingtime")) },
        { name: "Remarks", index: headers.findIndex(h => h.toLowerCase().includes("remarks")) }
      ];

      const missingCols = selectedCols.filter(col => col.index === -1);
      if (missingCols.length > 0) {
        const missingList = missingCols.map(col => `- ${col.name}`).join('\n');
        alert(`Some required columns are missing in the file:\n\n${missingList}`);
        document.getElementById("processingIndicator").classList.add("hidden");
        return;
      }

      const thead = document.getElementById("headerRow");
      const tbody = document.querySelector("#summaryTable tbody");
      const rowCounter = document.getElementById("rowCounter");

      thead.innerHTML = "";
      tbody.innerHTML = "";
      parsedData = [];

      selectedCols.forEach(col => {
        const th = document.createElement("th");
        th.textContent = col.name;
        thead.appendChild(th);
      });

      const totalRows = json.length - 1;
      rowCounter.classList.remove("hidden");

      json.slice(1).forEach((row, i) => {
        rowCounter.textContent = `Processing row ${i + 1} of ${totalRows}`;

        const tr = document.createElement("tr");
        const rowData = {};

        selectedCols.forEach(col => {
          const td = document.createElement("td");
          const val = row[col.index] ?? "";
          td.textContent = val;
          tr.appendChild(td);
          rowData[col.name] = val;
        });

        tbody.appendChild(tr);
        parsedData.push(rowData);
      });

      rowCounter.textContent = `Finished processing ${totalRows} rows.`;

      if ($.fn.DataTable.isDataTable("#summaryTable")) {
        $("#summaryTable").DataTable().destroy();
      }
      $("#summaryTable").DataTable({
        paging: false,
        searching: false,
        ordering: true,
        info: false
      });

      document.getElementById("jsonDataInput").value = JSON.stringify(parsedData);
      document.getElementById("processingIndicator").classList.add("hidden");
	  
      document.getElementById("saveButton").disabled = false;
    }

    function chunkArray(array, size) {
      const result = [];
      for (let i = 0; i < array.length; i += size) {
        result.push(array.slice(i, i + size));
      }
      return result;
    }

    document.getElementById("saveButton").addEventListener("click", async function () {
      if (!parsedData.length) {
        alert("No data to save. Please upload a valid file.");
        return;
      }

      const uploadStatus = document.getElementById("uploadStatus");
      uploadStatus.classList.remove("hidden");
      uploadStatus.textContent = "Starting upload...";

      const chunks = chunkArray(parsedData, 1000);
      const totalBatches = chunks.length;
      let uploadedRows = 0;
      let totalRows = parsedData.length;

      for (let i = 0; i < totalBatches; i++) {
        const chunk = chunks[i];
        uploadStatus.textContent = `Uploading batch ${i + 1} of ${totalBatches} (${uploadedRows} of ${totalRows} rows done)...`;

        try {
          await fetch('/jnt_update', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value
            },
            body: JSON.stringify({ jsonData: JSON.stringify(chunk) })
          });

          uploadedRows += chunk.length;
        } catch (err) {
          console.error("Upload failed for batch", i + 1, err);
          uploadStatus.textContent = `❌ Error uploading batch ${i + 1}. Upload stopped.`;
          return;
        }
      }

      uploadStatus.textContent = `✅ Upload complete! ${uploadedRows} rows processed.`;
      alert("Upload complete!");
      location.reload();
    });
  </script>
</x-layout>
