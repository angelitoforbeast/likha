{{-- resources/views/jnt/accounts/mapping.blade.php --}}
<x-layout>
  <x-slot name="title">Page Mapping • JNT Accounts</x-slot>

  <x-slot name="heading">
    <div class="flex items-center justify-between">
      <div class="text-xl font-bold">Page → JNT Account Mapping</div>
      <a href="{{ route('jnt.accounts.index') }}"
         class="text-sm bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-lg font-medium transition">
        ← JNT Accounts
      </a>
    </div>
  </x-slot>

  {{-- Alerts --}}
  @if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4">
      {{ session('success') }}
    </div>
  @endif
  @if($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
      <ul class="list-disc list-inside">
        @foreach($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  @if($accounts->isEmpty())
    <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-4 rounded-lg mb-4">
      No JNT Accounts found.
      <a href="{{ route('jnt.accounts.index') }}" class="underline font-medium">Add accounts first →</a>
    </div>
  @else

  <div x-data="mappingApp()">

    {{-- Bulk Assign Bar --}}
    <section class="bg-white rounded-xl shadow p-4 mb-4">
      <h2 class="font-semibold text-base mb-2">Bulk Assign</h2>
      <div class="flex items-center gap-3 flex-wrap">
        <span class="text-sm text-gray-600" x-text="selectedCount + ' page(s) selected'"></span>
        <select x-model="bulkAccountId"
                class="border border-gray-300 rounded px-2 py-1.5 text-sm">
          <option value="">-- select account --</option>
          @foreach($accounts as $acc)
            <option value="{{ $acc->id }}">
              {{ $acc->label }} — {{ $acc->eccompanyid }} / {{ $acc->customerid }}
            </option>
          @endforeach
          <option value="0">-- use .env default --</option>
        </select>
        <button type="button" @click="applyBulk()"
                :disabled="selectedCount === 0 || bulkAccountId === ''"
                class="px-4 py-1.5 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed">
          Apply to Selected
        </button>
        <button type="button" @click="clearSelection()"
                :disabled="selectedCount === 0"
                class="px-3 py-1.5 bg-gray-200 text-gray-700 rounded-md text-sm hover:bg-gray-300 disabled:opacity-40 disabled:cursor-not-allowed">
          Clear Selection
        </button>
      </div>
    </section>

    {{-- Mapping Form --}}
    <section class="bg-white rounded-xl shadow p-4">
      <h2 class="font-semibold text-lg mb-1">Assign JNT Account per Page</h2>
      <p class="text-sm text-gray-500 mb-4">
        Pages are sourced from <code>macro_output</code>. If a page has no account assigned,
        it falls back to the default credentials in <code>.env</code>.
      </p>

      <form action="{{ route('jnt.accounts.mapping.save') }}" method="POST" id="mapping-form">
        @csrf

        <div class="overflow-x-auto">
          <table class="min-w-full border border-gray-200 bg-white text-sm">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-3 py-2 border-b text-center w-8">
                  {{-- Select All checkbox --}}
                  <input type="checkbox" @change="toggleAll($event)"
                         :checked="selectedCount > 0 && selectedCount === totalCount"
                         :indeterminate="selectedCount > 0 && selectedCount < totalCount"
                         class="rounded cursor-pointer">
                </th>
                <th class="px-3 py-2 border-b text-left">Page</th>
                <th class="px-3 py-2 border-b text-left">JNT Account</th>
                <th class="px-3 py-2 border-b text-left text-gray-400">Currently Assigned</th>
              </tr>
            </thead>
            <tbody>
              @forelse($pages as $page)
                @php $mapped = $mappings->get($page); @endphp
                <tr class="hover:bg-gray-50" :class="selected['{{ addslashes($page) }}'] ? 'bg-indigo-50' : ''">
                  {{-- Row checkbox --}}
                  <td class="px-3 py-2 border-b text-center">
                    <input type="checkbox"
                           x-model="selected['{{ addslashes($page) }}']"
                           @change="onRowCheck()"
                           class="rounded cursor-pointer">
                  </td>
                  <td class="px-3 py-2 border-b font-medium">{{ $page }}</td>
                  <td class="px-3 py-2 border-b">
                    <select name="mappings[{{ $page }}]"
                            x-ref="select_{{ \Illuminate\Support\Str::slug($page, '_') }}"
                            data-page="{{ $page }}"
                            class="page-select border border-gray-300 rounded px-2 py-1 text-sm w-full max-w-xs">
                      <option value="">-- use .env default --</option>
                      @foreach($accounts as $acc)
                        <option value="{{ $acc->id }}"
                          {{ ($mapped && $mapped->jnt_account_id == $acc->id) ? 'selected' : '' }}>
                          {{ $acc->label }} — {{ $acc->eccompanyid }} / {{ $acc->customerid }}
                        </option>
                      @endforeach
                    </select>
                  </td>
                  <td class="px-3 py-2 border-b text-xs">
                    @if($mapped && $mapped->account)
                      <span class="text-green-700 font-medium">{{ $mapped->account->label }}</span>
                      <span class="text-gray-400">({{ $mapped->account->customerid }})</span>
                    @else
                      <span class="text-gray-400 italic">.env fallback</span>
                    @endif
                  </td>
                </tr>
              @empty
                <tr>
                  <td colspan="4" class="px-3 py-4 text-center text-gray-500">
                    No pages found in macro_output.
                  </td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>

        @if($pages->isNotEmpty())
          <div class="mt-4">
            <button type="submit"
                    class="px-5 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm font-medium">
              Save Mappings
            </button>
          </div>
        @endif
      </form>
    </section>

  </div>

  @endif

  {{-- Info --}}
  <div class="mt-4 bg-blue-50 border border-blue-200 rounded-xl p-4 text-sm text-blue-800">
    <h3 class="font-semibold mb-1">How it works</h3>
    <p>When a batch is created for a page, the system checks this mapping to determine which JNT account (EC Company ID + Customer ID) to use. If the page has no mapping, it uses the default credentials from <code>.env</code>.</p>
  </div>

  <script>
    function mappingApp() {
      return {
        selected: {},
        bulkAccountId: '',
        totalCount: document.querySelectorAll('.page-select').length,

        get selectedCount() {
          return Object.values(this.selected).filter(v => v).length;
        },

        toggleAll(event) {
          const checked = event.target.checked;
          document.querySelectorAll('.page-select').forEach(sel => {
            const page = sel.dataset.page;
            this.selected[page] = checked;
          });
        },

        onRowCheck() {
          // just triggers selectedCount reactivity
        },

        clearSelection() {
          this.selected = {};
        },

        applyBulk() {
          if (this.bulkAccountId === '' || this.selectedCount === 0) return;

          const accountId = this.bulkAccountId === '0' ? '' : this.bulkAccountId;

          document.querySelectorAll('.page-select').forEach(sel => {
            const page = sel.dataset.page;
            if (this.selected[page]) {
              sel.value = accountId;
            }
          });
        },
      }
    }
  </script>

</x-layout>
