{{-- ═══ PHONE WHITELIST MODAL ═══ --}}
<div id="whitelist-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40" style="display:none">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg max-h-[85vh] overflow-y-auto mx-4">
    <div class="px-6 py-4 border-b flex items-center justify-between sticky top-0 bg-white z-10">
      <h3 class="font-semibold text-gray-800">📋 Phone Whitelist</h3>
      <button onclick="closeWhitelistModal()" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
    </div>
    {{-- Add form --}}
    <div class="px-6 py-4 border-b bg-gray-50">
      <div class="flex gap-2">
        <input type="text" id="wl-phone" placeholder="Phone number (e.g. 9171234567)" class="flex-1 border rounded-lg px-3 py-2 text-sm" maxlength="20"/>
        <input type="text" id="wl-reason" placeholder="Reason (optional)" class="flex-1 border rounded-lg px-3 py-2 text-sm" maxlength="255"/>
        <button onclick="addWhitelistPhone()" class="bg-emerald-600 hover:bg-emerald-700 text-white text-sm px-4 py-2 rounded-lg">Add</button>
      </div>
      <div id="wl-error" class="text-red-500 text-xs mt-2 hidden"></div>
    </div>
    {{-- List --}}
    <div class="px-6 py-4">
      <div id="wl-list" class="space-y-2">
        <div class="text-gray-400 text-sm text-center py-4">Loading...</div>
      </div>
    </div>
  </div>
</div>

<script>
  // ═══ PHONE WHITELIST ═══
  const csrfTokenWL = '{{ csrf_token() }}';
  let whitelistCanDelete = false;

  document.getElementById('phone-whitelist-btn')?.addEventListener('click', () => {
    openWhitelistModal();
  });

  function openWhitelistModal() {
    const modal = document.getElementById('whitelist-modal');
    modal.style.display = 'flex';
    modal.classList.remove('hidden');
    loadWhitelist();
  }

  function closeWhitelistModal() {
    const modal = document.getElementById('whitelist-modal');
    modal.style.display = 'none';
    modal.classList.add('hidden');
  }

  // Close on backdrop click
  document.getElementById('whitelist-modal')?.addEventListener('click', function(e) {
    if (e.target === this) closeWhitelistModal();
  });

  async function loadWhitelist() {
    const listEl = document.getElementById('wl-list');
    listEl.innerHTML = '<div class="text-gray-400 text-sm text-center py-4">Loading...</div>';

    try {
      const res = await fetch('/phone-whitelist', {
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfTokenWL }
      });
      const data = await res.json();
      whitelistCanDelete = data.can_delete;

      if (!data.items || data.items.length === 0) {
        listEl.innerHTML = '<div class="text-gray-400 text-sm text-center py-4">No whitelisted numbers yet.</div>';
        return;
      }

      listEl.innerHTML = '';
      data.items.forEach(item => {
        const div = document.createElement('div');
        div.className = 'flex items-center justify-between py-2 border-b last:border-0';
        div.innerHTML = `
          <div>
            <span class="font-mono text-sm font-medium text-gray-800">${item.phone_number}</span>
            ${item.reason ? `<span class="text-xs text-gray-500 ml-2">${item.reason}</span>` : ''}
            <div class="text-[10px] text-gray-400">
              Added by ${item.creator ? item.creator.name : 'Unknown'}
              &middot; ${new Date(item.created_at).toLocaleDateString()}
            </div>
          </div>
          ${whitelistCanDelete ? `<button onclick="deleteWhitelistPhone(${item.id})" class="text-red-400 hover:text-red-600 text-xs px-2 py-1">🗑 Delete</button>` : ''}
        `;
        listEl.appendChild(div);
      });
    } catch(e) {
      listEl.innerHTML = '<div class="text-red-500 text-sm text-center py-4">Failed to load.</div>';
    }
  }

  async function addWhitelistPhone() {
    const phoneEl = document.getElementById('wl-phone');
    const reasonEl = document.getElementById('wl-reason');
    const errorEl = document.getElementById('wl-error');

    errorEl.classList.add('hidden');
    errorEl.textContent = '';

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

      phoneEl.value = '';
      reasonEl.value = '';
      loadWhitelist();
    } catch(e) {
      errorEl.textContent = 'Network error.';
      errorEl.classList.remove('hidden');
    }
  }

  async function deleteWhitelistPhone(id) {
    if (!confirm('Remove this phone from whitelist?')) return;

    try {
      await fetch('/phone-whitelist/' + id, {
        method: 'DELETE',
        headers: {
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfTokenWL
        }
      });
      loadWhitelist();
    } catch(e) {
      alert('Failed to delete.');
    }
  }
