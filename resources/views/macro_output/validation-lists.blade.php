<x-layout>
  <style>
    .tab-btn { transition: all 0.2s; }
    .tab-btn.active { border-color: #3b82f6; color: #1d4ed8; background: #eff6ff; font-weight: 600; }
    .tab-btn:not(.active) { border-color: transparent; color: #6b7280; }
    .tab-btn:not(.active):hover { color: #374151; background: #f9fafb; }
    .tab-panel { display: none; }
    .tab-panel.active { display: block; }
    .list-row { transition: background 0.15s; }
    .list-row:hover { background: #f9fafb; }
  </style>

  <div class="max-w-4xl mx-auto px-4 py-8">

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6 gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">Validation Lists</h1>
        <p class="text-sm text-gray-500 mt-1">Manage whitelists and blacklists used during order validation.</p>
      </div>
      <a href="{{ route('macro_output.index') }}" class="text-sm text-blue-600 hover:text-blue-800 hover:underline whitespace-nowrap">&larr; Back to Encoder</a>
    </div>

    {{-- Summary Cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
      <div class="bg-white rounded-xl border p-4 shadow-sm">
        <div class="text-xs text-gray-500 uppercase tracking-wide">Phone Whitelist</div>
        <div id="stat-phone" class="text-2xl font-bold text-blue-600 mt-1">—</div>
      </div>
      <div class="bg-white rounded-xl border p-4 shadow-sm">
        <div class="text-xs text-gray-500 uppercase tracking-wide">FB Name Blacklist</div>
        <div id="stat-fbname" class="text-2xl font-bold text-red-600 mt-1">—</div>
      </div>
      <div class="bg-white rounded-xl border p-4 shadow-sm">
        <div class="text-xs text-gray-500 uppercase tracking-wide">Keyword Blacklist</div>
        <div id="stat-keyword" class="text-2xl font-bold text-orange-600 mt-1">—</div>
      </div>
      <div class="bg-white rounded-xl border p-4 shadow-sm">
        <div class="text-xs text-gray-500 uppercase tracking-wide">Address Keyword</div>
        <div id="stat-addrkw" class="text-2xl font-bold text-purple-600 mt-1">—</div>
      </div>
    </div>

    {{-- Tabs --}}
    <div class="flex gap-1 border-b mb-0">
      <button class="tab-btn active px-5 py-3 text-sm border-b-2 rounded-t-lg" data-tab="phone" onclick="switchTab('phone')">
        📋 Phone Whitelist
      </button>
      <button class="tab-btn px-5 py-3 text-sm border-b-2 rounded-t-lg" data-tab="fbname" onclick="switchTab('fbname')">
        🚫 FB Name Blacklist
      </button>
      <button class="tab-btn px-5 py-3 text-sm border-b-2 rounded-t-lg" data-tab="keyword" onclick="switchTab('keyword')">
        🔑 Keyword Blacklist
      </button>
      <button class="tab-btn px-5 py-3 text-sm border-b-2 rounded-t-lg" data-tab="addrkw" onclick="switchTab('addrkw')">
        🏠 Address Keyword
      </button>
    </div>

    {{-- ═══ PHONE WHITELIST TAB ═══ --}}
    <div id="panel-phone" class="tab-panel active">
      <div class="bg-white rounded-b-xl rounded-tr-xl border border-t-0 shadow-sm">
        {{-- Add form --}}
        <div class="p-5 border-b bg-gray-50/50">
          <div class="flex flex-col sm:flex-row gap-3">
            <input type="text" id="phone-input" placeholder="Phone number (e.g. 9171234567)" class="flex-1 border rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400 outline-none" maxlength="20"/>
            <input type="text" id="phone-reason" placeholder="Reason (optional)" class="flex-1 border rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400 outline-none" maxlength="255"/>
            <button onclick="addPhone()" class="bg-emerald-600 hover:bg-emerald-700 text-white text-sm px-6 py-2.5 rounded-lg font-medium transition-colors whitespace-nowrap">+ Add</button>
          </div>
          <div id="phone-error" class="text-red-500 text-xs mt-2 hidden"></div>
          <div id="phone-success" class="text-emerald-600 text-xs mt-2 hidden"></div>
        </div>
        {{-- Search --}}
        <div class="px-5 pt-4">
          <input type="text" id="phone-search" placeholder="Search..." class="w-full border rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-blue-300 outline-none" oninput="filterList('phone')"/>
        </div>
        {{-- Count --}}
        <div class="px-5 pt-3 pb-2">
          <span id="phone-count" class="text-xs text-gray-500">Loading...</span>
        </div>
        {{-- List --}}
        <div id="phone-list" class="px-5 pb-5">
          <div class="text-gray-400 text-sm text-center py-8">Loading...</div>
        </div>
      </div>
    </div>

    {{-- ═══ FB NAME BLACKLIST TAB ═══ --}}
    <div id="panel-fbname" class="tab-panel">
      <div class="bg-white rounded-b-xl rounded-tr-xl border border-t-0 shadow-sm">
        {{-- Add form --}}
        <div class="p-5 border-b bg-gray-50/50">
          <div class="flex flex-col sm:flex-row gap-3">
            <input type="text" id="fbname-input" placeholder="FB Name to blacklist" class="flex-1 border rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-red-300 focus:border-red-400 outline-none" maxlength="255"/>
            <input type="text" id="fbname-reason" placeholder="Reason (optional)" class="flex-1 border rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-red-300 focus:border-red-400 outline-none" maxlength="255"/>
            <button onclick="addFbname()" class="bg-red-600 hover:bg-red-700 text-white text-sm px-6 py-2.5 rounded-lg font-medium transition-colors whitespace-nowrap">+ Add</button>
          </div>
          <div id="fbname-error" class="text-red-500 text-xs mt-2 hidden"></div>
          <div id="fbname-success" class="text-emerald-600 text-xs mt-2 hidden"></div>
        </div>
        {{-- Search --}}
        <div class="px-5 pt-4">
          <input type="text" id="fbname-search" placeholder="Search..." class="w-full border rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-red-300 outline-none" oninput="filterList('fbname')"/>
        </div>
        {{-- Count --}}
        <div class="px-5 pt-3 pb-2">
          <span id="fbname-count" class="text-xs text-gray-500">Loading...</span>
        </div>
        {{-- List --}}
        <div id="fbname-list" class="px-5 pb-5">
          <div class="text-gray-400 text-sm text-center py-8">Loading...</div>
        </div>
      </div>
    </div>

    {{-- ═══ KEYWORD BLACKLIST TAB ═══ --}}
    <div id="panel-keyword" class="tab-panel">
      <div class="bg-white rounded-b-xl rounded-tr-xl border border-t-0 shadow-sm">
        {{-- Add form --}}
        <div class="p-5 border-b bg-gray-50/50">
          <div class="flex flex-col sm:flex-row gap-3">
            <input type="text" id="keyword-input" placeholder="Keyword to blacklist" class="flex-1 border rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-300 focus:border-orange-400 outline-none" maxlength="255"/>
            <input type="text" id="keyword-reason" placeholder="Reason (optional)" class="flex-1 border rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-orange-300 focus:border-orange-400 outline-none" maxlength="255"/>
            <button onclick="addKeyword()" class="bg-orange-600 hover:bg-orange-700 text-white text-sm px-6 py-2.5 rounded-lg font-medium transition-colors whitespace-nowrap">+ Add</button>
          </div>
          <div id="keyword-error" class="text-red-500 text-xs mt-2 hidden"></div>
          <div id="keyword-success" class="text-emerald-600 text-xs mt-2 hidden"></div>
        </div>
        {{-- Search --}}
        <div class="px-5 pt-4">
          <input type="text" id="keyword-search" placeholder="Search..." class="w-full border rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-orange-300 outline-none" oninput="filterList('keyword')"/>
        </div>
        {{-- Count --}}
        <div class="px-5 pt-3 pb-2">
          <span id="keyword-count" class="text-xs text-gray-500">Loading...</span>
        </div>
        {{-- List --}}
        <div id="keyword-list" class="px-5 pb-5">
          <div class="text-gray-400 text-sm text-center py-8">Loading...</div>
        </div>
      </div>
    </div>

    {{-- ═══ ADDRESS KEYWORD BLACKLIST TAB ═══ --}}
    <div id="panel-addrkw" class="tab-panel">
      <div class="bg-white rounded-b-xl rounded-tr-xl border border-t-0 shadow-sm">
        <div class="px-5 pt-4 text-xs text-gray-500">
          Hinahanap sa <strong>ADDRESS (Line 1)</strong> tuwing Validate / Validate 1. Pag may tumama (partial, case-insensitive) → <strong>invalid / TO FIX</strong> ang address. (Blangkong address = invalid din.)
        </div>
        {{-- Add form --}}
        <div class="p-5 border-b bg-gray-50/50">
          <div class="flex flex-col sm:flex-row gap-3">
            <input type="text" id="addrkw-input" placeholder="Keyword sa address (hal. 'wala', 'n/a', 'tba')" class="flex-1 border rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-purple-300 focus:border-purple-400 outline-none" maxlength="255"/>
            <input type="text" id="addrkw-reason" placeholder="Reason (optional)" class="flex-1 border rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-purple-300 focus:border-purple-400 outline-none" maxlength="255"/>
            <button onclick="addAddrkw()" class="bg-purple-600 hover:bg-purple-700 text-white text-sm px-6 py-2.5 rounded-lg font-medium transition-colors whitespace-nowrap">+ Add</button>
          </div>
          <div id="addrkw-error" class="text-red-500 text-xs mt-2 hidden"></div>
          <div id="addrkw-success" class="text-emerald-600 text-xs mt-2 hidden"></div>
        </div>
        {{-- Search --}}
        <div class="px-5 pt-4">
          <input type="text" id="addrkw-search" placeholder="Search..." class="w-full border rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-purple-300 outline-none" oninput="filterList('addrkw')"/>
        </div>
        {{-- Count --}}
        <div class="px-5 pt-3 pb-2">
          <span id="addrkw-count" class="text-xs text-gray-500">Loading...</span>
        </div>
        {{-- List --}}
        <div id="addrkw-list" class="px-5 pb-5">
          <div class="text-gray-400 text-sm text-center py-8">Loading...</div>
        </div>
      </div>
    </div>

  </div>

  <script>
    const csrf = '{{ csrf_token() }}';
    const listData = { phone: [], fbname: [], keyword: [], addrkw: [] };
    let canDeleteMap = { phone: false, fbname: false, keyword: false, addrkw: false };

    /* ─── TABS ─── */
    function switchTab(tab) {
      document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
      document.querySelector(`.tab-btn[data-tab="${tab}"]`).classList.add('active');
      document.getElementById(`panel-${tab}`).classList.add('active');
    }

    /* ─── GENERIC RENDER ─── */
    function renderList(type, items) {
      const listEl = document.getElementById(`${type}-list`);
      const countEl = document.getElementById(`${type}-count`);

      if (!items || items.length === 0) {
        listEl.innerHTML = '<div class="text-gray-400 text-sm text-center py-8">No entries yet.</div>';
        countEl.textContent = '0 entries';
        return;
      }

      const labelField = type === 'phone' ? 'phone_number' : (type === 'fbname' ? 'fb_name' : 'keyword');
      const colorClass = type === 'phone' ? 'bg-blue-50 text-blue-800'
        : (type === 'fbname' ? 'bg-red-50 text-red-800'
        : (type === 'addrkw' ? 'bg-purple-50 text-purple-800' : 'bg-orange-50 text-orange-800'));
      const canDel = canDeleteMap[type];

      listEl.innerHTML = '';
      items.forEach((item, idx) => {
        const div = document.createElement('div');
        div.className = `list-row flex items-center justify-between py-3 ${idx < items.length - 1 ? 'border-b' : ''}`;

        const val = item[labelField] || '';
        const reason = item.reason || '';
        const creator = item.creator ? item.creator.name : 'Unknown';
        const date = new Date(item.created_at).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });

        div.innerHTML = `
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-3 flex-wrap">
              <span class="font-mono text-sm font-semibold px-2.5 py-1 rounded ${colorClass}">${escHtml(val)}</span>
              ${reason ? `<span class="text-sm text-gray-600 truncate">${escHtml(reason)}</span>` : '<span class="text-sm text-gray-400 italic">No reason</span>'}
            </div>
            <div class="text-[11px] text-gray-400 mt-1">
              Added by <span class="font-medium text-gray-500">${escHtml(creator)}</span> &middot; ${date}
            </div>
          </div>
          ${canDel ? `<button onclick="deleteEntry('${type}', ${item.id}, '${escAttr(val)}')" class="ml-4 text-red-400 hover:text-red-600 hover:bg-red-50 text-xs px-3 py-1.5 rounded-lg transition-colors font-medium whitespace-nowrap">Delete</button>` : ''}
        `;
        listEl.appendChild(div);
      });

      countEl.textContent = `${items.length} entr${items.length !== 1 ? 'ies' : 'y'}`;
    }

    function filterList(type) {
      const query = (document.getElementById(`${type}-search`).value || '').trim().toLowerCase();
      const all = listData[type] || [];
      if (!query) { renderList(type, all); return; }

      const labelField = type === 'phone' ? 'phone_number' : (type === 'fbname' ? 'fb_name' : 'keyword');
      const filtered = all.filter(item =>
        (item[labelField] || '').toLowerCase().includes(query) ||
        (item.reason || '').toLowerCase().includes(query) ||
        (item.creator?.name || '').toLowerCase().includes(query)
      );
      renderList(type, filtered);
      document.getElementById(`${type}-count`).textContent = `${filtered.length} of ${all.length} shown`;
    }

    /* ─── LOAD DATA ─── */
    async function loadAll() {
      await Promise.all([loadList('phone'), loadList('fbname'), loadList('keyword'), loadList('addrkw')]);
    }

    async function loadList(type) {
      const urls = {
        phone: '/validation-lists/phone/data',
        fbname: '/validation-lists/fbname/data',
        keyword: '/validation-lists/keyword/data',
        addrkw: '/validation-lists/address-keyword/data',
      };

      try {
        const res = await fetch(urls[type], { headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf } });
        const data = await res.json();
        listData[type] = data.items || [];
        canDeleteMap[type] = data.can_delete || false;
        renderList(type, listData[type]);

        // Update stat cards
        const statEl = document.getElementById(`stat-${type}`);
        if (statEl) statEl.textContent = listData[type].length;
      } catch(e) {
        document.getElementById(`${type}-list`).innerHTML = '<div class="text-red-500 text-sm text-center py-8">Failed to load.</div>';
      }
    }

    /* ─── ADD ENTRIES ─── */
    async function addPhone() {
      await addEntry('phone', '/validation-lists/phone', {
        phone_number: document.getElementById('phone-input').value.trim(),
        reason: document.getElementById('phone-reason').value.trim() || null,
      }, 'phone-input', 'phone-reason');
    }

    async function addFbname() {
      await addEntry('fbname', '/validation-lists/fbname', {
        fb_name: document.getElementById('fbname-input').value.trim(),
        reason: document.getElementById('fbname-reason').value.trim() || null,
      }, 'fbname-input', 'fbname-reason');
    }

    async function addKeyword() {
      await addEntry('keyword', '/validation-lists/keyword', {
        keyword: document.getElementById('keyword-input').value.trim(),
        reason: document.getElementById('keyword-reason').value.trim() || null,
      }, 'keyword-input', 'keyword-reason');
    }

    async function addAddrkw() {
      await addEntry('addrkw', '/validation-lists/address-keyword', {
        keyword: document.getElementById('addrkw-input').value.trim(),
        reason: document.getElementById('addrkw-reason').value.trim() || null,
      }, 'addrkw-input', 'addrkw-reason');
    }

    async function addEntry(type, url, body, inputId, reasonId) {
      const errorEl = document.getElementById(`${type}-error`);
      const successEl = document.getElementById(`${type}-success`);
      errorEl.classList.add('hidden');
      successEl.classList.add('hidden');

      const mainVal = Object.values(body)[0];
      if (!mainVal) {
        errorEl.textContent = 'Value is required.';
        errorEl.classList.remove('hidden');
        return;
      }

      try {
        const res = await fetch(url, {
          method: 'POST',
          headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
          body: JSON.stringify(body),
        });
        const data = await res.json();

        if (!res.ok) {
          errorEl.textContent = data.error || data.message || 'Failed to add.';
          errorEl.classList.remove('hidden');
          return;
        }

        successEl.textContent = 'Added successfully!';
        successEl.classList.remove('hidden');
        document.getElementById(inputId).value = '';
        document.getElementById(reasonId).value = '';
        document.getElementById(`${type}-search`).value = '';
        loadList(type);
        setTimeout(() => successEl.classList.add('hidden'), 3000);
      } catch(e) {
        errorEl.textContent = 'Network error.';
        errorEl.classList.remove('hidden');
      }
    }

    /* ─── DELETE ─── */
    async function deleteEntry(type, id, label) {
      if (!confirm(`Remove "${label}" from the list?`)) return;

      const urls = {
        phone: '/validation-lists/phone/',
        fbname: '/validation-lists/fbname/',
        keyword: '/validation-lists/keyword/',
        addrkw: '/validation-lists/address-keyword/',
      };

      try {
        await fetch(urls[type] + id, {
          method: 'DELETE',
          headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        });
        document.getElementById(`${type}-search`).value = '';
        loadList(type);
      } catch(e) {
        alert('Failed to delete.');
      }
    }

    /* ─── UTILS ─── */
    function escHtml(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function escAttr(s) { return s.replace(/'/g, "\\'").replace(/"/g, '&quot;'); }

    /* ─── Enter key support ─── */
    ['phone-input','phone-reason'].forEach(id => {
      document.getElementById(id)?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addPhone(); } });
    });
    ['fbname-input','fbname-reason'].forEach(id => {
      document.getElementById(id)?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addFbname(); } });
    });
    ['keyword-input','keyword-reason'].forEach(id => {
      document.getElementById(id)?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addKeyword(); } });
    });
    ['addrkw-input','addrkw-reason'].forEach(id => {
      document.getElementById(id)?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addAddrkw(); } });
    });

    /* ─── INIT ─── */
    document.addEventListener('DOMContentLoaded', () => loadAll());
  </script>
</x-layout>
