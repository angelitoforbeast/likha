<x-layout>
  <x-slot name="heading">Access Report</x-slot>
  <x-slot name="title">Access Report</x-slot>

  <div class="min-h-screen bg-gray-50 mt-16">

    {{-- ===== HEADER: days + role + decision ===== --}}
    <div class="sticky top-16 z-30 bg-white border-b border-gray-200 shadow-sm">
      <form method="GET" action="{{ route('owner.access-report') }}"
            class="max-w-screen-2xl mx-auto px-4 py-2.5 flex flex-wrap items-center gap-2 text-sm">
        <span class="font-bold text-gray-800 mr-2">Who each page would block</span>

        <label class="text-xs text-gray-500" for="days">Last</label>
        <input id="days" type="number" name="days" value="{{ $days }}" min="1" max="30"
               class="text-xs border border-gray-200 rounded-lg px-2 py-1 w-16">
        <span class="text-xs text-gray-500">days</span>

        <select name="role" class="text-xs border border-gray-200 rounded-lg px-2 py-1">
          <option value="">All roles</option>
          @foreach($roles as $r)
            <option value="{{ $r }}" @selected($r === $role)>{{ $r === '' ? 'No role' : $r }}</option>
          @endforeach
        </select>

        <select name="decision" class="text-xs border border-gray-200 rounded-lg px-2 py-1">
          <option value="">All decisions</option>
          @foreach($decisions as $value => $label)
            <option value="{{ $value }}" @selected($value === $decision)>{{ $label }}</option>
          @endforeach
        </select>

        <button type="submit"
                class="text-xs font-semibold bg-blue-600 text-white rounded-lg px-3 py-1 hover:bg-blue-700">Show</button>
      </form>
    </div>

    <div class="max-w-screen-2xl mx-auto px-4 py-5 space-y-4">

      @if($mode === \App\Support\AccessRule::ENFORCE)
        <p class="text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg px-3 py-2">
          Blocking is on: a role that cannot see a page's nav link gets a "no access" page.
        </p>
      @else
        <p class="text-sm text-gray-600 bg-white border border-gray-200 rounded-lg px-3 py-2">
          Recording only: nobody is blocked yet. Each row is a page a role opened without seeing its nav link,
          or a page with no nav link at all.
        </p>
      @endif

      <section class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <h2 class="px-4 pt-3 pb-2 text-sm font-bold text-gray-700">Per role and page</h2>
        @if($rows->isEmpty())
          <p class="px-4 pb-4 text-sm text-gray-500">Nothing recorded in these days.</p>
        @else
          <div class="overflow-x-auto">
            <table class="min-w-full text-xs">
              <thead class="bg-gray-50 text-gray-500">
                <tr>
                  <th class="px-3 py-2 text-left font-semibold">Role</th>
                  <th class="px-3 py-2 text-left font-semibold">Page</th>
                  <th class="px-3 py-2 text-left font-semibold">Nav link</th>
                  <th class="px-3 py-2 text-left font-semibold">Decision</th>
                  <th class="px-3 py-2 text-right font-semibold">Hits</th>
                  <th class="px-3 py-2 text-right font-semibold">People</th>
                  <th class="px-3 py-2 text-left font-semibold">Last time</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                @foreach($rows as $row)
                  <tr class="hover:bg-gray-50">
                    <td class="px-3 py-1.5 whitespace-nowrap">{{ $row->role === '' ? 'No role' : $row->role }}</td>
                    <td class="px-3 py-1.5 font-mono text-gray-800">{{ $row->path_pattern }}</td>
                    <td class="px-3 py-1.5 whitespace-nowrap {{ $row->nav_label ? '' : 'text-gray-400' }}">{{ $row->nav_label ?? 'No nav link' }}</td>
                    <td class="px-3 py-1.5 whitespace-nowrap">
                      @if($row->decision === \App\Support\AccessRule::WOULD_BLOCK)
                        <span class="text-red-700">Would block</span>
                      @else
                        <span class="text-amber-700">Unmapped</span>
                      @endif
                    </td>
                    <td class="px-3 py-1.5 text-right tabular-nums">{{ number_format((int) $row->hits) }}</td>
                    <td class="px-3 py-1.5 text-right tabular-nums">{{ (int) $row->people }}</td>
                    <td class="px-3 py-1.5 whitespace-nowrap text-gray-600">{{ \Illuminate\Support\Carbon::parse($row->last_at)->format('M j, H:i') }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endif
      </section>
    </div>
  </div>
</x-layout>
