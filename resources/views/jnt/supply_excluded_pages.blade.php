<x-layout>
  <x-slot name="title">Supply — Excluded Pages</x-slot>
  <x-slot name="heading">Supply Planner · Excluded Pages (multi-item)</x-slot>

  <style>
    .save-toast { position:fixed; bottom:24px; right:24px; background:#16a34a; color:white;
                  padding:8px 18px; border-radius:8px; font-size:13px; font-weight:600;
                  display:none; z-index:9999; box-shadow:0 4px 12px rgba(0,0,0,.15); }
  </style>

  <div class="mx-auto px-4 py-4" style="max-width:1000px;">

    <div class="mb-4 flex items-center gap-3 flex-wrap">
      <a href="{{ url('/jnt/supply/config') }}"
         class="text-sm px-3 py-1.5 rounded-md border border-gray-300 hover:bg-gray-50 text-gray-700">
        ← Back to Config
      </a>
      <a href="{{ url('/jnt/supply') }}"
         class="text-sm px-3 py-1.5 rounded-md border border-gray-300 hover:bg-gray-50 text-gray-700">
        ← Back to Supply
      </a>
    </div>

    <div class="bg-white rounded-lg shadow border border-rose-200 p-4 mb-4">
      <p class="text-sm text-gray-700 mb-2">
        Pages listed here are <strong>excluded only from Projected Profit%</strong> calculation.
        Ganun pa rin ang ibang columns (velocity, class, lifecycle, etc.) — di sila maaapektuhan.
      </p>
      <p class="text-xs text-gray-500">
        Gamitin ito para sa mga pages na may <em>mixed/multi-item mix</em> na dahilan para hindi accurate ang per-item profit attribution.
      </p>
    </div>

    {{-- Add form --}}
    <div class="bg-white rounded-lg shadow border border-gray-200 p-4 mb-4">
      <h3 class="text-sm font-semibold text-gray-700 mb-3">Add excluded page</h3>
      <div class="flex flex-wrap items-end gap-3" id="add-form">
        <div class="flex-1 min-w-[280px]">
          <label class="block text-xs text-gray-500 mb-1">Page</label>
          <select id="new-page" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-full bg-white">
            <option value="">— select page —</option>
            @foreach($pages as $p)
              <option value="{{ $p }}">{{ $p }}</option>
            @endforeach
          </select>
        </div>
        <div class="flex-1 min-w-[280px]">
          <label class="block text-xs text-gray-500 mb-1">Reason (optional)</label>
          <input type="text" id="new-reason" maxlength="255"
                 class="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-full"
                 placeholder="e.g. multi-item bundle page, ads shared across SKUs">
        </div>
        <button type="button" id="add-btn"
                class="bg-rose-600 hover:bg-rose-700 text-white text-sm font-semibold px-4 py-2 rounded-md shadow">
          <i class="fa-solid fa-plus mr-1"></i> Exclude
        </button>
      </div>
    </div>

    {{-- List --}}
    <div class="bg-white rounded-lg shadow border border-gray-200">
      <table class="min-w-full text-sm border-collapse">
        <thead>
          <tr class="bg-gray-50 border-b border-gray-200">
            <th class="px-3 py-2 text-left   text-xs font-semibold text-gray-500 uppercase w-8/12">Page</th>
            <th class="px-3 py-2 text-left   text-xs font-semibold text-gray-500 uppercase">Reason</th>
            <th class="px-3 py-2 text-center text-xs font-semibold text-gray-500 uppercase w-24">Action</th>
          </tr>
        </thead>
        <tbody id="excluded-tbody">
          @forelse($excluded as $ex)
            <tr class="border-b border-gray-100" data-id="{{ $ex->id }}">
              <td class="px-3 py-2 font-medium text-gray-800">{{ $ex->page_name }}</td>
              <td class="px-3 py-2 text-gray-600">{{ $ex->reason ?: '—' }}</td>
              <td class="px-3 py-2 text-center">
                <button type="button"
                        class="del-btn text-xs bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded">
                  Remove
                </button>
              </td>
            </tr>
          @empty
            <tr id="empty-row">
              <td colspan="3" class="px-3 py-6 text-center text-gray-400">No excluded pages yet.</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

  </div>

  <div class="save-toast" id="saveToast">✓ Saved</div>

  <script>
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let toastTimer;
    function showToast(msg = '✓ Saved') {
      const t = document.getElementById('saveToast');
      t.textContent = msg;
      t.style.display = 'block';
      clearTimeout(toastTimer);
      toastTimer = setTimeout(() => t.style.display = 'none', 2000);
    }

    document.getElementById('add-btn').addEventListener('click', function () {
      const pageEl = document.getElementById('new-page');
      const page = pageEl.value.trim();
      const reason = document.getElementById('new-reason').value.trim();
      if (!page) { alert('Please select a page'); return; }
      this.disabled = true;
      fetch('/jnt/supply/excluded-pages', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
        body: JSON.stringify({ page_name: page, reason: reason || null }),
      })
      .then(r => r.json())
      .then(d => {
        this.disabled = false;
        if (d.success && d.row) {
          document.getElementById('empty-row')?.remove();
          const tbody = document.getElementById('excluded-tbody');
          const tr = document.createElement('tr');
          tr.className = 'border-b border-gray-100';
          tr.dataset.id = d.row.id;
          tr.innerHTML = `
            <td class="px-3 py-2 font-medium text-gray-800"></td>
            <td class="px-3 py-2 text-gray-600"></td>
            <td class="px-3 py-2 text-center">
              <button type="button" class="del-btn text-xs bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded">Remove</button>
            </td>`;
          tr.children[0].textContent = d.row.page_name;
          tr.children[1].textContent = d.row.reason || '—';
          tbody.appendChild(tr);
          pageEl.value = '';
          document.getElementById('new-reason').value = '';
          showToast('✓ Excluded — reload supply to recompute Profit%');
        } else {
          alert(d.error || 'Failed');
        }
      })
      .catch(err => { console.error(err); this.disabled = false; alert('Network error'); });
    });

    document.addEventListener('click', function (e) {
      if (!e.target.classList.contains('del-btn')) return;
      const tr = e.target.closest('tr[data-id]');
      if (!tr) return;
      if (!confirm('Remove this exclusion? Profit% will include this page again after next reload.')) return;
      const id = tr.dataset.id;
      e.target.disabled = true;
      fetch(`/jnt/supply/excluded-pages/${id}`, {
        method: 'DELETE',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      })
      .then(r => r.json())
      .then(d => {
        e.target.disabled = false;
        if (d.success) { tr.remove(); showToast('✓ Removed'); }
        else alert(d.error || 'Failed');
      })
      .catch(err => { console.error(err); e.target.disabled = false; alert('Network error'); });
    });
  </script>
</x-layout>
