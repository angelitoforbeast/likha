<x-layout>
  <x-slot name="title">Address Search</x-slot>

  <style>
    html, body {
      margin: 0;
      padding: 0;
      height: 100%;
      font-family: 'Segoe UI', sans-serif;
      font-size: 13px;
      background-color: #fff;
    }

    body {
      display: flex;
      flex-direction: column;
      box-sizing: border-box;
      padding: 16px;
      max-width: 820px;
      margin: 0 auto;
    }

    h2 {
      font-size: 16px;
      text-align: center;
      color: #d93b58;
      margin-bottom: 16px;
    }

    .output-line {
      margin-bottom: 6px;
      padding: 8px;
      border: 1px solid #ccc;
      border-radius: 4px;
      background-color: #f9f9f9;
      cursor: pointer;
      font-weight: bold;
    }

    .output-line:hover { background-color: #f0f0f0; }

    .copy-value { color: #000; margin-left: 6px; }

    label {
      font-weight: bold;
      font-size: 12px;
      margin: 10px 0 6px;
      display: block;
    }

    .input-row {
      display: flex;
      gap: 6px;
      margin-bottom: 8px;
    }

    input[type="text"] {
      flex: 1;
      padding: 6px 8px;
      font-size: 13px;
      border: 1px solid #ccc;
      border-radius: 4px;
    }

    button.clear-btn {
      padding: 6px 12px;
      font-size: 12px;
      border-radius: 4px;
      border: 1px solid #d33;
      background-color: #ffdddd;
      color: #d33;
      font-weight: bold;
      cursor: pointer;
    }

    button.clear-btn:hover { background-color: #ffcccc; }

    .hint {
      font-size: 11px;
      color: #666;
      margin: 4px 0 10px;
    }

    ul {
      list-style: none;
      padding: 0;
      margin: 0;
      border: 1px solid #ddd;
      max-height: calc(100vh - 280px);
      overflow-y: auto;
      border-radius: 4px;
    }

    li {
      padding: 8px 10px;
      border-bottom: 1px solid #eee;
      cursor: pointer;
    }

    li:hover { background-color: #f0f0f0; }
    li.active { background-color: #eef2ff; }
  </style>
</head>
<body>

  <h2>📍 Address Search</h2>

  <!-- Output Display (click to copy value) -->
  <div class="output-line" id="provLine" onclick="copyOnlyValue('provLine')">PROVINCE:<span class="copy-value"></span></div>
  <div class="output-line" id="cityLine" onclick="copyOnlyValue('cityLine')">CITY:<span class="copy-value"></span></div>
  <div class="output-line" id="brgyLine" onclick="copyOnlyValue('brgyLine')">BARANGAY:<span class="copy-value"></span></div>

  <label for="search">Search</label>
  <div class="input-row">
    <input type="text" id="search" placeholder="Type any word..." autocomplete="off">
    <button class="clear-btn" type="button" onclick="clearAll()">Clear</button>
  </div>

  <div class="hint">
    Type at least 3 characters. Multiple words supported (e.g. <b>isabela san mateo</b>). Use ↑ ↓ then Enter to select.
  </div>

  <ul id="results"></ul>

  <script>
    const searchEl = document.getElementById('search');
    const resultsEl = document.getElementById('results');

    let currentResults = [];
    let activeIndex = -1;
    let debounceTimer = null;

    function setOutputs(prov='', city='', brgy='') {
      document.getElementById("provLine").innerHTML = `PROVINCE:<span class="copy-value">${prov}</span>`;
      document.getElementById("cityLine").innerHTML = `CITY:<span class="copy-value">${city}</span>`;
      document.getElementById("brgyLine").innerHTML = `BARANGAY:<span class="copy-value">${brgy}</span>`;
    }

    function copyOnlyValue(id) {
      const span = document.querySelector(`#${id} .copy-value`);
      if (!span) return;
      const v = (span.textContent || '').trim();
      if (!v) return;
      navigator.clipboard.writeText(v);
    }

    function clearAll() {
      searchEl.value = '';
      resultsEl.innerHTML = '';
      currentResults = [];
      activeIndex = -1;
      setOutputs('', '', '');
      searchEl.focus();
    }

    async function fetchResults(q) {
      const url = new URL("{{ route('jnt.address.search') }}", window.location.origin);
      url.searchParams.set('q', q);
      url.searchParams.set('limit', '80');

      const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' }});
      if (!res.ok) return [];
      const data = await res.json();
      return (data && Array.isArray(data.results)) ? data.results : [];
    }

    function renderResults(items) {
      resultsEl.innerHTML = '';
      activeIndex = -1;

      if (!items.length) return;

      items.forEach((it, idx) => {
        const li = document.createElement('li');
        li.textContent = it.label;
        li.addEventListener('click', () => selectIndex(idx));
        resultsEl.appendChild(li);
      });
    }

    function highlightActive() {
      const lis = Array.from(resultsEl.querySelectorAll('li'));
      lis.forEach((li, i) => li.classList.toggle('active', i === activeIndex));
    }

    function selectIndex(idx) {
      const it = currentResults[idx];
      if (!it) return;

      searchEl.value = it.label;
      resultsEl.innerHTML = '';
      currentResults = [];
      activeIndex = -1;

      setOutputs(it.prov, it.city, it.brgy);
    }

    searchEl.addEventListener('input', () => {
      const q = (searchEl.value || '').trim();

      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(async () => {
        if (q.length < 3) {
          resultsEl.innerHTML = '';
          currentResults = [];
          activeIndex = -1;
          return;
        }

        const items = await fetchResults(q);
        currentResults = items;
        renderResults(items);
      }, 180);
    });

    searchEl.addEventListener('keydown', (e) => {
      const max = currentResults.length;

      if (e.key === 'ArrowDown' && max) {
        e.preventDefault();
        activeIndex = Math.min(activeIndex + 1, max - 1);
        highlightActive();
      }

      if (e.key === 'ArrowUp' && max) {
        e.preventDefault();
        activeIndex = Math.max(activeIndex - 1, 0);
        highlightActive();
      }

      if (e.key === 'Enter') {
        if (activeIndex >= 0) {
          e.preventDefault();
          selectIndex(activeIndex);
        } else if (max) {
          e.preventDefault();
          selectIndex(0);
        }
      }

      if (e.key === 'Escape') {
        resultsEl.innerHTML = '';
        currentResults = [];
        activeIndex = -1;
      }
    });

    // initial
    setOutputs('', '', '');
  </script>

</body>
</x-layout>
