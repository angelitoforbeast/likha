<x-layout>
    <x-slot name="title">Ad Account ID</x-slot>
  <x-slot name="heading">Ad Accounts</x-slot>

  @if (session('status'))
    <div class="mb-3 p-3 rounded bg-green-100 text-green-800">
      {{ session('status') }}
    </div>
  @endif

  @if ($errors->any())
    <div class="mb-3 p-3 rounded bg-red-100 text-red-800">
      <ul class="list-disc list-inside">
        @foreach ($errors->all() as $e)
          <li>{{ $e }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <div class="grid md:grid-cols-2 gap-4">
    <!-- Form -->
    <div class="bg-white rounded shadow p-4">
      <h2 class="font-semibold text-lg mb-3">
        {{ $editing ? 'Edit Ad Account' : 'Add Ad Account' }}
      </h2>

      <form method="POST" action="{{ route('ad_accounts.store') }}" class="space-y-3">
        @csrf

        @if($editing)
          <input type="hidden" name="mode" value="update">
          <input type="hidden" name="original_ad_account_id" value="{{ $editing->ad_account_id }}">
        @endif

        <div>
          <label class="block text-sm text-gray-700 mb-1">Ad Account ID</label>
          <input
            type="text"
            name="ad_account_id"
            value="{{ old('ad_account_id', $editing->ad_account_id ?? '') }}"
            class="w-full border rounded px-3 py-2"
            placeholder="e.g. 421938277125959"
            required
          >
        </div>

        <div>
          <label class="block text-sm text-gray-700 mb-1">Ad Account Name</label>
          <input
            type="text"
            name="name"
            value="{{ old('name', $editing->name ?? '') }}"
            class="w-full border rounded px-3 py-2"
            placeholder="e.g. Likha Main Account"
            required
          >
        </div>

        <div class="flex items-center gap-2">
          <button class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
            {{ $editing ? 'Update' : 'Save' }}
          </button>

          @if($editing)
            <a href="{{ route('ad_accounts.index') }}" class="text-sm text-gray-600 hover:underline">
              Cancel
            </a>
          @endif
        </div>
      </form>
    </div>

    <!-- Table -->
    <div class="bg-white rounded shadow overflow-x-auto">
      <table class="min-w-full">
        <thead class="bg-gray-100">
          <tr>
            <th class="text-left p-2 border-b">Ad Account ID</th>
            <th class="text-left p-2 border-b">Name</th>
            <th class="text-left p-2 border-b">Added by</th>
            <th class="text-left p-2 border-b">Last change</th>
            <th class="text-left p-2 border-b w-40">Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse ($rows as $r)
            <tr class="hover:bg-gray-50">
              <td class="p-2 border-b font-mono text-sm">{{ $r->ad_account_id }}</td>
              <td class="p-2 border-b">{{ $r->name }}</td>
              <td class="p-2 border-b text-sm {{ $r->created_by ? '' : 'text-gray-400' }}">{{ $r->added_by_name }}</td>
              <td class="p-2 border-b text-sm whitespace-nowrap">{{ $r->updated_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }}</td>
              <td class="p-2 border-b">
                <a
                  href="{{ route('ad_accounts.edit', ['ad_account_id' => $r->ad_account_id]) }}"
                  class="inline-block px-3 py-1 rounded bg-indigo-600 text-white text-sm hover:bg-indigo-700"
                >Edit</a>

                <form
                  action="{{ route('ad_accounts.destroy', ['ad_account_id' => $r->ad_account_id]) }}"
                  method="POST"
                  class="inline"
                  onsubmit="return confirm('Delete this ad account?');"
                >
                  @csrf
                  @method('DELETE')
                  <button class="ml-2 inline-block px-3 py-1 rounded bg-red-600 text-white text-sm hover:bg-red-700">
                    Delete
                  </button>
                </form>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="5" class="p-4 text-center text-gray-500">No ad accounts yet.</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <!-- Change log: bawat dagdag, palit at bura, pinakabago muna -->
  <div class="bg-white rounded shadow mt-4">
    <h2 class="font-semibold text-lg p-4 pb-2">Change log</h2>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-100">
          <tr>
            <th class="text-left p-2 border-b">When</th>
            <th class="text-left p-2 border-b">Who</th>
            <th class="text-left p-2 border-b">Action</th>
            <th class="text-left p-2 border-b">Ad Account ID</th>
            <th class="text-left p-2 border-b">Name</th>
          </tr>
        </thead>
        <tbody>
          @forelse ($log as $e)
            <tr class="hover:bg-gray-50 align-top">
              <td class="p-2 border-b whitespace-nowrap">{{ $e->at ? \Illuminate\Support\Carbon::parse($e->at)->timezone('Asia/Manila')->format('M j, Y g:i A') : '—' }}</td>
              <td class="p-2 border-b {{ $e->user_id ? '' : 'text-gray-400' }}">{{ $e->who }}</td>
              <td class="p-2 border-b">
                <span class="inline-block px-2 py-0.5 rounded text-xs
                  {{ $e->action === 'deleted' ? 'bg-red-100 text-red-800' : ($e->action === 'changed' ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800') }}">{{ $e->action }}</span>
              </td>
              <td class="p-2 border-b font-mono whitespace-nowrap">
                @if ($e->old_ad_account_id !== null && $e->old_ad_account_id !== $e->ad_account_id)
                  <span class="text-gray-500">{{ $e->old_ad_account_id }}</span> → {{ $e->ad_account_id }}
                @else
                  {{ $e->ad_account_id }}
                @endif
              </td>
              <td class="p-2 border-b">
                @if ($e->old_name !== null && $e->old_name !== $e->name)
                  <span class="text-gray-500">{{ $e->old_name }}</span> → {{ $e->name }}
                @elseif ($e->action === 'deleted')
                  <span class="line-through text-gray-500">{{ $e->name }}</span>
                @else
                  {{ $e->name }}
                @endif
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="5" class="p-4 text-center text-gray-500">Wala pang pagbabago.</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
    @if ($log->hasPages())
      <div class="p-3">{{ $log->links() }}</div>
    @endif
  </div>
</x-layout>
