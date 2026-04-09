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

  {{-- Mapping Form --}}
  <section class="bg-white rounded-xl shadow p-4">
    <h2 class="font-semibold text-lg mb-1">Assign JNT Account per Page</h2>
    <p class="text-sm text-gray-500 mb-4">
      Pages are sourced from <code>macro_output</code>. If a page has no account assigned,
      it falls back to the default credentials in <code>.env</code>.
    </p>

    <form action="{{ route('jnt.accounts.mapping.save') }}" method="POST">
      @csrf

      <div class="overflow-x-auto">
        <table class="min-w-full border border-gray-200 bg-white text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-3 py-2 border-b text-left">Page</th>
              <th class="px-3 py-2 border-b text-left">JNT Account</th>
              <th class="px-3 py-2 border-b text-left text-gray-400">Currently Assigned</th>
            </tr>
          </thead>
          <tbody>
            @forelse($pages as $page)
              @php $mapped = $mappings->get($page); @endphp
              <tr class="hover:bg-gray-50">
                <td class="px-3 py-2 border-b font-medium">{{ $page }}</td>
                <td class="px-3 py-2 border-b">
                  <select name="mappings[{{ $page }}]"
                          class="border border-gray-300 rounded px-2 py-1 text-sm w-full max-w-xs">
                    <option value="">-- use .env default --</option>
                    @foreach($accounts as $acc)
                      <option value="{{ $acc->id }}"
                        {{ ($mapped && $mapped->jnt_account_id == $acc->id) ? 'selected' : '' }}>
                        {{ $acc->label }} — {{ $acc->eccompanyid }} / {{ $acc->customerid }}
                      </option>
                    @endforeach
                  </select>
                </td>
                <td class="px-3 py-2 border-b text-gray-400 text-xs">
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
                <td colspan="3" class="px-3 py-4 text-center text-gray-500">
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

  @endif

  {{-- Info --}}
  <div class="mt-4 bg-blue-50 border border-blue-200 rounded-xl p-4 text-sm text-blue-800">
    <h3 class="font-semibold mb-1">How it works</h3>
    <p>When a batch is created for a page, the system checks this mapping to determine which JNT account (EC Company ID + Customer ID) to use. If the page has no mapping, it uses the default credentials from <code>.env</code>.</p>
  </div>

</x-layout>
