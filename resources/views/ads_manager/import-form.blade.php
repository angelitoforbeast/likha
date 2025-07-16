<x-layout>
    <x-slot name="heading">📤 Import Ads Manager Report</x-slot>

    @if(session('success'))
        <div class="bg-green-100 text-green-800 px-4 py-2 rounded mb-4">
            {{ session('success') }}
        </div>
    @endif
    

    <form method="POST" action="{{ route('ads-manager.import') }}" enctype="multipart/form-data" class="max-w-md mx-auto space-y-4">
        @csrf

        <div>
            <label for="excel_file" class="block font-medium">Select Excel File</label>
            <input type="file" name="excel_file" id="excel_file" required class="border p-2 rounded w-full" />
        </div>

        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Import</button>
    </form>
</x-layout>
