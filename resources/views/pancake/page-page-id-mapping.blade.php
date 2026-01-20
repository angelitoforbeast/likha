<x-layout>
  <x-slot name="title">Pancake Page ID Mapping</x-slot>
  <x-slot name="heading">Pancake Page ID Mapping</x-slot>

  @if (session('success'))
    <div class="max-w-6xl mx-auto mt-4 bg-green-100 text-green-900 p-3 rounded">
      {{ session('success') }}
    </div>
  @endif

  @if ($errors->any())
    <div class="max-w-6xl mx-auto mt-4 bg-red-100 text-red-900 p-3 rounded">
      <div class="font-semibold mb-1">Please fix the following:</div>
      <ul class="list-disc pl-5">
        @foreach ($errors->all() as $e)
          <li>{{ $e }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <div class="max-w-6xl mx-auto mt-6 space-y-6">

    {{-- Add Mapping --}}
    <div class="bg-white shadow rounded-lg p-5">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-lg font-semibold">Add Mapping (1-to-1)</h2>
        <div class="text-sm text-gray-600">
          page_name source: <b>ads_manager_reports.page_name</b>
          @if(isset($pageNames) && $pageNames->count() > 0)
            <span class="ml-2">({{ $pageNames->count() }} options)</span>
          @else
            <span class="ml-2 text-red-700">(ads_manager_reports page_name not found; manual input allowed)</span>
          @endif
        </div>
      </div>

      <div class="text-xs text-gray-600 mb-3">
        Rules: <b>pancake_page_id</b> cannot duplicate, and <b>pancake_page_name</b> cannot duplicate (one-to-one).
      </div>

      <form method="POST" action="{{ route('pancake.page_id_mapping.store') }}" class="grid grid-cols-1 md:grid-cols-3 gap-3">
        @csrf

        <div>
          <label class="block text-sm text-gray-600 mb-1">pancake_page_id (unique)</label>
          <input name="pancake_page_id" value="{{ old('pancake_page_id') }}"
                 class="w-full border border-gray-300 rounded px-3 py-2 text-sm font-mono"
                 placeholder="e.g. 1234567890" required>
        </div>

        <div class="md:col-span-2">
          <label class="block text-sm text-gray-600 mb-1">pancake_page_name (unique)</label>

          <input name="pancake_page_name"
                 value="{{ old('pancake_page_name') }}"
                 class="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                 placeholder="Type to search page name..."
                 @if(isset($pageNames) && $pageNames->count() > 0) list="pageNamesList" @endif
                 required>

          <div class="text-xs text-gray-500 mt-1">
            @if(isset($pageNames) && $pageNames->count() > 0)
              Must match an existing page_name from ads_manager_reports.
            @else
              ads_manager_reports not available; manual input allowed.
            @endif
          </div>
        </div>

        <div class="md:col-span-3">
          <button class="px-4 py-2 rounded bg-black text-white text-sm">
            Save Mapping
          </button>
        </div>
      </form>
    </div>

    {{-- Existing Mappings --}}
    <div class="bg-white shadow rounded-lg p-5">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-lg font-semibold">Existing Mappings</h2>
        <div class="text-sm text-gray-600">
          Total: <b>{{ $mappings->count() }}</b>
        </div>
      </div>

      <div class="overflow-auto">
        <table class="w-full text-sm border">
          <thead class="bg-gray-50">
            <tr>
              <th class="text-left p-2 border">pancake_page_id</th>
              <th class="text-left p-2 border">pancake_page_name</th>
              <th class="text-left p-2 border w-48">Actions</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($mappings as $m)
              <tr class="border-t">
                <td class="p-2 border align-top">
                  <div class="view-row" data-row="{{ $m->id }}">
                    <span class="font-mono">{{ $m->pancake_page_id }}</span>
                  </div>

                  <div class="edit-row hidden" data-row="{{ $m->id }}">
                    <input form="editForm{{ $m->id }}"
                           name="pancake_page_id"
                           value="{{ $m->pancake_page_id }}"
                           class="w-full border border-gray-300 rounded px-2 py-1 text-sm font-mono"
                           required>
                  </div>
                </td>

                <td class="p-2 border align-top">
                  <div class="view-row" data-row="{{ $m->id }}">
                    {{ $m->pancake_page_name }}
                  </div>

                  <div class="edit-row hidden" data-row="{{ $m->id }}">
                    <input form="editForm{{ $m->id }}"
                           name="pancake_page_name"
                           value="{{ $m->pancake_page_name }}"
                           class="w-full border border-gray-300 rounded px-2 py-1 text-sm"
                           placeholder="Type to search page name..."
                           @if(isset($pageNames) && $pageNames->count() > 0) list="pageNamesList" @endif
                           required>
                    <div class="text-xs text-gray-500 mt-1">
                      @if(isset($pageNames) && $pageNames->count() > 0)
                        Must match ads_manager_reports.page_name and must be unique in this table.
                      @else
                        Manual input allowed; must be unique in this table.
                      @endif
                    </div>
                  </div>
                </td>

                <td class="p-2 border align-top">
                  <div class="view-row flex items-center gap-2" data-row="{{ $m->id }}">
                    <button type="button"
                            class="px-3 py-1 rounded bg-gray-900 text-white text-xs"
                            onclick="toggleEdit({{ $m->id }}, true)">
                      Edit
                    </button>

                    <form method="POST" action="{{ route('pancake.page_id_mapping.destroy', $m->id) }}"
                          onsubmit="return confirm('Delete this mapping?')">
                      @csrf
                      @method('DELETE')
                      <button class="px-3 py-1 rounded bg-red-600 text-white text-xs">
                        Delete
                      </button>
                    </form>
                  </div>

                  <div class="edit-row hidden flex items-center gap-2" data-row="{{ $m->id }}">
                    <form id="editForm{{ $m->id }}" method="POST" action="{{ route('pancake.page_id_mapping.update', $m->id) }}">
                      @csrf
                      @method('PUT')
                    </form>

                    <button type="submit"
                            form="editForm{{ $m->id }}"
                            class="px-3 py-1 rounded bg-green-700 text-white text-xs">
                      Save
                    </button>

                    <button type="button"
                            class="px-3 py-1 rounded bg-gray-200 text-gray-900 text-xs"
                            onclick="toggleEdit({{ $m->id }}, false)">
                      Cancel
                    </button>
                  </div>
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="3" class="p-3 text-center text-gray-600">
                  No mappings yet.
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    {{-- Unmapped list from pancake_conversations --}}
    <div class="bg-white shadow rounded-lg p-5">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-lg font-semibold">Unmapped pancake_page_id from pancake_conversations</h2>
        <div class="text-sm text-gray-600">
          Total: <b>{{ is_countable($unmapped) ? count($unmapped) : 0 }}</b>
        </div>
      </div>

      @if (!\Illuminate\Support\Facades\Schema::hasTable('pancake_conversations'))
        <div class="text-sm text-gray-700">
          Table <b>pancake_conversations</b> not found. This section will populate once the table exists.
        </div>
      @else
        <div class="overflow-auto">
          <table class="w-full text-sm border">
            <thead class="bg-gray-50">
              <tr>
                <th class="text-left p-2 border">pancake_page_id</th>
                <th class="text-left p-2 border w-40">Conversations Count</th>
                <th class="text-left p-2 border w-40">Quick Add</th>
              </tr>
            </thead>
            <tbody>
              @forelse ($unmapped as $u)
                <tr class="border-t">
                  <td class="p-2 border font-mono">{{ $u->pancake_page_id }}</td>
                  <td class="p-2 border">{{ $u->conversations_count }}</td>
                  <td class="p-2 border">
                    <button type="button"
                            class="px-3 py-1 rounded bg-black text-white text-xs"
                            onclick="prefillAdd('{{ addslashes($u->pancake_page_id) }}')">
                      Use this ID
                    </button>
                  </td>
                </tr>
              @empty
                <tr>
                  <td colspan="3" class="p-3 text-center text-gray-600">
                    No unmapped pancake_page_id found (all are mapped).
                  </td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>
      @endif
    </div>

  </div>

  {{-- Datalist for page names --}}
  @if(isset($pageNames) && $pageNames->count() > 0)
    <datalist id="pageNamesList">
      @foreach($pageNames as $pn)
        <option value="{{ $pn }}"></option>
      @endforeach
    </datalist>
  @endif

  <script>
    function toggleEdit(rowId, isEdit) {
      document.querySelectorAll('.view-row[data-row="' + rowId + '"]').forEach(el => {
        el.classList.toggle('hidden', isEdit);
      });
      document.querySelectorAll('.edit-row[data-row="' + rowId + '"]').forEach(el => {
        el.classList.toggle('hidden', !isEdit);
      });
    }

    function prefillAdd(pageId) {
      const input = document.querySelector('input[name="pancake_page_id"]');
      if (!input) return;
      input.value = pageId;
      window.scrollTo({ top: 0, behavior: 'smooth' });
      input.focus();
    }
  </script>
</x-layout>
