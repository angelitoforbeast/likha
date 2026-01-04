<x-layout>
    <x-slot name="title">Allowed IPs</x-slot>
    <x-slot name="heading">Allowed IPs (CEO Only)</x-slot>

    <div class="max-w-5xl mx-auto p-4 space-y-4">

        @if(session('success'))
            <div class="p-3 rounded-lg bg-green-50 text-green-700 border border-green-200">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="p-3 rounded-lg bg-red-50 text-red-700 border border-red-200">
                <div class="font-semibold mb-2">May error:</div>
                <ul class="list-disc pl-5 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Add form --}}
        <div class="bg-white rounded-xl shadow p-4 border">
            <div class="font-semibold mb-3">Add IP</div>

            <form method="POST" action="{{ route('allowed_ips.store') }}" class="flex flex-col md:flex-row gap-3">
                @csrf

                <div class="flex-1">
                    <label class="text-sm text-gray-600">IP Address</label>
                    <input name="ip_address" value="{{ old('ip_address') }}"
                           class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring"
                           placeholder="e.g. 103.60.171.10" required>
                </div>

                <div class="flex-1">
                    <label class="text-sm text-gray-600">Label</label>
                    <input name="label" value="{{ old('label') }}"
                           class="w-full border rounded-lg px-3 py-2 focus:outline-none focus:ring"
                           placeholder="e.g. Angelito / Office / Backup">
                </div>

                <div class="flex items-end">
                    <button class="px-4 py-2 rounded-lg bg-black text-white hover:opacity-90">
                        Add
                    </button>
                </div>
            </form>
        </div>

        {{-- List --}}
        <div class="bg-white rounded-xl shadow border overflow-hidden">
            <div class="p-4 border-b font-semibold">Saved IPs</div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left">
                            <th class="px-4 py-3 w-16">ID</th>
                            <th class="px-4 py-3">IP Address</th>
                            <th class="px-4 py-3">Label</th>
                            <th class="px-4 py-3 w-48">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($ips as $ip)
                            <tr class="border-t">
                                <td class="px-4 py-3 text-gray-600">{{ $ip->id }}</td>

                                <td class="px-4 py-3">
                                    <form method="POST" action="{{ route('allowed_ips.update', $ip->id) }}" class="flex gap-2 items-center">
                                        @csrf
                                        @method('PUT')

                                        <input name="ip_address" value="{{ $ip->ip_address }}"
                                               class="w-56 border rounded-lg px-3 py-2 focus:outline-none focus:ring"
                                               required>

                                        <input name="label" value="{{ $ip->label }}"
                                               class="flex-1 min-w-[220px] border rounded-lg px-3 py-2 focus:outline-none focus:ring"
                                               placeholder="Label">

                                        <button class="px-3 py-2 rounded-lg bg-blue-600 text-white hover:opacity-90">
                                            Save
                                        </button>
                                    </form>
                                </td>

                                <td class="px-4 py-3">
                                    {{-- keep blank since label is inside edit row above --}}
                                </td>

                                <td class="px-4 py-3">
                                    <form method="POST" action="{{ route('allowed_ips.destroy', $ip->id) }}"
                                          onsubmit="return confirm('Delete this IP? Baka ma-lockout ka kapag tinanggal mo current IP mo.');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="px-3 py-2 rounded-lg bg-red-600 text-white hover:opacity-90">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr class="border-t">
                                <td colspan="4" class="px-4 py-6 text-center text-gray-500">
                                    No IPs yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="p-4 text-xs text-gray-500 border-t">
                Note: Validation uses Laravel <code>ip</code> rule (IPv4/IPv6). If you later want CIDR support, sabihin mo lang.
            </div>
        </div>
    </div>
</x-layout>
