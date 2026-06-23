<x-layout>
  <x-slot name="title">AI Checker Access</x-slot>
  <x-slot name="heading">AI Checker — Access (sino ang pwedeng gumamit)</x-slot>

  <div class="max-w-4xl mx-auto p-4 space-y-4">

    <a href="{{ route('macro_checker.logs') }}" class="inline-flex items-center text-sm text-blue-600 hover:underline">
      ← Balik sa AI Checker Logs
    </a>

    @if (session('success'))
      <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-green-800">{{ session('success') }}</div>
    @endif

    <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-900">
      Ang <strong>CEO · Marketing · Marketing - OIC</strong> ay <strong>laging pwede</strong> (via role — hindi na kailangang i-check).
      Dito mo lang ida-dagdag ang <strong>EXTRA users</strong> (hal. Data Encoder) na gusto mong payagan.
    </div>

    <form method="POST" action="{{ route('macro_checker.access.update') }}">
      @csrf

      <div class="mb-3 flex items-center gap-3">
        <input type="text" id="userSearch" placeholder="Hanapin (name / email / role)…"
               class="w-72 border border-gray-300 rounded-lg px-3 py-2 text-sm" autocomplete="off">
        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
          I-save ang access
        </button>
      </div>

      <div class="overflow-x-auto rounded-xl border bg-white shadow-sm">
        <table class="min-w-full text-sm" id="userTable">
          <thead class="bg-gray-50 text-gray-700">
            <tr>
              <th class="px-3 py-2 text-center font-semibold w-20">Pwede?</th>
              <th class="px-3 py-2 text-left font-semibold">Name</th>
              <th class="px-3 py-2 text-left font-semibold">Email</th>
              <th class="px-3 py-2 text-left font-semibold">Role</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            @forelse ($users as $u)
              <tr class="hover:bg-gray-50/60 user-row">
                <td class="px-3 py-2 text-center">
                  @if ($u->role_allowed)
                    <span title="Laging pwede via role" class="inline-block rounded px-2 py-0.5 text-xs font-semibold bg-green-100 text-green-700">✓ via role</span>
                  @else
                    <input type="checkbox" name="user_ids[]" value="{{ $u->id }}"
                           {{ $u->in_allowlist ? 'checked' : '' }}
                           class="w-4 h-4 cursor-pointer" style="accent-color:#2563eb">
                  @endif
                </td>
                <td class="px-3 py-2 text-gray-900 u-name">{{ $u->name }}</td>
                <td class="px-3 py-2 text-gray-600 u-email">{{ $u->email }}</td>
                <td class="px-3 py-2 text-gray-600 u-role">{{ $u->role ?? '—' }}</td>
              </tr>
            @empty
              <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">Walang users.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>

      <div class="mt-3">
        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
          I-save ang access
        </button>
      </div>
    </form>
  </div>

  <script>
    // Simpleng client-side search filter.
    (function () {
      const box = document.getElementById('userSearch');
      if (!box) return;
      box.addEventListener('input', function () {
        const q = this.value.toLowerCase().trim();
        document.querySelectorAll('#userTable tbody .user-row').forEach(function (tr) {
          const hay = [
            tr.querySelector('.u-name')?.textContent || '',
            tr.querySelector('.u-email')?.textContent || '',
            tr.querySelector('.u-role')?.textContent || '',
          ].join(' ').toLowerCase();
          tr.style.display = (q === '' || hay.includes(q)) ? '' : 'none';
        });
      });
    })();
  </script>
</x-layout>
