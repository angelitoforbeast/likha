{{-- resources/views/jnt/fee-settings.blade.php --}}
<x-layout>
  <x-slot name="title">Fee Settings • Likha</x-slot>

  <x-slot name="heading">
    <div class="flex items-center gap-3">
      <div class="text-xl font-bold">⚙️ Fee Settings</div>
      <a href="{{ route('jnt.remittance') }}" class="text-sm text-blue-600 hover:underline">← Back to Remittance</a>
    </div>
  </x-slot>

  <div x-data="feeSettingsApp()" x-init="init()">

    {{-- Success message --}}
    @if(session('success'))
      <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4">
        {{ session('success') }}
      </div>
    @endif

    {{-- Error messages --}}
    @if($errors->any())
      <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
        <ul class="list-disc list-inside">
          @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    {{-- Add New Setting --}}
    <section class="bg-white rounded-xl shadow p-4 mb-4">
      <h2 class="font-semibold text-lg mb-3">Add New Fee Setting</h2>
      <form action="{{ route('fee-settings.store') }}" method="POST">
        @csrf
        <div class="grid md:grid-cols-4 gap-3 items-end">
          <div>
            <label class="block text-sm font-medium mb-1">Setting Type</label>
            <select name="setting_key" x-model="selectedKey" class="w-full border border-gray-300 p-2 rounded-md" required>
              <option value="cod_fee_rate">COD Fee Rate</option>
              <option value="cod_fee_vat_rate">COD Fee VAT Rate</option>
              <option value="shipping_fee_per_order">Shipping Fee per Order</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1" x-text="isShippingFee ? 'Amount (PHP)' : 'Rate Value'"></label>
            <div class="flex items-center gap-1">
              <input type="number" name="setting_value"
                     :step="isShippingFee ? '0.01' : '0.000001'"
                     min="0"
                     :max="isShippingFee ? '99999' : '1'"
                     class="w-full border border-gray-300 p-2 rounded-md" required
                     :placeholder="isShippingFee ? 'e.g. 100.00' : 'e.g. 0.015 for 1.5%'"
                     value="{{ old('setting_value') }}"
                     x-ref="settingValue"
                     @input="updateHint()">
              <span class="text-xs text-gray-500 whitespace-nowrap" x-text="rateHint"></span>
            </div>
            <div class="text-xs text-gray-400 mt-1" x-text="isShippingFee ? 'Enter flat amount in PHP per order' : 'Enter as decimal (0.015 = 1.5%, 0.12 = 12%)'"></div>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Effective Date</label>
            <input type="date" name="effective_date" class="w-full border border-gray-300 p-2 rounded-md" required
                   value="{{ old('effective_date', date('Y-m-d')) }}">
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Description (optional)</label>
            <input type="text" name="description" class="w-full border border-gray-300 p-2 rounded-md"
                   :placeholder="isShippingFee ? 'e.g. New SF rate from March' : 'e.g. New COD rate from March'"
                   value="{{ old('description') }}">
          </div>
        </div>
        <div class="mt-3">
          <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm font-medium">
            Add Setting
          </button>
        </div>
      </form>
    </section>

    {{-- Shipping Fee per Order Settings --}}
    <section class="bg-white rounded-xl shadow p-4 mb-4">
      <h2 class="font-semibold text-lg mb-3">🚚 Shipping Fee per Order History
        <span class="text-sm font-normal text-gray-500">(scope: {{ $scope }})</span>
      </h2>
      <div class="overflow-x-auto">
        <table class="min-w-full border border-gray-200 bg-white text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-3 py-2 border-b text-left">Effective Date</th>
              <th class="px-3 py-2 border-b text-right">Amount (PHP)</th>
              <th class="px-3 py-2 border-b text-left">Description</th>
              <th class="px-3 py-2 border-b text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            @php $shippingSettings = $settings->where('setting_key', 'shipping_fee_per_order'); @endphp
            @forelse($shippingSettings as $s)
              <tr class="hover:bg-gray-50" x-data="{ editing: false }">
                {{-- View mode --}}
                <template x-if="!editing">
                  <td class="px-3 py-2 border-b">{{ $s->effective_date->format('M d, Y') }}</td>
                </template>
                <template x-if="!editing">
                  <td class="px-3 py-2 border-b text-right font-mono font-semibold">₱{{ number_format((float)$s->setting_value, 2) }}</td>
                </template>
                <template x-if="!editing">
                  <td class="px-3 py-2 border-b text-gray-600">{{ $s->description ?? '—' }}</td>
                </template>
                <template x-if="!editing">
                  <td class="px-3 py-2 border-b text-center">
                    <button @click="editing = true" class="text-blue-600 hover:underline text-xs mr-2">Edit</button>
                    <form action="{{ route('fee-settings.destroy', $s) }}" method="POST" class="inline"
                          onsubmit="return confirm('Are you sure you want to delete this setting?')">
                      @csrf @method('DELETE')
                      <button type="submit" class="text-red-600 hover:underline text-xs">Delete</button>
                    </form>
                  </td>
                </template>

                {{-- Edit mode --}}
                <template x-if="editing">
                  <td colspan="4" class="px-3 py-2 border-b">
                    <form action="{{ route('fee-settings.update', $s) }}" method="POST" class="flex items-center gap-2 flex-wrap">
                      @csrf @method('PUT')
                      <input type="date" name="effective_date" value="{{ $s->effective_date->format('Y-m-d') }}"
                             class="border rounded px-2 py-1 text-sm" required>
                      <div class="flex items-center gap-1">
                        <span class="text-sm">₱</span>
                        <input type="number" name="setting_value" value="{{ $s->setting_value }}" step="0.01" min="0" max="99999"
                               class="border rounded px-2 py-1 text-sm w-32" required>
                      </div>
                      <input type="text" name="description" value="{{ $s->description }}"
                             class="border rounded px-2 py-1 text-sm flex-1" placeholder="Description">
                      <button type="submit" class="px-3 py-1 bg-green-600 text-white rounded text-xs hover:bg-green-700">Save</button>
                      <button type="button" @click="editing = false" class="px-3 py-1 bg-gray-300 rounded text-xs hover:bg-gray-400">Cancel</button>
                    </form>
                  </td>
                </template>
              </tr>
            @empty
              <tr><td colspan="4" class="px-3 py-4 text-center text-gray-500">No Shipping Fee settings yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>

    {{-- COD Fee Rate Settings --}}
    <section class="bg-white rounded-xl shadow p-4 mb-4">
      <h2 class="font-semibold text-lg mb-3">COD Fee Rate History
        <span class="text-sm font-normal text-gray-500">(scope: {{ $scope }})</span>
      </h2>
      <div class="overflow-x-auto">
        <table class="min-w-full border border-gray-200 bg-white text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-3 py-2 border-b text-left">Effective Date</th>
              <th class="px-3 py-2 border-b text-right">Rate</th>
              <th class="px-3 py-2 border-b text-right">Percentage</th>
              <th class="px-3 py-2 border-b text-left">Description</th>
              <th class="px-3 py-2 border-b text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            @php $codFeeSettings = $settings->where('setting_key', 'cod_fee_rate'); @endphp
            @forelse($codFeeSettings as $s)
              <tr class="hover:bg-gray-50" x-data="{ editing: false }">
                {{-- View mode --}}
                <template x-if="!editing">
                  <td class="px-3 py-2 border-b">{{ $s->effective_date->format('M d, Y') }}</td>
                </template>
                <template x-if="!editing">
                  <td class="px-3 py-2 border-b text-right font-mono">{{ $s->setting_value }}</td>
                </template>
                <template x-if="!editing">
                  <td class="px-3 py-2 border-b text-right font-semibold">{{ number_format($s->setting_value * 100, 2) }}%</td>
                </template>
                <template x-if="!editing">
                  <td class="px-3 py-2 border-b text-gray-600">{{ $s->description ?? '—' }}</td>
                </template>
                <template x-if="!editing">
                  <td class="px-3 py-2 border-b text-center">
                    <button @click="editing = true" class="text-blue-600 hover:underline text-xs mr-2">Edit</button>
                    <form action="{{ route('fee-settings.destroy', $s) }}" method="POST" class="inline"
                          onsubmit="return confirm('Are you sure you want to delete this setting?')">
                      @csrf @method('DELETE')
                      <button type="submit" class="text-red-600 hover:underline text-xs">Delete</button>
                    </form>
                  </td>
                </template>

                {{-- Edit mode --}}
                <template x-if="editing">
                  <td colspan="5" class="px-3 py-2 border-b">
                    <form action="{{ route('fee-settings.update', $s) }}" method="POST" class="flex items-center gap-2 flex-wrap">
                      @csrf @method('PUT')
                      <input type="date" name="effective_date" value="{{ $s->effective_date->format('Y-m-d') }}"
                             class="border rounded px-2 py-1 text-sm" required>
                      <input type="number" name="setting_value" value="{{ $s->setting_value }}" step="0.000001" min="0" max="1"
                             class="border rounded px-2 py-1 text-sm w-32" required>
                      <input type="text" name="description" value="{{ $s->description }}"
                             class="border rounded px-2 py-1 text-sm flex-1" placeholder="Description">
                      <button type="submit" class="px-3 py-1 bg-green-600 text-white rounded text-xs hover:bg-green-700">Save</button>
                      <button type="button" @click="editing = false" class="px-3 py-1 bg-gray-300 rounded text-xs hover:bg-gray-400">Cancel</button>
                    </form>
                  </td>
                </template>
              </tr>
            @empty
              <tr><td colspan="5" class="px-3 py-4 text-center text-gray-500">No COD Fee Rate settings yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>

    {{-- COD Fee VAT Rate Settings --}}
    <section class="bg-white rounded-xl shadow p-4 mb-4">
      <h2 class="font-semibold text-lg mb-3">COD Fee VAT Rate History
        <span class="text-sm font-normal text-gray-500">(scope: {{ $scope }})</span>
      </h2>
      <div class="overflow-x-auto">
        <table class="min-w-full border border-gray-200 bg-white text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-3 py-2 border-b text-left">Effective Date</th>
              <th class="px-3 py-2 border-b text-right">Rate</th>
              <th class="px-3 py-2 border-b text-right">Percentage</th>
              <th class="px-3 py-2 border-b text-left">Description</th>
              <th class="px-3 py-2 border-b text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            @php $vatSettings = $settings->where('setting_key', 'cod_fee_vat_rate'); @endphp
            @forelse($vatSettings as $s)
              <tr class="hover:bg-gray-50" x-data="{ editing: false }">
                {{-- View mode --}}
                <template x-if="!editing">
                  <td class="px-3 py-2 border-b">{{ $s->effective_date->format('M d, Y') }}</td>
                </template>
                <template x-if="!editing">
                  <td class="px-3 py-2 border-b text-right font-mono">{{ $s->setting_value }}</td>
                </template>
                <template x-if="!editing">
                  <td class="px-3 py-2 border-b text-right font-semibold">{{ number_format($s->setting_value * 100, 2) }}%</td>
                </template>
                <template x-if="!editing">
                  <td class="px-3 py-2 border-b text-gray-600">{{ $s->description ?? '—' }}</td>
                </template>
                <template x-if="!editing">
                  <td class="px-3 py-2 border-b text-center">
                    <button @click="editing = true" class="text-blue-600 hover:underline text-xs mr-2">Edit</button>
                    <form action="{{ route('fee-settings.destroy', $s) }}" method="POST" class="inline"
                          onsubmit="return confirm('Are you sure you want to delete this setting?')">
                      @csrf @method('DELETE')
                      <button type="submit" class="text-red-600 hover:underline text-xs">Delete</button>
                    </form>
                  </td>
                </template>

                {{-- Edit mode --}}
                <template x-if="editing">
                  <td colspan="5" class="px-3 py-2 border-b">
                    <form action="{{ route('fee-settings.update', $s) }}" method="POST" class="flex items-center gap-2 flex-wrap">
                      @csrf @method('PUT')
                      <input type="date" name="effective_date" value="{{ $s->effective_date->format('Y-m-d') }}"
                             class="border rounded px-2 py-1 text-sm" required>
                      <input type="number" name="setting_value" value="{{ $s->setting_value }}" step="0.000001" min="0" max="1"
                             class="border rounded px-2 py-1 text-sm w-32" required>
                      <input type="text" name="description" value="{{ $s->description }}"
                             class="border rounded px-2 py-1 text-sm flex-1" placeholder="Description">
                      <button type="submit" class="px-3 py-1 bg-green-600 text-white rounded text-xs hover:bg-green-700">Save</button>
                      <button type="button" @click="editing = false" class="px-3 py-1 bg-gray-300 rounded text-xs hover:bg-gray-400">Cancel</button>
                    </form>
                  </td>
                </template>
              </tr>
            @empty
              <tr><td colspan="5" class="px-3 py-4 text-center text-gray-500">No COD Fee VAT Rate settings yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>

    {{-- Info box --}}
    <section class="bg-blue-50 border border-blue-200 rounded-xl p-4 text-sm text-blue-800">
      <h3 class="font-semibold mb-2">How it works</h3>
      <p class="mb-2">Each setting has an <strong>effective date</strong>. When computing fees, the system uses the rate with the <strong>latest effective date that is on or before the transaction date</strong>.</p>
      <p class="mb-2">For example, if you have:</p>
      <ul class="list-disc list-inside mb-2">
        <li>COD Fee Rate = 1.5% effective Jan 1, 2025</li>
        <li>COD Fee Rate = 2.0% effective Apr 1, 2026</li>
      </ul>
      <p class="mb-2">Then transactions before Apr 1, 2026 use 1.5%, and transactions from Apr 1, 2026 onward use 2.0%.</p>
      <p><strong>Shipping Fee per Order</strong> works the same way — the J&T Checker will compare the actual shipping fee from the uploaded Excel against the expected flat rate set here to flag any discrepancies.</p>
    </section>
  </div>

  <script>
    function feeSettingsApp() {
      return {
        selectedKey: '{{ old("setting_key", "cod_fee_rate") }}',
        rateHint: '',
        get isShippingFee() {
          return this.selectedKey === 'shipping_fee_per_order';
        },
        init() {
          this.updateHint();
        },
        updateHint() {
          const input = this.$refs.settingValue;
          if (!input) return;
          const v = parseFloat(input.value);
          if (this.isShippingFee) {
            this.rateHint = (!isNaN(v) && v >= 0) ? `= ₱${v.toFixed(2)}` : '';
          } else {
            this.rateHint = (!isNaN(v) && v >= 0) ? `= ${(v * 100).toFixed(2)}%` : '';
          }
        }
      }
    }
  </script>
</x-layout>
