<x-layout>
  <x-slot name="heading">My Profile</x-slot>

  <div class="max-w-2xl mx-auto bg-white shadow-sm rounded-lg p-6">
    @if (session('status'))
      <div class="mb-4 rounded border border-green-200 bg-green-50 text-green-700 px-4 py-2">
        {{ session('status') }}
      </div>
    @endif

    <form method="POST" action="{{ route('profile.update') }}" class="space-y-6">
      @csrf

      {{-- Email (read-only) --}}
      <div>
        <label class="block text-sm font-medium text-gray-700">Email</label>
        <input
          type="email"
          value="{{ Auth::user()->email }}"
          disabled
          class="mt-1 block w-full rounded-md border-gray-300 bg-gray-100 text-gray-700 shadow-sm focus:outline-none"
        >
        <p class="text-xs text-gray-500 mt-1">Your email is read-only.</p>
      </div>

      {{-- Name (editable) --}}
      <div>
        <label for="name" class="block text-sm font-medium text-gray-700">Name</label>
        <input
          id="name"
          name="name"
          type="text"
          value="{{ old('name', Auth::user()->name) }}"
          class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
          required
        >
        @error('name')
          <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
        @enderror
      </div>

      <div class="flex justify-end">
        <button
          type="submit"
          class="inline-flex items-center px-4 py-2 rounded-md bg-blue-600 text-white hover:bg-blue-700 focus:outline-none"
        >
          Save Changes
        </button>
      </div>
    </form>
  </div>
</x-layout>
