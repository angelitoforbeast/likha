<!doctype html>
<html lang="en" class="h-full bg-gray-100">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Ads Manager • Insights</title>
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full">
<div class="min-h-full">

  <header class="bg-white shadow-sm">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
      <h1 class="text-2xl font-bold tracking-tight text-gray-900">
        Ads Manager • Insights
      </h1>
    </div>
  </header>

  <main>
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 space-y-6">

      <!-- Filters -->
      <div class="bg-white p-4 rounded shadow">
        <div class="grid grid-cols-1 md:grid-cols-7 gap-3 items-end">
          <div>
            <label class="block text-sm font-medium text-gray-700">From</label>
            <input id="from" type="date" class="w-full border rounded p-2">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">To</label>
            <input id="to" type="date" class="w-full border rounded p-2">
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700">Page</label>
            <select id="page" class="w-full border rounded p-2">
              <option value="">All pages</option>
              @foreach ($pages as $p)
                <option value="{{ $p }}">{{ $p }}</option>
              @endforeach
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Tier</label>
            <select id="tier" class="w-full border rounded p-2">
              <option value="">Default (₱299)</option>
              <option value="199">₱199 SKU</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Target CPP (₱)</label>
            <input id="targetCpp" type="number" step="0.01" class="w-full border rounded p-2" placeholder="ex: 70">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Breakeven CPP (₱)</label>
            <input id="breakevenCpp" type="number" step="0.01" class="w-full border rounded p-2" placeholder="ex: 65">
          </div>
        </div>

        <div class="flex flex-wrap gap-3 mt-3 items-center">
          <label class="inline-flex items-center gap-2 text-sm text-gray-700">
            <input id="includeInactive" type="checkbox" class="rounded border-gray-300">
            Include inactive
          </label>

          <button id="btnPreview" class="bg-slate-700 hover:bg-slate-800 text-white font-semibold px-4 py-2 rounded">
            👀 Show Data
          </button>
          <button id="btnAnalyze" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2 rounded">
            🧠 Analyze
          </button>

          <div id="filtersText" class="ml-auto text-sm text-gray-600"></div>
        </div>

        <div id="targetsText" class="text-xs text-gray-500 mt-2"></div>
        <div id="dataStale" class="hidden text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1 mt-2">
          Filters changed. Click <b>Show Data</b> to refresh before Analyze.
        </div>
      </div>

      <!-- Data Preview -->
      <div id="dataPreview" class="bg-white p-4 rounded shadow hidden">
        <div class="flex justify-between items-center mb-3">
          <div class="text-lg font-semibold">Data Preview</div>
          <div id="dataStats" class="text-sm text-gray-600"></div>
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-100">
              <tr class="text-left">
                <th class="py-2 pr-3">Page</th>
                <th class="py-2 pr-3">Campaign</th>
                <th class="py-2 pr-3">Spend</th>
                <th class="py-2 pr-3">Impr</th>
                <th class="py-2 pr-3">Reach</th>
                <th class="py-2 pr-3">Msgs</th>
                <th class="py-2 pr-3">Purch</th>
                <th class="py-2 pr-3">CPM</th>
                <th class="py-2 pr-3">CPI</th>
                <th class="py-2 pr-3">CPP</th>
                <th class="py-2 pr-3">Freq</th>
                <th class="py-2 pr-3">Msgs/1k</th>
                <th class="py-2 pr-3">Delivery</th>
              </tr>
            </thead>
            <tbody id="kpiBody" class="align-top"></tbody>
          </table>
        </div>
      </div>

      <!-- Insights -->
      <div class="bg-white p-4 rounded shadow">
        <div id="summary" class="hidden whitespace-pre-wrap text-gray-800 mb-3"></div>

        <div id="globalActions" class="hidden mb-4">
          <div class="font-semibold mb-2">Global Actions</div>
          <ul id="gaList" class="list-disc pl-6 space-y-1"></ul>
        </div>

        <div id="campaigns" class="hidden">
          <div class="font-semibold mb-2">Per-campaign Decisions</div>
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-gray-100">
                <tr class="text-left">
                  <th class="py-2 pr-4">Page</th>
                  <th class="py-2 pr-4">Campaign</th>
                  <th class="py-2 pr-4">Decision</th>
                  <th class="py-2 pr-4">Why</th>
                  <th class="py-2 pr-4">Next Tests</th>
                </tr>
              </thead>
              <tbody id="campBody"></tbody>
            </table>
          </div>
        </div>
      </div>

    </div>

    <script>
      const qs  = s => document.querySelector(s);
      const qsv = s => (qs(s)?.value || '').trim();
      const CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

      // defaults: last 2 days
      const today = new Date();
      const twoDaysAgo = new Date(Date.now() - 2*24*60*60*1000);
      qs('#to').value   = today.toISOString().slice(0,10);
      qs('#from').value = twoDaysAgo.toISOString().slice(0,10);

      let KPIS_CACHE = [];
      let STALE = false;

      // --- helpers ---
      function renderFilters() {
        const pageSel = qs('#page'), tierSel = qs('#tier');
        const pageText = pageSel.value ? pageSel.value : 'All';
        const tierText = tierSel.options[tierSel.selectedIndex]?.text || 'Default';
        qs('#filtersText').innerHTML =
          `<b>From:</b> ${qsv('#from')} &nbsp; <b>To:</b> ${qsv('#to')} &nbsp; ` +
          `<b>Page:</b> ${escapeHtml(pageText)} &nbsp; <b>Tier:</b> ${escapeHtml(tierText)} ` +
          (qs('#includeInactive').checked ? `&nbsp; <span class="text-rose-600">(including inactive)</span>` : ``);
      }

      function computeTargets(tier) {
        return (tier === '199')
          ? { CPM_max: 10, CPP_max: 55, CPP_breakeven: null }
          : { CPM_max: 10, CPP_max: 70, CPP_breakeven: null };
      }

      function showTargets(fromServer = null) {
        const t = fromServer || computeTargets(qsv('#tier'));
        if (!qsv('#targetCpp') && t.CPP_max != null) qs('#targetCpp').value = t.CPP_max;
        if (!qsv('#breakevenCpp') && t.CPP_breakeven != null) qs('#breakevenCpp').value = t.CPP_breakeven;

        let parts = [`CPM≤₱${t.CPM_max}`];
        if (t.CPP_max != null) parts.push(`Target CPP≤₱${t.CPP_max}`);
        if (t.CPP_breakeven != null) parts.push(`Breakeven ₱${t.CPP_breakeven}`);
        qs('#targetsText').innerHTML = `Using targets → <b>${parts.join(', ')}</b>`;
      }

      function setLoading(btn, isLoading, label="Loading...") {
        if (!btn) return;
        btn.dataset.__orig = btn.dataset.__orig || btn.textContent;
        btn.disabled = !!isLoading;
        btn.classList.toggle('opacity-60', !!isLoading);
        btn.classList.toggle('cursor-not-allowed', !!isLoading);
        btn.textContent = isLoading ? `⏳ ${label}` : btn.dataset.__orig;
      }

      function fnum(v) {
        const n = parseFloat(v);
        return isNaN(n) ? null : n;
      }

      // mark stale when filters change
      ['from','to','page','tier','targetCpp','breakevenCpp','includeInactive'].forEach(id => {
        qs('#'+id).addEventListener('change', () => {
          renderFilters();
          showTargets(); // local display
          STALE = true;
          qs('#dataStale').classList.remove('hidden');
        });
      });
      renderFilters();
      showTargets();

      // --- Show Data (GET) ---
      qs('#btnPreview').addEventListener('click', async () => {
        const btn = qs('#btnPreview');
        setLoading(btn, true, 'Loading data');
        try {
          clearAnalysis();
          const params = new URLSearchParams({
            from: qsv('#from'),
            to:   qsv('#to'),
            page: qsv('#page'),
            tier: qsv('#tier'),
            mode: 'range_summary',
            target_cpp: qsv('#targetCpp'),
            breakeven_cpp: qsv('#breakevenCpp'),
            include_inactive: qs('#includeInactive').checked ? '1' : '0',
          });
          const res = await fetch(`{{ route('ads.insights.preview') }}?`+params, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
          });
          const data = await res.json();
          console.log('PREVIEW_RESPONSE', data);

          KPIS_CACHE = data.aggregated_kpis || [];
          renderDataPreview({aggregated_kpis: KPIS_CACHE});

          if (data.targets) showTargets(data.targets);

          STALE = false;
          qs('#dataStale').classList.add('hidden');

          // Optional: show how many rows were hidden
          if (data.hidden_inactive_rows > 0 && !qs('#includeInactive').checked) {
            console.info(`Filtered out ${data.hidden_inactive_rows} inactive rows.`);
          }
        } finally {
          setLoading(btn, false);
        }
      });

      // --- Analyze (POST exact KPIs shown) ---
      qs('#btnAnalyze').addEventListener('click', async () => {
        const btn = qs('#btnAnalyze');
        if (!KPIS_CACHE.length) {
          alert('Please click "Show Data" first.');
          return;
        }
        if (STALE && !confirm('Filters changed after last preview. Continue analyzing old data?')) {
          return;
        }

        setLoading(btn, true, 'Analyzing');
        try {
          const payload = {
            kpis: KPIS_CACHE,
            tier: qsv('#tier') || null,
            target_cpp: fnum(qsv('#targetCpp')),
            breakeven_cpp: fnum(qsv('#breakevenCpp')),
          };
          const res = await fetch(`{{ route('ads.insights.analyze') }}`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
              'X-CSRF-TOKEN': CSRF
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
          });
          const data = await res.json();
          console.log('ANALYZE_PAYLOAD', payload);
          console.log('ANALYZE_RESPONSE', data);

          renderDataPreview({aggregated_kpis: KPIS_CACHE});
          renderInsights(data);

          if (data.targets) showTargets(data.targets);
        } finally {
          setLoading(btn, false);
        }
      });

      // --- Renderers ---
      function renderDataPreview(data){
        const wrap = qs('#dataPreview');
        const body = qs('#kpiBody');
        const stats= qs('#dataStats');

        const kpis = data.aggregated_kpis || [];
        body.innerHTML = '';
        let totalSpend = 0;

        kpis.forEach(k => {
          totalSpend += (+k.spend||0);
          const tr = document.createElement('tr');
          tr.className = 'border-b';
          tr.innerHTML = `
            <td class="py-1 pr-3">${escapeHtml(k.page||'')}</td>
            <td class="py-1 pr-3">${escapeHtml(k.campaign||'')}</td>
            <td class="py-1 pr-3">₱${num(k.spend)}</td>
            <td class="py-1 pr-3">${num(k.impressions,0)}</td>
            <td class="py-1 pr-3">${num(k.reach,0)}</td>
            <td class="py-1 pr-3">${num(k.messages,0)}</td>
            <td class="py-1 pr-3">${num(k.purchases,0)}</td>
            <td class="py-1 pr-3">${num(k.CPM)}</td>
            <td class="py-1 pr-3">${num(k.CPI,6)}</td>
            <td class="py-1 pr-3">${num(k.CPP)}</td>
            <td class="py-1 pr-3">${num(k.frequency,3)}</td>
            <td class="py-1 pr-3">${num(k.msgs_per_1k_impr,3)}</td>
            <td class="py-1 pr-3 text-xs text-gray-500">${escapeHtml(k.campaign_delivery||'')}</td>
          `;
          body.appendChild(tr);
        });

        stats.innerHTML = `<b>Rows:</b> ${kpis.length} &nbsp;|&nbsp; <b>Total Spend:</b> ₱${num(totalSpend)}`;
        wrap.classList.remove('hidden');
      }

      function renderInsights(data) {
        const sum = qs('#summary');
        sum.classList.remove('hidden');

        if (data.error) {
          sum.textContent = '⚠️ ' + data.error;
          qs('#globalActions').classList.add('hidden');
          qs('#campaigns').classList.add('hidden');
          return;
        }

        sum.textContent = data.summary || 'No summary.';

        const gaWrap = qs('#globalActions');
        const gaList = qs('#gaList');
        gaList.innerHTML = '';
        (data.global_actions || []).forEach(a => {
          const li = document.createElement('li');
          li.innerHTML = `
            <span class="px-2 py-0.5 rounded text-white ${badgeColor(a.priority)} mr-2">${escapeHtml(a.priority||'')}</span>
            <span class="font-medium">${escapeHtml(a.action||'')}</span>
            <div class="text-gray-500 text-xs">${escapeHtml(a.reason||'')}</div>
          `;
          gaList.appendChild(li);
        });
        gaWrap.classList.toggle('hidden', (data.global_actions||[]).length === 0);

        const campWrap = qs('#campaigns');
        const body = qs('#campBody');
        body.innerHTML = '';
        (data.by_campaign || []).forEach(c => {
          const tr = document.createElement('tr');
          tr.className = 'border-b align-top';
          tr.innerHTML = `
            <td class="py-2 pr-4">${escapeHtml(c.page||'')}</td>
            <td class="py-2 pr-4">${escapeHtml(c.campaign||'')}</td>
            <td class="py-2 pr-4">${decisionBadge(c.decision)}</td>
            <td class="py-2 pr-4">${escapeHtml(c.why||'')}</td>
            <td class="py-2 pr-4">${(c.next_tests||[]).map(x=>`<div>• ${escapeHtml(x)}</div>`).join('')}</td>`;
          body.appendChild(tr);
        });
        campWrap.classList.toggle('hidden', (data.by_campaign||[]).length === 0);
      }

      // --- utils ---
      function badgeColor(p){ return {'high':'bg-red-600','medium':'bg-amber-600','low':'bg-gray-600'}[(p||'').toLowerCase()] || 'bg-gray-600'; }
      function decisionBadge(d){ const m={'scale':'bg-emerald-600','hold':'bg-gray-600','fix':'bg-blue-600','pause':'bg-rose-600'}; const cls=m[(d||'').toLowerCase()]||'bg-gray-600'; return `<span class="px-2 py-0.5 rounded text-white ${cls} capitalize">${escapeHtml(d||'')}</span>`; }
      function escapeHtml(s){return (s??'').toString().replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m]));}
      function num(v, d=2){ return (v===null||v===undefined||isNaN(v)) ? '' : Number(v).toFixed(d); }
      function clearAnalysis(){
        qs('#dataPreview').classList.add('hidden');
        qs('#kpiBody').innerHTML = '';
        qs('#dataStats').innerHTML = '';
        qs('#summary').classList.add('hidden'); qs('#summary').textContent = '';
        qs('#globalActions').classList.add('hidden'); qs('#gaList').innerHTML = '';
        qs('#campaigns').classList.add('hidden'); qs('#campBody').innerHTML = '';
      }
    </script>
  </main>
</div>
</body>
</html>