</script>

{{-- ═══ WHITELISTED INDICATOR IN VALIDATE RESULTS ═══ --}}
<script>
  // Override the existing clearValidateMarks to also remove whitelist badges
  const _origClearValidateMarks = clearValidateMarks;
  clearValidateMarks = function() {
    _origClearValidateMarks();
    document.querySelectorAll('.phone-whitelisted-badge').forEach(el => el.remove());
  };

  // Patch: after validate results come back, add whitelisted badge
  function addWhitelistedBadges(results) {
    results.forEach(result => {
      if (!result.phone_whitelisted) return;
      const id = String(result.id);
      const phoneEl = document.querySelector(`[data-id="${id}"][data-field="PHONE NUMBER"]`);
      if (!phoneEl) return;

      // Don't add duplicate badges
      if (phoneEl.parentElement.querySelector('.phone-whitelisted-badge')) return;

      const badge = document.createElement('span');
      badge.className = 'phone-whitelisted-badge';
      badge.textContent = 'Whitelisted';
      badge.title = 'This phone number is whitelisted — duplicate check skipped';
      phoneEl.parentElement.appendChild(badge);
    });
  }

  // Monkey-patch the validate button handler to also call addWhitelistedBadges
  (function() {
    const btn = document.getElementById('validate-btn');
    if (!btn) return;

    // Remove old listener by cloning
    const newBtn = btn.cloneNode(true);
    btn.parentNode.replaceChild(newBtn, btn);

    newBtn.addEventListener('click', function () {
      const statusEl = document.getElementById('validate-status');
      statusEl.textContent = 'Validating...';
      statusEl.classList.remove('text-green-600', 'text-red-600');
      statusEl.classList.add('text-gray-600');

      clearValidateMarks();

      const rows = Array.from(document.querySelectorAll('tr[data-id]'));
      const ids = rows
        .filter(row => row.querySelector('[data-field="STATUS"]')?.value !== 'CANNOT PROCEED')
        .map(row => row.dataset.id);

      fetch("{{ route('macro_output.validate') }}", {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrfTokenWL,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ ids })
      })
      .then(res => res.json())
      .then(results => {
        let errorCount = 0;
        const invalidRowIds = new Set();

        results.forEach(result => {
          const id = String(result.id);
          if (!result.invalid_fields) return;

          let rowHasIssue = false;

          Object.keys(result.invalid_fields).forEach(field => {
            if (result.invalid_fields[field]) {
              errorCount++;
              rowHasIssue = true;
              markInvalid(id, field);
            }
          });

          if (rowHasIssue) invalidRowIds.add(id);
        });

        rows.forEach(row => {
          const id = String(row.dataset.id || '');
          if (!id) return;
          const status = row.querySelector('[data-field="STATUS"]')?.value || '';
          if (status === 'CANNOT PROCEED') return;
          const fullNameEl = row.querySelector('[data-field="FULL NAME"]');
          if (!fullNameEl) return;
          const fullNameVal = (fullNameEl.value ?? fullNameEl.textContent ?? '').toString();
          if (!isValidFullName(fullNameVal)) {
            errorCount++;
            invalidRowIds.add(id);
            markInvalid(id, 'FULL NAME');
          }
        });

        // ✅ Add whitelisted badges
        addWhitelistedBadges(results);

        if (invalidRowIds.size > 0) {
          moveRowsWithIssuesToTop(invalidRowIds);
        }

        statusEl.textContent = errorCount > 0 ? `${errorCount} cell(s) with issues` : 'All good! ✅';
        statusEl.classList.remove('text-gray-600');
        statusEl.classList.add(errorCount > 0 ? 'text-red-600' : 'text-green-600');

        if (typeof scheduleRefreshValidatedBadges === 'function') scheduleRefreshValidatedBadges();
      })
      .catch(() => {
        statusEl.textContent = 'Validation failed.';
        statusEl.classList.remove('text-gray-600');
        statusEl.classList.add('text-red-600');
        if (typeof scheduleRefreshValidatedBadges === 'function') scheduleRefreshValidatedBadges();
      });
    });
  })();
</script>
