<x-layout>
  <x-slot name="heading">Dashboard</x-slot>

  <div class="p-4">
    <h1 class="text-xl font-bold mb-4">Welcome, {{ Auth::user()->name }}</h1>

    @if (Auth::user()?->hasRole('CEO'))
        <div class="bg-green-100 text-green-800 px-4 py-2 rounded">
            ✅ You are the CEO
        </div>
    @else
        <div class="bg-yellow-100 text-yellow-800 px-4 py-2 rounded">
            ✅ You are Great!
        </div>
    @endif
  </div>
</x-layout>
