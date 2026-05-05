<x-layout>
  <x-slot name="title">System Monitor</x-slot>
  <x-slot name="heading">🖥 System Monitor (CEO)</x-slot>

  <style>
    .sm-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .sm-card-header { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
    .sm-title { font-size:13px; font-weight:600; color:#0f172a; }
    .sm-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; padding:14px; }
    .sm-cell { background:#f8fafc; padding:10px 12px; border-radius:8px; border:1px solid #e2e8f0; }
    .sm-cell .label { font-size:10.5px; color:#64748b; text-transform:uppercase; letter-spacing:0.05em; }
    .sm-cell .value { font-size:18px; font-weight:700; color:#0f172a; margin-top:2px; }
    .sm-bar { width:100%; background:#e5e7eb; height:6px; border-radius:999px; overflow:hidden; margin-top:6px; }
    .sm-bar > div { height:100%; transition:width 0.3s; }
    .sm-bar-green > div { background:#22c55e; }
    .sm-bar-blue > div { background:#3b82f6; }
    .sm-bar-amber > div { background:#f59e0b; }
    .sm-bar-red > div { background:#ef4444; }

    .sm-table { width:100%; font-size:12px; border-collapse:collapse; }
    .sm-table th { background:#f8fafc; padding:6px 10px; text-align:left; font-weight:600; color:#475569; border-bottom:1px solid #e2e8f0; }
    .sm-table td { padding:5px 10px; border-bottom:1px solid #f1f5f9; }
    .sm-table tr:hover { background:#f8fafc; }
    .sm-table .num { text-align:right; font-variant-numeric:tabular-nums; }

    .badge-pill { display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:10.5px; font-weight:600; }
    .badge-green { background:#dcfce7; color:#166534; }
    .badge-amber { background:#fef3c7; color:#92400e; }
    .badge-red   { background:#fee2e2; color:#991b1b; }
    .badge-blue  { background:#dbeafe; color:#1e40af; }
  </style>

  <div class="w-full flex flex-col gap-4 p-2">
    <!-- Top header with refresh status -->
    <div class="flex justify-between items-center px-1">
      <div class="text-xs text-slate-500">
        Auto-refresh every 15 secs.
        Last update: <span id="lastUpdate" class="font-semibold text-slate-700">—</span>
      </div>
      <button id="btnRefreshNow" class="text-xs bg-slate-700 hover:bg-slate-800 text-white px-3 py-1.5 rounded">
        🔄 Refresh now
      </button>
    </div>

    <!-- Section 1: Server stats -->
    <div class="sm-card">
      <div class="sm-card-header">
        <div class="sm-title">📊 Server stats</div>
        <span id="serverHealth" class="badge-pill badge-green">HEALTHY</span>
      </div>
      <div class="sm-grid">
        <div class="sm-cell">
          <div class="label">Memory</div>
          <div class="value" id="memUsed">— / —</div>
          <div class="sm-bar sm-bar-green"><div id="memBar" style="width:0%"></div></div>
          <div class="text-[10.5px] text-slate-500 mt-1"><span id="memPct">0%</span> used</div>
        </div>
        <div class="sm-cell">
          <div class="label">Memory available</div>
          <div class="value" id="memAvailable" style="color:#15803d;">—</div>
        </div>
        <div class="sm-cell">
          <div class="label">Swap</div>
          <div class="value" id="swapUsed">—</div>
        </div>
        <div class="sm-cell">
          <div class="label">CPU load</div>
          <div class="value" id="loadAvg">—</div>
          <div class="text-[10.5px] text-slate-500 mt-1"><span id="cpuCount">—</span> cores</div>
        </div>
        <div class="sm-cell">
          <div class="label">Uptime</div>
          <div class="value" id="uptime" style="font-size:14px;">—</div>
        </div>
      </div>
    </div>

    <!-- Section 2: Disk usage -->
    <div class="sm-card">
      <div class="sm-card-header">
        <div class="sm-title">💾 Disk usage</div>
        <span id="diskHealth" class="badge-pill badge-green">OK</span>
      </div>
      <div class="sm-grid">
        <div class="sm-cell">
          <div class="label">Root (/) total</div>
          <div class="value" id="diskTotal">—</div>
        </div>
        <div class="sm-cell">
          <div class="label">Used</div>
          <div class="value" id="diskUsed" style="color:#1d4ed8;">—</div>
          <div class="sm-bar sm-bar-blue"><div id="diskBar" style="width:0%"></div></div>
          <div class="text-[10.5px] text-slate-500 mt-1"><span id="diskPct">0%</span> used</div>
        </div>
        <div class="sm-cell">
          <div class="label">Free</div>
          <div class="value" id="diskFree" style="color:#15803d;">—</div>
        </div>
      </div>
    </div>

    <!-- Section 3: Database stats -->
    <div class="sm-card">
      <div class="sm-card-header">
        <div class="sm-title">🗃 Database tables (top 30 by size)</div>
        <span class="text-xs text-slate-500">
          Total: <span id="dbTotalMb" class="font-semibold text-slate-700">—</span> MB •
          <span id="dbTableCount">—</span> tables •
          <span id="dbTotalRows">—</span> rows
        </span>
      </div>
      <div class="overflow-auto" style="max-height:400px;">
        <table class="sm-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Table</th>
              <th class="num">Rows</th>
              <th class="num">Data MB</th>
              <th class="num">Index MB</th>
              <th class="num">Total MB</th>
            </tr>
          </thead>
          <tbody id="dbTablesBody">
            <tr><td colspan="6" class="text-center text-slate-400 py-4">Loading...</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Section 4: File storage -->
    <div class="sm-card">
      <div class="sm-card-header">
        <div class="sm-title">📁 File storage</div>
        <span class="text-xs text-slate-500">Cached 2 mins (slow scan)</span>
      </div>
      <div class="overflow-auto">
        <table class="sm-table">
          <thead>
            <tr>
              <th>Directory</th>
              <th>Path</th>
              <th class="num">Files</th>
              <th class="num">Size MB</th>
            </tr>
          </thead>
          <tbody id="storageBody">
            <tr><td colspan="4" class="text-center text-slate-400 py-4">Loading...</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Section 5: PHP processes -->
    <div class="sm-card">
      <div class="sm-card-header">
        <div class="sm-title">🔧 PHP processes + MySQL</div>
        <span class="text-xs text-slate-500">
          MySQL: <span id="mysqlMb" class="font-semibold text-slate-700">—</span> MB •
          PHP-FPM: <span id="fpmCount">—</span> procs (<span id="fpmTotalMb">—</span> MB)
        </span>
      </div>
      <div class="overflow-auto" style="max-height:400px;">
        <table class="sm-table">
          <thead>
            <tr>
              <th class="num">PID</th>
              <th>Queue</th>
              <th class="num">RSS (MB)</th>
            </tr>
          </thead>
          <tbody id="workersBody">
            <tr><td colspan="3" class="text-center text-slate-400 py-4">Loading...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <script>
    const csrfToken = '{{ csrf_token() }}';

    function fmtBytes(mb) {
      if (mb === null || mb === undefined) return '—';
      if (mb < 1024) return mb.toLocaleString() + ' MB';
      return (mb / 1024).toFixed(2) + ' GB';
    }

    function fmtUptime(secs) {
      if (!secs) return '—';
      const d = Math.floor(secs / 86400);
      const h = Math.floor((secs % 86400) / 3600);
      const m = Math.floor((secs % 3600) / 60);
      if (d > 0) return `${d}d ${h}h ${m}m`;
      if (h > 0) return `${h}h ${m}m`;
      return `${m}m`;
    }

    function setText(id, val) {
      const el = document.getElementById(id);
      if (el) el.textContent = val;
    }

    function applyData(data) {
      // ===== Server =====
      const srv = data.server || {};
      setText('memUsed', `${(srv.memory_used_mb || 0).toLocaleString()} / ${(srv.memory_total_mb || 0).toLocaleString()} MB`);
      setText('memPct', `${srv.memory_used_percent || 0}%`);
      setText('memAvailable', fmtBytes(srv.memory_available_mb || 0));
      setText('swapUsed', `${srv.swap_used_mb || 0} / ${srv.swap_total_mb || 0} MB`);

      const memBar = document.getElementById('memBar');
      if (memBar) {
        const pct = srv.memory_used_percent || 0;
        memBar.style.width = pct + '%';
        const parent = memBar.parentElement;
        if (parent) {
          parent.classList.remove('sm-bar-green', 'sm-bar-amber', 'sm-bar-red');
          if (pct < 70) parent.classList.add('sm-bar-green');
          else if (pct < 90) parent.classList.add('sm-bar-amber');
          else parent.classList.add('sm-bar-red');
        }
      }

      // Server health badge
      const sh = document.getElementById('serverHealth');
      if (sh) {
        if ((srv.memory_used_percent || 0) > 90) {
          sh.textContent = 'HIGH MEM'; sh.className = 'badge-pill badge-red';
        } else if ((srv.memory_used_percent || 0) > 75) {
          sh.textContent = 'MODERATE'; sh.className = 'badge-pill badge-amber';
        } else {
          sh.textContent = 'HEALTHY'; sh.className = 'badge-pill badge-green';
        }
      }

      const loadStr = (srv.load_1m !== null && srv.load_1m !== undefined)
        ? `${srv.load_1m} / ${srv.load_5m || 0} / ${srv.load_15m || 0}`
        : '—';
      setText('loadAvg', loadStr);
      setText('cpuCount', srv.cpu_count || '—');
      setText('uptime', fmtUptime(srv.uptime_seconds || 0));

      // ===== Disk =====
      const dsk = data.disk || {};
      setText('diskTotal', fmtBytes((dsk.root_total_gb || 0) * 1024));
      setText('diskUsed', fmtBytes((dsk.root_used_gb || 0) * 1024));
      setText('diskFree', fmtBytes((dsk.root_free_gb || 0) * 1024));
      setText('diskPct', `${dsk.root_percent || 0}%`);

      const diskBar = document.getElementById('diskBar');
      if (diskBar) {
        const pct = dsk.root_percent || 0;
        diskBar.style.width = pct + '%';
        const parent = diskBar.parentElement;
        if (parent) {
          parent.classList.remove('sm-bar-blue', 'sm-bar-amber', 'sm-bar-red');
          if (pct < 75) parent.classList.add('sm-bar-blue');
          else if (pct < 90) parent.classList.add('sm-bar-amber');
          else parent.classList.add('sm-bar-red');
        }
      }

      const dh = document.getElementById('diskHealth');
      if (dh) {
        const pct = dsk.root_percent || 0;
        if (pct > 90) { dh.textContent = 'CRITICAL'; dh.className = 'badge-pill badge-red'; }
        else if (pct > 75) { dh.textContent = 'WATCH'; dh.className = 'badge-pill badge-amber'; }
        else { dh.textContent = 'OK'; dh.className = 'badge-pill badge-green'; }
      }

      // ===== Database =====
      const db = data.database || {};
      setText('dbTotalMb', (db.total_size_mb || 0).toLocaleString());
      setText('dbTableCount', (db.table_count || 0).toLocaleString());
      setText('dbTotalRows', (db.total_rows || 0).toLocaleString());

      const tbody = document.getElementById('dbTablesBody');
      if (tbody && Array.isArray(db.tables)) {
        if (db.tables.length === 0) {
          tbody.innerHTML = '<tr><td colspan="6" class="text-center text-slate-400 py-4">No tables</td></tr>';
        } else {
          tbody.innerHTML = db.tables.map((t, i) => `
            <tr>
              <td>${i + 1}</td>
              <td><code class="text-[11px]">${t.name}</code></td>
              <td class="num">${(t.rows || 0).toLocaleString()}</td>
              <td class="num">${(t.data_mb || 0).toLocaleString()}</td>
              <td class="num">${(t.index_mb || 0).toLocaleString()}</td>
              <td class="num font-semibold">${(t.total_mb || 0).toLocaleString()}</td>
            </tr>
          `).join('');
        }
      }

      // ===== Storage =====
      const stg = data.storage || {};
      const storageBody = document.getElementById('storageBody');
      if (storageBody) {
        const rows = Object.entries(stg).map(([key, info]) => {
          const path = (info && info.path) ? info.path : '—';
          const size = (info && info.size_mb !== undefined) ? info.size_mb : 0;
          const files = (info && info.file_count !== undefined) ? info.file_count : 0;
          const exists = info && info.exists;
          const labelClass = exists ? '' : 'text-slate-400';
          return `
            <tr class="${labelClass}">
              <td><strong>${key}</strong></td>
              <td><code class="text-[11px] text-slate-500">${path}</code></td>
              <td class="num">${(files || 0).toLocaleString()}</td>
              <td class="num font-semibold">${(size || 0).toLocaleString()}</td>
            </tr>
          `;
        });
        storageBody.innerHTML = rows.length
          ? rows.join('')
          : '<tr><td colspan="4" class="text-center text-slate-400 py-4">No storage data</td></tr>';
      }

      // ===== Processes =====
      const proc = data.processes || {};
      setText('mysqlMb', (proc.mysql_mb || 0).toLocaleString());
      setText('fpmCount', proc.fpm_count || 0);
      setText('fpmTotalMb', (proc.fpm_total_mb || 0).toLocaleString());

      const wb = document.getElementById('workersBody');
      if (wb && Array.isArray(proc.queue_workers)) {
        if (proc.queue_workers.length === 0) {
          wb.innerHTML = '<tr><td colspan="3" class="text-center text-slate-400 py-4">No queue workers</td></tr>';
        } else {
          wb.innerHTML = proc.queue_workers.map(w => `
            <tr>
              <td class="num">${w.pid}</td>
              <td><span class="badge-pill badge-blue">${w.queue}</span></td>
              <td class="num font-semibold">${w.rss_mb}</td>
            </tr>
          `).join('');
        }
      }

      setText('lastUpdate', data.fetched_at || '—');
    }

    async function fetchData() {
      try {
        const res = await fetch('/system-monitor/data', { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const data = await res.json();
        applyData(data);
      } catch (e) {
        console.warn('System monitor fetch error', e);
      }
    }

    // Initial fetch + interval
    fetchData();
    setInterval(fetchData, 15000);

    document.getElementById('btnRefreshNow')?.addEventListener('click', () => {
      fetchData();
    });
  </script>
</x-layout>
