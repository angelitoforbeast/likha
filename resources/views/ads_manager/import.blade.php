<x-layout>
    <x-slot name="heading">📥 Import Ads Manager Report</x-slot>

    @if(session('success'))
        <div class="bg-green-100 text-green-800 p-2 rounded mb-4">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="bg-red-100 text-red-800 p-2 rounded mb-4">
            <ul class="list-disc ml-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('ads-manager.import') }}" method="POST" enctype="multipart/form-data" class="max-w-md space-y-4">
        @csrf

        <div>
            <label class="block font-semibold">Upload Excel File (.xlsx, .xls, .csv)</label>
            <input type="file" name="excel_file" required class="w-full border p-2 rounded" />
        </div>

        <div>
            <button type="submit" class="bg-blue-600 text-white font-semibold px-4 py-2 rounded">
                📤 Import
            </button>
        </div>
    </form>
</x-layout>
