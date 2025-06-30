<x-layout>
    <x-slot name="heading">📊 MES FILE vs ORDER MANAGEMENT vs STICKER</x-slot>

    <div class="max-w-6xl mx-auto mt-6 bg-white shadow-md rounded-lg p-6 space-y-6">
        <p class="text-gray-600 text-lg">
            Step 1: Upload the <strong>MES FILE</strong> Excel file.<br>
            Step 2: Upload the <strong>ORDER MANAGEMENT</strong> Excel file.<br>
            Step 3: Upload one or more <strong>PDF files</strong> (STICKER).
        </p>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">🏭 MES FILE</label>
                <input type="file" id="mesInput" accept=".xlsx,.xls,.csv"
                    class="block w-full text-sm text-gray-700 border border-gray-300 rounded-md shadow-sm file:bg-yellow-50 file:border-0 file:px-4 file:py-2 file:mr-4 file:rounded file:text-yellow-700 hover:file:bg-yellow-100">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">📊 ORDER MANAGEMENT</label>
                <input type="file" id="orderInput" accept=".xlsx,.xls,.csv" disabled
                    class="block w-full text-sm text-gray-700 border border-gray-300 rounded-md shadow-sm file:bg-green-50 file:border-0 file:px-4 file:py-2 file:mr-4 file:rounded file:text-green-700 hover:file:bg-green-100 disabled:opacity-50">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">🏷️ STICKER (PDF)</label>
                <input type="file" id="pdfInput" multiple accept=".pdf" disabled
                    class="block w-full text-sm text-gray-700 border border-gray-300 rounded-md shadow-sm file:bg-blue-50 file:border-0 file:px-4 file:py-2 file:mr-4 file:rounded file:text-blue-700 hover:file:bg-blue-100 disabled:opacity-50">
            </div>
        </div>

        <div id="output" class="overflow-x-auto mt-6"></div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.10.377/pdf.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.10.377/pdf.worker.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

    <script>
        const pdfjsLib = window['pdfjs-dist/build/pdf'];
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.10.377/pdf.worker.min.js';

        let orderCountMap = {};
        let mesCountMap = {};
        let pdfCountMap = {};

        const mesInput = document.getElementById('mesInput');
        const orderInput = document.getElementById('orderInput');
        const pdfInput = document.getElementById('pdfInput');

        function normalizeItemName(name) {
            return name
                .replace(/^\d+\s*x\s*/i, '')     // remove quantity prefix like "2 x"
                .replace(/\u00A0/g, ' ')         // replace non-breaking spaces
                .replace(/\s+/g, ' ')            // collapse multiple spaces
                .trim()
                .toUpperCase();
        }

        function addToMap(map, item, count = 1) {
            const key = normalizeItemName(item);
            map[key] = (map[key] || 0) + count;
        }

        mesInput.addEventListener('change', async (event) => {
            const file = event.target.files[0];
            const reader = new FileReader();

            reader.onload = (e) => {
                const data = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, { type: 'array' });
                const sheet = workbook.Sheets[workbook.SheetNames[0]];
                const json = XLSX.utils.sheet_to_json(sheet, { range: 7 });

                mesCountMap = {};
                json.forEach(row => {
                    const item = row["Parcel Name (*)"];
                    if (item) addToMap(mesCountMap, item);
                });

                orderInput.disabled = false;
                renderComparisonTable();
            };

            reader.readAsArrayBuffer(file);
        });

        orderInput.addEventListener('change', async (event) => {
            const file = event.target.files[0];
            const reader = new FileReader();

            reader.onload = (e) => {
                const data = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, { type: 'array' });
                const sheet = workbook.Sheets[workbook.SheetNames[0]];
                const json = XLSX.utils.sheet_to_json(sheet);

                orderCountMap = {};
                json.forEach(row => {
                    const item = row["Item Name"];
                    if (item) addToMap(orderCountMap, item);
                });

                pdfInput.disabled = false;
                renderComparisonTable();
            };

            reader.readAsArrayBuffer(file);
        });

        pdfInput.addEventListener('change', async (event) => {
            const files = Array.from(event.target.files);
            const extractedItems = [];

            for (const file of files) {
                const buffer = await file.arrayBuffer();
                const pdf = await pdfjsLib.getDocument({ data: buffer }).promise;

                for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
                    const page = await pdf.getPage(pageNum);
                    const content = await page.getTextContent();
                    const lines = [];

                    let lastY = null;
                    let line = [];
                    content.items.forEach(item => {
                        if (lastY !== null && Math.abs(item.transform[5] - lastY) > 5) {
                            lines.push(line.join(' ').trim());
                            line = [];
                        }
                        line.push(item.str);
                        lastY = item.transform[5];
                    });
                    if (line.length) lines.push(line.join(' ').trim());

                    let pieceCount = 0;
                    for (let i = 0; i < lines.length; i++) {
                        if (lines[i].includes("Piece:")) {
                            pieceCount++;
                            if (pieceCount % 2 === 1 && i > 0) {
                                const value = lines[i - 1].trim();
                                if (value) extractedItems.push(value);
                            }
                        }
                    }
                }
            }

            pdfCountMap = {};
            extractedItems.forEach(item => {
                addToMap(pdfCountMap, item);
            });

            renderComparisonTable();
        });

        function renderComparisonTable() {
    const allItems = new Set([
        ...Object.keys(mesCountMap),
        ...Object.keys(orderCountMap),
        ...Object.keys(pdfCountMap)
    ]);

    if (allItems.size === 0) return;

    let totalMes = 0;
    let totalOrder = 0;
    let totalSticker = 0;

    let tableHTML = `
        <table class="min-w-full border border-gray-300 shadow-sm rounded-lg overflow-hidden">
            <thead class="bg-gray-100 text-gray-700">
                <tr>
                    <th class="px-4 py-2 text-left border-b">🧾 Item</th>
                    <th class="px-4 py-2 text-left border-b">🏭 MES FILE</th>
                    <th class="px-4 py-2 text-left border-b">📊 ORDER MANAGEMENT</th>
                    <th class="px-4 py-2 text-left border-b">🏷️ STICKER</th>
                </tr>
            </thead>
            <tbody class="bg-white">`;

    allItems.forEach(item => {
        const mes = mesCountMap[item] || 0;
        const order = orderCountMap[item] || 0;
        const sticker = pdfCountMap[item] || 0;

        totalMes += mes;
        totalOrder += order;
        totalSticker += sticker;

        const values = [mes, order, sticker];
        const freq = {};
        values.forEach(v => freq[v] = (freq[v] || 0) + 1);

        const mesClass = freq[mes] === 1 ? 'bg-red-100' : '';
        const orderClass = freq[order] === 1 ? 'bg-red-100' : '';
        const stickerClass = freq[sticker] === 1 ? 'bg-red-500 text-white' : '';


        tableHTML += `
            <tr class="hover:bg-gray-50 transition">
                <td class="px-4 py-2 border-b">${item}</td>
                <td class="px-4 py-2 border-b ${mesClass}">${mes}</td>
                <td class="px-4 py-2 border-b ${orderClass}">${order}</td>
                <td class="px-4 py-2 border-b ${stickerClass}">${sticker}</td>
            </tr>`;
    });

    // Append totals row
    tableHTML += `
        <tr class="font-semibold bg-gray-50">
            <td class="px-4 py-2 border-t border-b">TOTAL</td>
            <td class="px-4 py-2 border-t border-b">${totalMes}</td>
            <td class="px-4 py-2 border-t border-b">${totalOrder}</td>
            <td class="px-4 py-2 border-t border-b">${totalSticker}</td>
        </tr>`;

    tableHTML += `</tbody></table>`;
    document.getElementById('output').innerHTML = tableHTML;
}

    </script>
</x-layout>