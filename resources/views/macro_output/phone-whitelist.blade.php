<x-layout>
  <div class="max-w-3xl mx-auto px-4 py-8">

    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">📋 Phone Whitelist</h1>
        <p class="text-sm text-gray-500 mt-1">Whitelisted phone numbers are allowed to have duplicate orders without being flagged during validation.</p>
      </div>
      <a href="{{ route('macro_output.index') }}" class="text-sm text-blue-600 hover:text-blue-800 hover:underline">&larr; Back to Encoder</a>
    </div>

    {{-- Add form --}}
    <div class="bg-white rounded-xl shadow-sm border p-5 mb-6">
      <h2 class="text-sm font-semibold text-gray-700 mb-3">Add Phone Number</h2>
      <div class="flex flex-col sm:flex-row gap-3">
        <input type="text" id="wl-phone" placeholder="Phone number (e.g. 9171234567)" class="flex-1 border rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400 outline-none" maxlength="20"/>
        <input type="text" id="wl-reason" placeholder="Reason (optional)" class="flex-1 border rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400 outline-none" maxlength="255"/>
        <button onclick="addWhitelistPhone()" class="bg-emerald-600 hover:bg-emerald-700 text-white text-sm px-6 py-2.5 rounded-lg font-medium transition-colors whitespace-nowrap">
          + Add
        </button>
      </div>
      <div id="wl-error" class="text-red-500 text-xs mt-2 hidden"></div>
      <div id="wl-success" class="text-emerald-600 text-xs mt-2 hidden"></div>
    </div>

    {{-- Search --}}
    <div class="mb-4">
      <input type="text" id="wl-search" placeholder="Search phone number or reason..." class="w-full border rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400 outline-none" oninput="filterWhitelist()"/>
    </div>

    {{-- Stats --}}
    <div class="flex items-center justify-between mb-3">
      <span id="wl-count" class="text-sm text-gray-500">Loading...</span>
    </div>

    {{-- List --}}
    <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
      <div id="wl-list">
        <div class="text-gray-400 text-sm text-center py-8">Loading...</div>
      </div>
    </div>

  </div>

  <script>
    const csrfTokenWL = '{{ csrf_token() }}';
    let whitelistCanDelete = false;
    let whitelistItems = [];

    // Load on page ready
    document.addEventListener('DOMContentLoaded', () => loadWhitelist());

    async function loadWhitelist() {
      const listEl = document.getElementById('wl-list');
      const countEl = document.getElementById('wl-count');
      listEl.innerHTML = '<div class="text-gray-400 text-sm text-center py-8">Loading...</div>';

      try {
        const res = await fetch('/phone-whitelist/data', {
          headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfTokenWL }
        });
        const data = await res.json();
        whitelistCanDelete = data.can_delete;
        whitelistItems = data.items || [];

        countEl.textContent = `${whitelistItems.length} whitelisted number${whitelistItems.length !== 1 ? 's' : ''}`;
        renderWhitelist(whitelistItems);
      } catch(e) {
        listEl.innerHTML = '<div class="text-red-500 text-sm text-center py-8">Failed to load.</div>';
        countEl.textContent = '';
      }
    }

    function renderWhitelist(items) {
      const listEl = document.getElementById('wl-list');

      if (!items || items.length === 0) {
        listEl.innerHTML = '<div class="text-gray-400 text-sm text-center py-8">No whitelisted numbers found.</div>';
        return;
      }

      listEl.innerHTML = '';
      items.forEach((item, idx) => {
        const div = document.createElement('div');
        div.className = `flex items-center justify-between px-5 py-3 ${idx < items.length - 1 ? 'border-b' : ''} hover:bg-gray-50 transition-colors`;
        div.setAttribute('data-phone', item.phone_number);
        div.setAttribute('data-reason', item.reason || '');

        const createdDate = new Date(item.created_at).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
        const creatorName = item.creator ? item.creator.name : 'Unknown';

        div.innerHTML = `
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-3">
              <span class="font-mono text-sm font-semibold text-gray-800 bg-gray-100 px-2.5 py-1 rounded">${item.phone_number}</span>
              ${item.reason ? `<span class="text-sm text-gray-600 truncate">${item.reason}</span>` : '<span class="text-sm text-gray-400 italic">No reason</span>'}
            </div>
            <div class="text-[11px] text-gray-400 mt-1">
              Added by <span class="font-medium text-gray-500">${creatorName}</span> &middot; ${createdDate}
            </div>
          </div>
          ${whitelistCanDelete ? `
            <button onclick="deleteWhitelistPhone(${item.id}, '${item.phone_number}')" class="ml-4 text-red-400 hover:text-red-600 hover:bg-red-50 text-xs px-3 py-1.5 rounded-lg transition-colors font-medium">
              Delete
            </button>
          ` : ''}
        `;
        listEl.appendChild(div);
      });
    }

    function filterWhitelist() {
      const query = (document.getElementById('wl-search').value || '').trim().toLowerCase();
      if (!query) {
        renderWhitelist(whitelistItems);
        document.getElementById('wl-count').textContent = `${whitelistItems.length} whitelisted number${whitelistItems.length !== 1 ? 's' : ''}`;
        return;
      }

      const filtered = whitelistItems.filter(item =>
        (item.phone_number || '').toLowerCase().includes(query) ||
        (item.reason || '').toLowerCase().includes(query) ||
        (item.creator?.name || '').toLowerCase().includes(query)
      );

      renderWhitelist(filtered);
      document.getElementById('wl-count').textContent = `${filtered.length} of ${whitelistItems.length} shown`;
    }

    async function addWhitelistPhone() {
      const phoneEl = document.getElementById('wl-phone');
      const reasonEl = document.getElementById('wl-reason');
      const errorEl = document.getElementById('wl-error');
      const successEl = document.getElementById('wl-success');

      errorEl.classList.add('hidden');
      successEl.classList.add('hidden');
      errorEl.textContent = '';
      successEl.textContent = '';

      const phone = phoneEl.value.trim();
      if (!phone) {
        errorEl.textContent = 'Phone number is required.';
        errorEl.classList.remove('hidden');
        return;
      }

      try {
        const res = await fetch('/phone-whitelist', {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfTokenWL
          },
          body: JSON.stringify({ phone_number: phone, reason: reasonEl.value.trim() || null })
        });

        const data = await res.json();

        if (!res.ok) {
          errorEl.textContent = data.error || data.message || 'Failed to add.';
          errorEl.classList.remove('hidden');
          return;
        }

        successEl.textContent = `Phone number ${phone} added to whitelist.`;
        successEl.classList.remove('hidden');
        phoneEl.value = '';
        reasonEl.value = '';
        document.getElementById('wl-search').value = '';
        loadWhitelist();

        setTimeout(() => { successEl.classList.add('hidden'); }, 3000);
      } catch(e) {
        errorEl.textContent = 'Network error.';
        errorEl.classList.remove('hidden');
      }
    }

    async function deleteWhitelistPhone(id, phone) {
      if (!confirm(`Remove ${phone} from whitelist?`)) return;

      try {
        const res = await fetch('/phone-whitelist/' + id, {
          method: 'DELETE',
          headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfTokenWL
          }
        });

        if (res.ok) {
          document.getElementById('wl-search').value = '';
          loadWhitelist();
        } else {
          alert('Failed to delete.');
        }
      } catch(e) {
        alert('Failed to delete.');
      }
    }

    // Enter key to submit
    document.getElementById('wl-phone')?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); addWhitelistPhone(); }
    });
    document.getElementById('wl-reason')?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); addWhitelistPhone(); }
    });
  </script>
</x-layout>
