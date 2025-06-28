<x-layout>
    <x-slot name="heading">
        Edit Likha Order GSheet Settings
    </x-slot>

    <div class="bg-white p-6 rounded shadow-md w-full max-w-xl mx-auto mt-6">
        <form method="POST" action="{{ url('/likha_order_import/settings') }}" class="space-y-4">
            @csrf

            <div>
                <label for="sheet_id" class="block text-sm font-medium text-gray-700">Google Sheet ID</label>
                <input type="text" name="sheet_id" id="sheet_id"
                    value="{{ old('sheet_id', $setting->sheet_id ?? '') }}"
                    class="mt-1 block w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring focus:border-blue-300" />
            </div>

            <div>
                <label for="range" class="block text-sm font-medium text-gray-700">Range (e.g. `Sheet1!A2:H`)</label>
                <input type="text" name="range" id="range"
                    value="{{ old('range', $setting->range ?? '') }}"
                    class="mt-1 block w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring focus:border-blue-300" />
            </div>

            <button type="submit"
                class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700 transition">
                Save Settings
            </button>

            @if(session('status'))
                <div class="mt-4 p-3 rounded bg-green-100 text-green-800">
                    {{ session('status') }}
                </div>
            @endif
        </form>
        
    </div>
</x-layout>
