{{-- resources/views/jnt/accounts/index.blade.php --}}
<x-layout>
  <x-slot name="title">JNT Accounts • Likha</x-slot>

  <x-slot name="heading">
    <div class="flex items-center justify-between">
      <div class="text-xl font-bold">JNT Accounts</div>
      <a href="{{ route('jnt.accounts.mapping') }}"
         class="text-sm bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-lg font-medium transition">
        Page Mapping →
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

  {{-- JNT Global Settings --}}
  <section class="bg-white rounded-xl shadow p-4 mb-4 border-l-4 border-blue-400">
    <h2 class="font-semibold text-lg mb-3">JNT Settings <span class="text-xs text-gray-400 font-normal ml-1">(per server)</span></h2>
    <form action="{{ route('jnt.settings.save') }}" method="POST" class="flex items-end gap-4 flex-wrap">
      @csrf
      <div>
        <label class="block text-sm font-medium mb-1">
          Preferred Pickup Days Offset
          <span class="text-gray-400 text-xs">(0 = today, 1 = tomorrow…)</span>
        </label>
        <div class="flex items-center gap-2">
          <input type="number" name="jnt_pickup_days_offset" value="{{ $pickupOffset }}"
                 min="0" max="30"
                 class="border border-gray-300 rounded-md px-3 py-2 text-sm w-24">
          <span class="text-sm text-gray-500">days</span>
          @if($pickupOffset > 0)
            <span class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded-full font-medium">
              Currently: +{{ $pickupOffset }}d → {{ now('Asia/Manila')->addDays($pickupOffset)->format('M d, Y') }}
            </span>
          @else
            <span class="text-xs bg-gray-100 text-gray-500 px-2 py-1 rounded-full">Currently: today</span>
          @endif
        </div>
      </div>
      <button type="submit"
              class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm font-medium">
        Save Settings
      </button>
    </form>
  </section>

  {{-- Add Account Form --}}
  <section class="bg-white rounded-xl shadow p-4 mb-4">
    <h2 class="font-semibold text-lg mb-3">Add New Account</h2>
    <form action="{{ route('jnt.accounts.store') }}" method="POST">
      @csrf
      <div class="grid md:grid-cols-3 gap-3 items-end">
        <div>
          <label class="block text-sm font-medium mb-1">Label</label>
          <input type="text" name="label" value="{{ old('label') }}"
                 placeholder="e.g. ACC1 or Main Account"
                 class="w-full border border-gray-300 p-2 rounded-md text-sm" required>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">EC Company ID</label>
          <input type="text" name="eccompanyid" value="{{ old('eccompanyid') }}"
                 placeholder="e.g. INCEPXION"
                 class="w-full border border-gray-300 p-2 rounded-md text-sm" required>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Customer ID</label>
          <input type="text" name="customerid" value="{{ old('customerid') }}"
                 placeholder="e.g. MNL-V9965"
                 class="w-full border border-gray-300 p-2 rounded-md text-sm" required>
        </div>
      </div>
      <div class="grid md:grid-cols-3 gap-3 items-end mt-3">
        <div>
          <label class="block text-sm font-medium mb-1">
            Pickup Days Offset
            <span class="text-gray-400 text-xs">(0 = today, 1 = tomorrow…)</span>
          </label>
          <input type="number" name="pickup_days_offset" value="{{ old('pickup_days_offset', 0) }}"
                 min="0" max="30"
                 class="border border-gray-300 rounded-md px-3 py-2 text-sm w-24">
        </div>
      </div>
      <div class="mt-3">
        <button type="submit"
                class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm font-medium">
          Add Account
        </button>
      </div>
    </form>
  </section>

  {{-- Accounts Table --}}
  <section class="bg-white rounded-xl shadow p-4">
    <h2 class="font-semibold text-lg mb-3">All Accounts</h2>
    <div class="overflow-x-auto">
      <table class="min-w-full border border-gray-200 bg-white text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-3 py-2 border-b text-left">Label</th>
            <th class="px-3 py-2 border-b text-left">EC Company ID</th>
            <th class="px-3 py-2 border-b text-left">Customer ID</th>
            <th class="px-3 py-2 border-b text-center">Uppercase</th>
            <th class="px-3 py-2 border-b text-center">Item Mapping</th>
            <th class="px-3 py-2 border-b text-center">Pickup Offset</th>
            <th class="px-3 py-2 border-b text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($accounts as $acc)
            <tr class="hover:bg-gray-50" x-data="{ editing: false }">

              {{-- View mode --}}
              <template x-if="!editing">
                <td class="px-3 py-2 border-b font-semibold">{{ $acc->label }}</td>
              </template>
              <template x-if="!editing">
                <td class="px-3 py-2 border-b font-mono text-gray-700">{{ $acc->eccompanyid }}</td>
              </template>
              <template x-if="!editing">
                <td class="px-3 py-2 border-b font-mono text-gray-700">{{ $acc->customerid }}</td>
              </template>
              <template x-if="!editing">
                <td class="px-3 py-2 border-b text-center">
                  @if($acc->force_uppercase)
                    <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded-full text-xs font-medium">ON</span>
                  @else
                    <span class="px-2 py-0.5 bg-gray-100 text-gray-500 rounded-full text-xs">OFF</span>
                  @endif
                </td>
              </template>
              <template x-if="!editing">
                <td class="px-3 py-2 border-b text-center">
                  @if($acc->use_item_sender_mapping)
                    <span class="px-2 py-0.5 bg-blue-100 text-blue-700 rounded-full text-xs font-medium">ON</span>
                  @else
                    <span class="px-2 py-0.5 bg-gray-100 text-gray-500 rounded-full text-xs">OFF</span>
                  @endif
                </td>
              </template>
              <template x-if="!editing">
                <td class="px-3 py-2 border-b text-center">
                  @php $offset = (int)($acc->pickup_days_offset ?? 0); @endphp
                  @if($offset === 0)
                    <span class="px-2 py-0.5 bg-gray-100 text-gray-500 rounded-full text-xs">+0d (today)</span>
                  @elseif($offset === 1)
                    <span class="px-2 py-0.5 bg-yellow-100 text-yellow-700 rounded-full text-xs font-medium">+1d (tomorrow)</span>
                  @else
                    <span class="px-2 py-0.5 bg-orange-100 text-orange-700 rounded-full text-xs font-medium">+{{ $offset }}d</span>
                  @endif
                </td>
              </template>
              <template x-if="!editing">
                <td class="px-3 py-2 border-b text-center">
                  <button @click="editing = true" class="text-blue-600 hover:underline text-xs mr-2">Edit</button>
                  <form action="{{ route('jnt.accounts.destroy', $acc) }}" method="POST" class="inline"
                        onsubmit="return confirm('Delete this account? Mapped pages will lose their assignment.')">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-red-600 hover:underline text-xs">Delete</button>
                  </form>
                </td>
              </template>

              {{-- Edit mode --}}
              <template x-if="editing">
                <td colspan="6" class="px-3 py-2 border-b">
                  <form action="{{ route('jnt.accounts.update', $acc) }}" method="POST"
                        class="flex items-center gap-2 flex-wrap">
                    @csrf @method('PUT')
                    <input type="text" name="label" value="{{ $acc->label }}"
                           placeholder="Label"
                           class="border rounded px-2 py-1 text-sm w-36" required>
                    <input type="text" name="eccompanyid" value="{{ $acc->eccompanyid }}"
                           placeholder="EC Company ID"
                           class="border rounded px-2 py-1 text-sm w-36" required>
                    <input type="text" name="customerid" value="{{ $acc->customerid }}"
                           placeholder="Customer ID"
                           class="border rounded px-2 py-1 text-sm w-36" required>
                    <label class="flex items-center gap-1.5 text-sm cursor-pointer select-none">
                      <input type="checkbox" name="force_uppercase" value="1"
                             {{ $acc->force_uppercase ? 'checked' : '' }}
                             class="rounded">
                      Force Uppercase
                    </label>
                    <label class="flex items-center gap-1.5 text-sm cursor-pointer select-none">
                      <input type="checkbox" name="use_item_sender_mapping" value="1"
                             {{ $acc->use_item_sender_mapping ? 'checked' : '' }}
                             class="rounded">
                      Item Mapping
                    </label>
                    <label class="flex items-center gap-1.5 text-sm cursor-pointer select-none">
                      <span class="font-medium">Pickup +</span>
                      <input type="number" name="pickup_days_offset" value="{{ (int)($acc->pickup_days_offset ?? 0) }}"
                             min="0" max="30"
                             class="border rounded px-2 py-1 text-sm w-16">
                      <span class="text-gray-500">days</span>
                    </label>
                    <button type="submit"
                            class="px-3 py-1 bg-green-600 text-white rounded text-xs hover:bg-green-700">
                      Save
                    </button>
                    <button type="button" @click="editing = false"
                            class="px-3 py-1 bg-gray-300 rounded text-xs hover:bg-gray-400">
                      Cancel
                    </button>
                  </form>
                </td>
              </template>

            </tr>
          @empty
            <tr>
              <td colspan="6" class="px-3 py-4 text-center text-gray-500">
                No accounts yet. Add one above.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    {{-- Info --}}
    <div class="mt-4 bg-blue-50 border border-blue-200 rounded-lg p-3 text-sm text-blue-800">
      <strong>Note:</strong> The <em>secret key</em> is shared across all accounts and is set in the <code>.env</code> file (<code>JNT_SECRET</code>).
      If no account is mapped to a page, the system falls back to the default credentials in <code>.env</code>.
    </div>
  </section>

</x-layout>
