{{-- resources/views/jnt/waybills/sender-address.blade.php --}}
<x-layout>
  <x-slot name="title">Sender Address</x-slot>
  <x-slot name="heading">J&T Waybills — Sender Address</x-slot>

  <div class="max-w-full overflow-x-hidden">

    {{-- Alerts --}}
    @if (session('ok'))
      <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">
        {{ session('ok') }}
      </div>
    @endif

    @if ($errors->any())
      <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
        <div class="font-semibold">May validation errors:</div>
        <ul class="list-disc pl-5">
          @foreach ($errors->all() as $err)
            <li class="break-words">{{ $err }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    {{-- Address Library Status --}}
    <div class="mb-4 rounded-xl border bg-white p-4">
      <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div class="text-sm font-semibold text-gray-800">Address Library (jnt_address.txt)</div>
          <div class="text-xs text-gray-500 break-words">
            Used for PROV → CITY → BRGY (BRGY = J&T AREA)
          </div>
        </div>
        <div class="text-xs">
          @if (!empty($txtFound) && $txtFound)
            <span class="rounded-md bg-green-100 px-2 py-1 font-semibold text-green-800">FOUND</span>
          @else
            <span class="rounded-md bg-red-100 px-2 py-1 font-semibold text-red-800">NOT FOUND</span>
          @endif
        </div>
      </div>

      <!-- <div class="mt-2 text-xs text-gray-500 break-words">
        Path: <span class="font-mono">{{ $txtPath ?? '' }}</span>
      </div> -->
    </div>

    {{-- Create Form --}}
    <div class="mb-6 rounded-xl border bg-white p-4">
      <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div class="text-sm font-semibold text-gray-800">Create Sender Address</div>
          <div class="text-xs text-gray-500">No horizontal scroll. All text wraps.</div>
        </div>
        <div class="text-xs text-gray-500">
          Saved: <span class="font-semibold">{{ $rows->count() }}</span>
        </div>
      </div>

      <form method="POST" action="{{ route('jnt.sender_address.store') }}" class="space-y-3">
        @csrf

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
          <div class="min-w-0">
            <label class="mb-1 block text-xs font-semibold text-gray-600">PHONE (optional)</label>
            <input
              name="jnt_sender_phone"
              value="{{ old('jnt_sender_phone') }}"
              class="w-full min-w-0 rounded-lg border px-3 py-2 text-sm"
              placeholder="0917xxxxxxx"
            />
            @error('jnt_sender_phone')
              <div class="mt-1 text-xs text-red-600 break-words">{{ $message }}</div>
            @enderror
          </div>

          <div class="min-w-0">
            <label class="mb-1 block text-xs font-semibold text-gray-600">PROVINCE</label>
            <select
              id="create_prov"
              name="jnt_sender_prov"
              class="w-full min-w-0 rounded-lg border px-3 py-2 text-sm"
            ></select>
            @error('jnt_sender_prov')
              <div class="mt-1 text-xs text-red-600 break-words">{{ $message }}</div>
            @enderror
          </div>

          <div class="min-w-0">
            <label class="mb-1 block text-xs font-semibold text-gray-600">CITY / MUNICIPALITY</label>
            <select
              id="create_city"
              name="jnt_sender_city"
              class="w-full min-w-0 rounded-lg border px-3 py-2 text-sm"
            ></select>
            @error('jnt_sender_city')
              <div class="mt-1 text-xs text-red-600 break-words">{{ $message }}</div>
            @enderror
          </div>

          <div class="min-w-0">
            <label class="mb-1 block text-xs font-semibold text-gray-600">AREA (BRGY)</label>
            <select
              id="create_area"
              name="jnt_sender_area"
              class="w-full min-w-0 rounded-lg border px-3 py-2 text-sm"
            ></select>
            @error('jnt_sender_area')
              <div class="mt-1 text-xs text-red-600 break-words">{{ $message }}</div>
            @enderror
          </div>
        </div>

        <div class="min-w-0">
          <label class="mb-1 block text-xs font-semibold text-gray-600">FULL ADDRESS</label>
          <textarea
            name="jnt_sender_address"
            rows="3"
            class="w-full min-w-0 rounded-lg border px-3 py-2 text-sm"
            placeholder="Warehouse/Pickup exact address..."
          >{{ old('jnt_sender_address') }}</textarea>
          @error('jnt_sender_address')
            <div class="mt-1 text-xs text-red-600 break-words">{{ $message }}</div>
          @enderror
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <button class="rounded-lg bg-black px-4 py-2 text-sm font-semibold text-white">
            Save
          </button>
          
        </div>
      </form>
    </div>

    {{-- Saved Rows (Cards) --}}
    <div class="space-y-3">
      @foreach ($rows as $r)
        <div class="rounded-xl border bg-white p-4">
          <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <div class="text-sm font-semibold text-gray-800">
              Row #{{ $r->id }}
            </div>

            <div class="text-xs text-gray-500">
              Created: {{ optional($r->created_at)->format('Y-m-d H:i') }}
            </div>
          </div>

          {{-- row-specific error --}}
          @if ($errors->has('row_'.$r->id))
            <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800 break-words">
              {{ $errors->first('row_'.$r->id) }}
            </div>
          @endif

          <form method="POST" action="{{ route('jnt.sender_address.update', $r) }}" class="space-y-3">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
              <div class="min-w-0">
                <label class="mb-1 block text-xs font-semibold text-gray-600">PHONE</label>
                <input
                  name="jnt_sender_phone"
                  value="{{ old('jnt_sender_phone', $r->jnt_sender_phone) }}"
                  class="w-full min-w-0 rounded-lg border px-3 py-2 text-sm"
                />
              </div>

              <div class="min-w-0">
                <label class="mb-1 block text-xs font-semibold text-gray-600">PROVINCE</label>
                <select
                  data-role="prov"
                  data-row="{{ $r->id }}"
                  name="jnt_sender_prov"
                  class="w-full min-w-0 rounded-lg border px-3 py-2 text-sm"
                  data-value="{{ old('jnt_sender_prov', $r->jnt_sender_prov) }}"
                ></select>
              </div>

              <div class="min-w-0">
                <label class="mb-1 block text-xs font-semibold text-gray-600">CITY</label>
                <select
                  data-role="city"
                  data-row="{{ $r->id }}"
                  name="jnt_sender_city"
                  class="w-full min-w-0 rounded-lg border px-3 py-2 text-sm"
                  data-value="{{ old('jnt_sender_city', $r->jnt_sender_city) }}"
                ></select>
              </div>

              <div class="min-w-0">
                <label class="mb-1 block text-xs font-semibold text-gray-600">AREA (BRGY)</label>
                <select
                  data-role="area"
                  data-row="{{ $r->id }}"
                  name="jnt_sender_area"
                  class="w-full min-w-0 rounded-lg border px-3 py-2 text-sm"
                  data-value="{{ old('jnt_sender_area', $r->jnt_sender_area) }}"
                ></select>
              </div>
            </div>

            <div class="min-w-0">
              <label class="mb-1 block text-xs font-semibold text-gray-600">FULL ADDRESS</label>
              <textarea
                name="jnt_sender_address"
                rows="3"
                class="w-full min-w-0 rounded-lg border px-3 py-2 text-sm"
              >{{ old('jnt_sender_address', $r->jnt_sender_address) }}</textarea>
            </div>

            <div class="flex flex-wrap items-center gap-2">
              <button class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">
                Update
              </button>

              <form method="POST" action="{{ route('jnt.sender_address.destroy', $r) }}">
                @csrf
                @method('DELETE')
                <button
                  type="submit"
                  onclick="return confirm('Delete row #{{ $r->id }}?')"
                  class="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700"
                >
                  Delete
                </button>
              </form>

              <div class="text-xs text-gray-500 break-words">
                {{ $r->jnt_sender_prov }} | {{ $r->jnt_sender_city }} | {{ $r->jnt_sender_area }}
              </div>
            </div>
          </form>
        </div>
      @endforeach
    </div>

  </div>

  {{-- JS for dropdowns (PROV -> CITY -> AREA) --}}
  <script>
    const ADDRESS_INDEX = @json($addressIndex ?? []);

    function toOptions(arr) {
      return Array.isArray(arr) ? arr : [];
    }

    function setOptions(selectEl, options, selectedValue = '') {
      const prev = selectedValue || '';
      selectEl.innerHTML = '';

      // placeholder
      const ph = document.createElement('option');
      ph.value = '';
      ph.textContent = 'Select...';
      selectEl.appendChild(ph);

      options.forEach(v => {
        const o = document.createElement('option');
        o.value = v;
        o.textContent = v;
        if (v === prev) o.selected = true;
        selectEl.appendChild(o);
      });
    }

    function getProvs() {
      return Object.keys(ADDRESS_INDEX || {}).sort((a,b)=>a.localeCompare(b));
    }

    function getCities(prov) {
      if (!prov || !ADDRESS_INDEX[prov]) return [];
      return Object.keys(ADDRESS_INDEX[prov]).sort((a,b)=>a.localeCompare(b));
    }

    function getAreas(prov, city) {
      if (!prov || !city || !ADDRESS_INDEX[prov] || !ADDRESS_INDEX[prov][city]) return [];
      return toOptions(ADDRESS_INDEX[prov][city]).slice().sort((a,b)=>a.localeCompare(b));
    }

    // CREATE form wiring
    (function initCreate() {
      const provSel = document.getElementById('create_prov');
      const citySel = document.getElementById('create_city');
      const areaSel = document.getElementById('create_area');

      if (!provSel || !citySel || !areaSel) return;

      const oldProv = @json(old('jnt_sender_prov', ''));
      const oldCity = @json(old('jnt_sender_city', ''));
      const oldArea = @json(old('jnt_sender_area', ''));

      setOptions(provSel, getProvs(), oldProv);

      function refreshCities() {
        const prov = provSel.value;
        const cities = getCities(prov);
        setOptions(citySel, cities, oldCity);
        refreshAreas();
      }

      function refreshAreas() {
        const prov = provSel.value;
        const city = citySel.value;
        const areas = getAreas(prov, city);
        setOptions(areaSel, areas, oldArea);
      }

      provSel.addEventListener('change', () => {
        // reset city/area on prov change
        setOptions(citySel, getCities(provSel.value), '');
        setOptions(areaSel, getAreas(provSel.value, ''), '');
      });

      citySel.addEventListener('change', () => {
        setOptions(areaSel, getAreas(provSel.value, citySel.value), '');
      });

      refreshCities();
    })();

    // ROW forms wiring
    (function initRows() {
      const provs = document.querySelectorAll('select[data-role="prov"]');
      provs.forEach(provSel => {
        const rowId = provSel.dataset.row;
        const citySel = document.querySelector(`select[data-role="city"][data-row="${rowId}"]`);
        const areaSel = document.querySelector(`select[data-role="area"][data-row="${rowId}"]`);

        const provVal = provSel.dataset.value || '';
        const cityVal = citySel?.dataset.value || '';
        const areaVal = areaSel?.dataset.value || '';

        setOptions(provSel, getProvs(), provVal);
        setOptions(citySel, getCities(provVal), cityVal);
        setOptions(areaSel, getAreas(provVal, cityVal), areaVal);

        provSel.addEventListener('change', () => {
          const p = provSel.value;
          setOptions(citySel, getCities(p), '');
          setOptions(areaSel, [], '');
        });

        citySel.addEventListener('change', () => {
          setOptions(areaSel, getAreas(provSel.value, citySel.value), '');
        });
      });
    })();
  </script>
</x-layout>
