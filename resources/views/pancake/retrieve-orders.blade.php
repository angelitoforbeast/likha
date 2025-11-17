<x-layout>
    <x-slot name="title">Retrieve Orders</x-slot>
    <x-slot name="heading">
        Retrieve Likha Orders
    </x-slot>

    <div class="max-w-7xl mx-auto bg-white shadow-sm rounded-lg p-4">
        {{-- CSRF meta for fetch calls --}}
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- XLSX library --}}
        <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.16.9/xlsx.full.min.js"></script>

        <style>
            body {
                margin: 10px;
                font-family: sans-serif;
                overflow-x: hidden; /* iwas horizontal scrollbar */
            }

            table {
                width: 100%;
                border-collapse: collapse;
                table-layout: fixed; /* fixed column widths */
            }

            th, td {
                border: 1px solid #000;
                padding: 4px 3px;
                text-align: left;
                vertical-align: top;
                font-size: 12px;
                word-wrap: break-word;
                overflow-wrap: break-word;
            }

            th {
                background-color: #f2f2f2;
            }

            input {
                margin: 10px 5px 10px 0;
                font-size: 13px;
            }

            /* base button */
            button {
                margin: 10px 5px 10px 0;
                font-size: 13px;
                padding: 6px 10px;
                border: 1px solid #cbd5e1;
                border-radius: 4px;
                background-color: #e5e7eb;
                color: #111827;
                cursor: pointer;
            }

            button:hover {
                background-color: #d1d5db;
            }

            /* disabled look (no color, gray) */
            button:disabled {
                background-color: #f9fafb;
                color: #9ca3af;
                border-color: #e5e7eb;
                cursor: not-allowed;
            }

            /* primary action buttons: colored only when enabled */
            #checkBtn:not(:disabled),
            #applyModeBtn:not(:disabled),
            #checkPageFileBtn:not(:disabled) {
                background-color: #3b82f6;   /* blue */
                color: #ffffff;
                border-color: #2563eb;
            }

            #checkBtn:not(:disabled):hover,
            #applyModeBtn:not(:disabled):hover,
            #checkPageFileBtn:not(:disabled):hover {
                background-color: #2563eb;
            }

            .badge {
                display: inline-block;
                padding: 2px 6px;
                border-radius: 999px;
                font-size: 11px;
                font-weight: 600;
            }

            .badge-existing {
                background: #c6f6d5;
                color: #22543d;
            }

            .badge-missing {
                background: #fed7d7;
                color: #742a2a;
            }

            .badge-neutral {
                background: #e2e8f0;
                color: #4a5568;
            }

            .small-note {
                font-size: 12px;
                color: #555;
            }

            #overrideSummary ul {
                margin: 4px 0 0;
                padding-left: 18px;
            }

            #overrideSummary li {
                font-size: 12px;
            }

            /* Fixed widths per column (total = 100%) */
            th.col-file,     td.col-file     { width: 10%; }
            th.col-sender,   td.col-sender   { width: 15%; }
            th.col-message,  td.col-message  { width: 30%; }
            th.col-phone,    td.col-phone    { width: 10%; }
            th.col-oldpage,  td.col-oldpage  { width: 10%; }
            th.col-newpage,  td.col-newpage  { width: 10%; }
            th.col-shop,     td.col-shop     { width: 10%; }
            th.col-status,   td.col-status   { width: 5%; }

            .filter-btn.active {
                background-color: #2b6cb0;
                color: #fff;
            }

            .filter-btn:disabled {
                background-color: #f9fafb;
                color: #9ca3af;
                border-color: #e5e7eb;
                cursor: not-allowed;
            }

            /* Copy button: green when enabled, gray when disabled */
            #copyBtn {
                display: none; /* lalabas lang pag all match */
                background-color: #00ff00;
                border-color: #00cc00;
                color: #000;
                font-weight: 600;
            }

            #copyBtn:hover {
                background-color: #00e600;
            }

            #copyBtn:disabled {
                background-color: #f9fafb;
                border-color: #e5e7eb;
                color: #9ca3af;
                cursor: not-allowed;
            }
        </style>

        <h2 class="text-lg font-semibold mb-2">Retrieve Likha Orders</h2>

        {{-- Upload / main controls --}}
        <div class="mb-3">
            <!-- Upload File Button (MULTIPLE) -->
            <input type="file" id="fileInput" accept=".xlsx, .xls" multiple />

            <!-- Extract File Button -->
            <button onclick="extractData()">Extract Data</button>

            <!-- Check Existing Button -->
            <button id="checkBtn" onclick="checkExisting()" disabled>Check Existing</button>

            <!-- Use Most Common Button (per Excel file) -->
            <button id="applyModeBtn" onclick="applyMostCommon()" disabled>
                Adjust Page Name
            </button>

            <!-- New button: check if PAGE is in filename -->
            <button id="checkPageFileBtn" onclick="checkPageAgainstFilename()" disabled>
                Check Page Name
            </button>

            <!-- Download Button -->
            <button onclick="downloadExcel()">Download Excel</button>
        </div>

        {{-- Filter Buttons --}}
        <div class="mb-2">
            <button id="filterAllBtn" class="filter-btn active" onclick="setFilter('all')">
                Show All
            </button>
            <button id="filterExistingBtn" class="filter-btn" onclick="setFilter('existing')" disabled>
                Show Existing Only
            </button>
            <button id="filterMissingBtn" class="filter-btn" onclick="setFilter('missing')" disabled>
                Show Not Found Only
            </button>
        </div>

        <!-- Display Row Count -->
        <div class="mb-2">
            <strong>Total Rows (current view):</strong> <span id="rowCount">0</span>
        </div>

        <!-- Summary ng overrides per file + filename checker -->
        <div id="overrideSummary" class="small-note mb-2"></div>

        <!-- COPY BUTTON just above table -->
        <div class="mb-2">
            <button id="copyBtn" onclick="copyMatchedRows()" disabled>
                Copy
            </button>
        </div>

        <!-- Table to display extracted data -->
        <div class="overflow-x-auto">
            <table>
                <thead>
                    <tr>
                        <th class="col-file">Source File</th>
                        <th class="col-sender">Sender Name (customer_name)</th>
                        <th class="col-message">Message Content</th>
                        <th class="col-phone">Phone Number</th>
                        <th class="col-oldpage">Old Page (macro_output)</th>
                        <th class="col-newpage">New Page (macro_output)</th>
                        <th class="col-shop">Shop Details (macro_output)</th>
                        <th class="col-status">Status</th>
                    </tr>
                </thead>
                <tbody id="data-body">
                    <!-- Extracted data will be inserted here -->
                </tbody>
            </table>
        </div>

        <script>
            // Row-level data (pwede galing sa multiple files)
            // item = { senderName, messageContent, phoneNumber, fileLabel }
            let extractedData = [];

            // Backend-derived
            let existingNames = new Set();   // fb_name na meron sa macro_output
            let pagesByName   = {};          // { fb_name: [PAGE1, PAGE2] }
            let shopsByName   = {};          // { fb_name: [SHOP1, SHOP2] }

            // Per-file overrides (mode per file)
            // { fileLabel: "MostCommonPage" }
            let overridePageByFile = {};
            let overrideShopByFile = {};

            // Filename vs page matches
            // { fileLabel: true|false }
            let filePageMatches = {};

            // Current filter: 'all' | 'existing' | 'missing'
            let currentFilter = 'all';

            function resetFiltersUI() {
                currentFilter = 'all';
                document.getElementById('filterAllBtn').classList.add('active');
                document.getElementById('filterExistingBtn').classList.remove('active');
                document.getElementById('filterMissingBtn').classList.remove('active');

                // Before checkExisting(), bawal mag-filter by status
                document.getElementById('filterExistingBtn').disabled = true;
                document.getElementById('filterMissingBtn').disabled = true;
            }

            function enableStatusFiltersIfPossible() {
                const hasStatus = existingNames.size > 0;
                document.getElementById('filterExistingBtn').disabled = !hasStatus;
                document.getElementById('filterMissingBtn').disabled = !hasStatus;
            }

            function setFilter(filterType) {
                currentFilter = filterType;

                // UI active state
                document.getElementById('filterAllBtn').classList.toggle('active', filterType === 'all');
                document.getElementById('filterExistingBtn').classList.toggle('active', filterType === 'existing');
                document.getElementById('filterMissingBtn').classList.toggle('active', filterType === 'missing');

                displayData();
            }

            function getFilteredData() {
                // Kung di pa nag-checkExisting, laging show all lang
                if (existingNames.size === 0 || currentFilter === 'all') {
                    return extractedData;
                }

                if (currentFilter === 'existing') {
                    return extractedData.filter(item => existingNames.has(item.senderName));
                }

                if (currentFilter === 'missing') {
                    return extractedData.filter(item => !existingNames.has(item.senderName));
                }

                return extractedData;
            }

            function extractData() {
                const fileInput = document.getElementById('fileInput');
                const files = fileInput.files;

                if (!files || files.length === 0) {
                    alert('Please upload at least one Excel file.');
                    return;
                }

                // Reset all state
                extractedData = [];
                existingNames = new Set();
                pagesByName   = {};
                shopsByName   = {};
                overridePageByFile = {};
                overrideShopByFile = {};
                filePageMatches = {};
                document.getElementById('data-body').innerHTML = '';
                document.getElementById('rowCount').textContent = '0';
                document.getElementById('checkBtn').disabled = true;
                document.getElementById('applyModeBtn').disabled = true;
                document.getElementById('checkPageFileBtn').disabled = true;
                document.getElementById('copyBtn').style.display = 'none';
                document.getElementById('copyBtn').disabled = true;
                document.getElementById('overrideSummary').innerHTML = '';
                resetFiltersUI();

                let pending = files.length; // ilang file pa ang hindi tapos i-process

                Array.from(files).forEach((file) => {
                    const reader = new FileReader();

                    reader.onload = function(e) {
                        try {
                            const data = new Uint8Array(e.target.result);
                            const workbook = XLSX.read(data, { type: 'array' });
                            const firstSheetName = workbook.SheetNames[0];
                            const worksheet = workbook.Sheets[firstSheetName];

                            const jsonData = XLSX.utils.sheet_to_json(worksheet, { header: 1 });

                            const phoneRegex = /(?:#?[O0o]9[0-9]{2})\d{7}/g;

                            // Grouping per FILE (so same sender in different files => hiwalay rows)
                            const groupedData = {};

                            jsonData.forEach((row, index) => {
                                if (index === 0) return; // Skip header row

                                // ORIGINAL MAPPING MO: col[1] = Sender Name, col[0] = Content
                                const senderName     = row[1];
                                const messageContent = row[0];

                                if (senderName && messageContent) {
                                    if (!groupedData[senderName]) {
                                        groupedData[senderName] = { messages: [], phoneNumber: [] };
                                    }

                                    groupedData[senderName].messages.push(messageContent);

                                    const cleanedContent = messageContent.replace(/[.,\s-]+/g, '');
                                    const phoneMatches   = cleanedContent.match(phoneRegex);
                                    if (phoneMatches) {
                                        const purePhoneNumbers = phoneMatches.map(phone => phone.replace(/\D+/g, ''));
                                        groupedData[senderName].phoneNumber.push(...purePhoneNumbers);
                                    }
                                }
                            });

                            // Convert groupedData ng FILE na 'to into extractedData rows
                            for (const sender in groupedData) {
                                const mergedMessages     = groupedData[sender].messages.join(', ');
                                const uniquePhoneNumbers = [...new Set(groupedData[sender].phoneNumber)];

                                // ONLY keep names with phone
                                if (uniquePhoneNumbers.length === 0) continue;

                                const phoneNumber = uniquePhoneNumbers.join(', ');

                                extractedData.push({
                                    senderName: sender,
                                    messageContent: mergedMessages,
                                    phoneNumber: phoneNumber,
                                    fileLabel: file.name   // para alam natin per Excel file
                                });
                            }
                        } finally {
                            // Pagkatapos ma-process ang file na 'to
                            pending--;
                            if (pending === 0) {
                                // Lahat ng files processed
                                displayData();
                                document.getElementById('checkBtn').disabled = extractedData.length === 0;
                            }
                        }
                    };

                    reader.readAsArrayBuffer(file);
                });
            }

            function displayData() {
                const data = getFilteredData();
                const tableBody = document.getElementById('data-body');
                tableBody.innerHTML = '';

                data.forEach(item => {
                    const tr = document.createElement('tr');

                    let statusHtml = '<span class="badge badge-neutral">Not checked</span>';

                    let oldPageText = '';
                    let newPageText = '';
                    let shopText    = '';

                    const fileKey = item.fileLabel;

                    if (existingNames.size > 0) {
                        if (existingNames.has(item.senderName)) {
                            statusHtml = '<span class="badge badge-existing">EXISTING</span>';

                            const pages = pagesByName[item.senderName] || [];
                            const shops = shopsByName[item.senderName] || [];

                            // Old Page = per-name galing macro_output
                            oldPageText = pages.length ? pages.join(', ') : '';

                            // New Page = override per file kung meron, else old
                            if (overridePageByFile[fileKey]) {
                                newPageText = overridePageByFile[fileKey];
                            } else {
                                newPageText = oldPageText;
                            }

                            // Shop Details = override per file kung meron, else per-name
                            if (overrideShopByFile[fileKey]) {
                                shopText = overrideShopByFile[fileKey];
                            } else {
                                shopText = shops.length ? shops.join(', ') : '';
                            }
                        } else {
                            statusHtml = '<span class="badge badge-missing">NOT FOUND</span>';

                            // Wala sa macro_output, pero pwede pa ring lagyan ng override per file
                            if (overridePageByFile[fileKey]) {
                                newPageText = overridePageByFile[fileKey];
                            }
                            if (overrideShopByFile[fileKey]) {
                                shopText = overrideShopByFile[fileKey];
                            }
                        }
                    }

                    tr.innerHTML = `
                        <td class="col-file">${item.fileLabel}</td>
                        <td class="col-sender">${item.senderName}</td>
                        <td class="col-message">${item.messageContent}</td>
                        <td class="col-phone">${item.phoneNumber}</td>
                        <td class="col-oldpage">${oldPageText}</td>
                        <td class="col-newpage">${newPageText}</td>
                        <td class="col-shop">${shopText}</td>
                        <td class="col-status">${statusHtml}</td>
                    `;
                    tableBody.appendChild(tr);
                });

                document.getElementById('rowCount').textContent = data.length;
            }

            async function checkExisting() {
                if (extractedData.length === 0) {
                    alert('Please extract data first.');
                    return;
                }

                const checkBtn = document.getElementById('checkBtn');
                checkBtn.disabled = true;

                // We only need unique names for checking
                const senders = Array.from(new Set(extractedData.map(item => item.senderName)));

                try {
                    const response = await fetch('{{ route('pancake.retrieve-orders.check') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ senders }),
                    });

                    if (!response.ok) {
                        alert('Error checking existing records.');
                        return;
                    }

                    const json = await response.json();

                    existingNames = new Set(json.existing || []);
                    pagesByName   = json.pages || {};
                    shopsByName   = json.shops || {};

                    // Enable override button only if we actually have some data from macro_output
                    const hasAnyRefData =
                        Object.keys(pagesByName).length > 0 || Object.keys(shopsByName).length > 0;

                    document.getElementById('applyModeBtn').disabled = !hasAnyRefData;

                    // Enable filters for existing/missing kung meron nang status
                    enableStatusFiltersIfPossible();

                    displayData();
                } catch (e) {
                    console.error(e);
                    alert('Unexpected error while checking existing records.');
                } finally {
                    checkBtn.disabled = false;
                }
            }

            function applyMostCommon() {
                // For each file, compute its own mode of PAGE and SHOP_DETAILS
                const pagesPerFile = {}; // { fileLabel: [page1,page2,...] }
                const shopsPerFile = {}; // { fileLabel: [shop1,shop2,...] }

                extractedData.forEach(item => {
                    const fileKey = item.fileLabel;
                    const name    = item.senderName;

                    // Only consider EXISTING names for computing modes
                    if (!existingNames.has(name)) return;

                    const pages = pagesByName[name] || [];
                    const shops = shopsByName[name] || [];

                    if (pages.length) {
                        if (!pagesPerFile[fileKey]) pagesPerFile[fileKey] = [];
                        pagesPerFile[fileKey].push(...pages.filter(Boolean));
                    }

                    if (shops.length) {
                        if (!shopsPerFile[fileKey]) shopsPerFile[fileKey] = [];
                        shopsPerFile[fileKey].push(...shops.filter(Boolean));
                    }
                });

                function getMode(arr) {
                    const counts = {};
                    let bestVal   = null;
                    let bestCount = 0;

                    arr.forEach(v => {
                        counts[v] = (counts[v] || 0) + 1;
                        if (counts[v] > bestCount) {
                            bestCount = counts[v];
                            bestVal   = v;
                        }
                    });

                    return bestVal;
                }

                overridePageByFile = {};
                overrideShopByFile = {};
                const summaryLines = [];

                const fileKeys = Array.from(new Set(extractedData.map(i => i.fileLabel)));

                fileKeys.forEach(fileKey => {
                    let pageMode = null;
                    let shopMode = null;

                    if (pagesPerFile[fileKey] && pagesPerFile[fileKey].length) {
                        pageMode = getMode(pagesPerFile[fileKey]);
                        overridePageByFile[fileKey] = pageMode;
                    }

                    if (shopsPerFile[fileKey] && shopsPerFile[fileKey].length) {
                        shopMode = getMode(shopsPerFile[fileKey]);
                        overrideShopByFile[fileKey] = shopMode;
                    }

                    if (pageMode || shopMode) {
                        summaryLines.push(
                            `<li><strong>${fileKey}</strong> &mdash; PAGE: ${pageMode || '-'} | SHOP: ${shopMode || '-'}</li>`
                        );
                    }
                });

                if (summaryLines.length === 0) {
                    alert('No PAGE or SHOP DETAILS found to compute modes per file.');
                    return;
                }

                document.getElementById('overrideSummary').innerHTML =
                    '<strong>Applied overrides per file:</strong><ul>' +
                    summaryLines.join('') +
                    '</ul>';

                // Enable filename vs PAGE checker kung may PAGE overrides
                const hasAnyPageOverride = Object.keys(overridePageByFile).length > 0;
                document.getElementById('checkPageFileBtn').disabled = !hasAnyPageOverride;

                // Re-render table with overrides (respecting current filter)
                displayData();
            }

            function normalizeString(str) {
                if (!str) return '';
                return String(str)
                    .toLowerCase()
                    .replace(/[^a-z0-9]/g, ''); // remove spaces, symbols, etc.
            }

            function checkPageAgainstFilename() {
                if (!Object.keys(overridePageByFile).length) {
                    alert('No PAGE overrides found. Please click "Adjust Page Name" first.');
                    return;
                }

                const lines = [];
                filePageMatches = {};

                for (const fileKey of Object.keys(overridePageByFile)) {
                    const page = overridePageByFile[fileKey] || '';
                    const normFile = normalizeString(fileKey);
                    const normPage = normalizeString(page);

                    let isMatch = false;
                    if (normPage && normFile.includes(normPage)) {
                        isMatch = true;
                    }

                    filePageMatches[fileKey] = isMatch;

                    lines.push(
                        `<li><strong>${fileKey}</strong> — PAGE: ${page || '(none)'} — ` +
                        (isMatch
                            ? '<span style="color:green;">MATCH ✅</span>'
                            : '<span style="color:red;">NO MATCH ❌</span>') +
                        `</li>`
                    );
                }

                const summaryDiv = document.getElementById('overrideSummary');
                let html = summaryDiv.innerHTML || '';

                html += '<div style="margin-top:4px;"><strong>Filename vs PAGE check:</strong><ul>' +
                        lines.join('') +
                        '</ul></div>';

                summaryDiv.innerHTML = html;

                // Enable + show copy button only if ALL files match
                const allMatch = Object.values(filePageMatches).every(v => v === true);
                const copyBtn = document.getElementById('copyBtn');
                if (allMatch) {
                    copyBtn.style.display = 'inline-block';
                    copyBtn.disabled = false;
                } else {
                    copyBtn.style.display = 'none';
                    copyBtn.disabled = true;
                }
            }

            function buildMultilineFormula(str) {
                if (!str) return '';

                const lines = String(str).split(/\r?\n/);
                const esc = s => String(s).replace(/"/g, '""'); // escape double quotes

                if (lines.length === 1) {
                    // Single line pa rin, pero as formula
                    return '="' + esc(lines[0]) + '"';
                }

                // Multiple lines -> ="line1"&CHAR(10)&"line2"&CHAR(10)&"line3"
                let formula = '="' + esc(lines[0]) + '"';
                for (let i = 1; i < lines.length; i++) {
                    formula += '&CHAR(10)&"' + esc(lines[i]) + '"';
                }
                return formula;
            }

            function copyMatchedRows() {
                // Only include rows where:
                // 1) FILE NAME matches PAGE (filePageMatches[fileKey] === true)
                // 2) Sender name is NOT FOUND sa macro_output (!existingNames.has(senderName))
                const matchedRows = extractedData.filter(item => {
                    const fileKey = item.fileLabel;

                    const filenameMatchesPage = filePageMatches[fileKey] === true;
                    const isNotFoundInMacro   = !existingNames.has(item.senderName);

                    return filenameMatchesPage && isNotFoundInMacro;
                });

                if (matchedRows.length === 0) {
                    alert("No NOT FOUND rows to copy.");
                    return;
                }

                const lines = matchedRows.map(item => {
                    const fileKey = item.fileLabel;

                    // New Page = override per file (for NOT FOUND rows, dito talaga galing)
                    const newPage = overridePageByFile[fileKey] || '';

                    // RAW SHOP DETAILS (pwedeng may line break)
                    let rawShopDetails = '';
                    if (overrideShopByFile[fileKey]) {
                        rawShopDetails = overrideShopByFile[fileKey];
                    } else {
                        const shops = shopsByName[item.senderName] || [];
                        rawShopDetails = shops.length ? shops.join(', ') : '';
                    }

                    // Gawing formula para line breaks = CHAR(10) sa loob ng cell
                    const shopFormula = buildMultilineFormula(rawShopDetails);

                    // Clean message: remove line breaks para hindi mag-split ng rows sa GSheet
                    let cleanedMessage = (item.messageContent || '')
                        .replace(/\r?\n/g, ' ')   // newline -> space
                        .replace(/\s+/g, ' ')     // multiple spaces -> single
                        .trim();

                    // 6 COLUMNS:
                    // A: New Page
                    // B: Sender Name
                    // C: Phone Number
                    // D: Message Content
                    // E: SHOP DETAILS (formula na may CHAR(10) kung multi-line)
                    // F: cxd
                    return [
                        newPage,
                        item.senderName,
                        item.phoneNumber,
                        cleanedMessage,
                        shopFormula,
                        'cxd'
                    ].join("\t");
                });

                const output = lines.join("\n");

                navigator.clipboard.writeText(output)
                    .then(() => alert("Copied NOT FOUND rows to clipboard! Ready to paste into Google Sheets."))
                    .catch(err => alert("Failed to copy: " + err));
            }

            function downloadExcel() {
                const fileName = prompt("Enter a name for the file:", "filtered_data.xlsx");
                if (fileName === null) return;

                const wb = XLSX.utils.book_new();

                const data = getFilteredData();

                const wsData = data.map(item => [
                    item.fileLabel,
                    item.senderName,
                    item.messageContent,
                    item.phoneNumber
                ]);

                wsData.unshift(["Source File", "Sender Name (customer_name)", "Message Content", "Phone Number"]);

                const ws = XLSX.utils.aoa_to_sheet(wsData);
                XLSX.utils.book_append_sheet(wb, ws, "With Phone Only");

                XLSX.writeFile(wb, fileName.endsWith('.xlsx') ? fileName : `${fileName}.xlsx`);
            }
        </script>
    </div>
</x-layout>
