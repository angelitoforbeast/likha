<x-layout>
  <x-slot name="heading">Manage Roles</x-slot>

  <div class="mb-4">
    <form method="POST" action="{{ route('roles.store') }}" class="space-x-2">
      @csrf
      <input name="name" placeholder="Role Name" class="border p-1 rounded" required>
      <input name="access_level" placeholder="Access Level" class="border p-1 rounded" type="number">
      <button type="submit" class="bg-blue-500 text-white px-4 py-1 rounded">Add Role</button>
    </form>
  </div>

  @if(session('success'))
    <div class="text-green-600 mb-2">{{ session('success') }}</div>
  @endif

  <table class="table-auto w-full border">
    <thead>
      <tr class="bg-gray-200">
        <th class="border px-4 py-2">ID</th>
        <th class="border px-4 py-2">Name</th>
        <th class="border px-4 py-2">Access Level</th>
        <th class="border px-4 py-2">Action</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($roles as $role)
        <tr>
          <td class="border px-4 py-2 text-center">{{ $role->id }}</td>
          <td class="border px-4 py-2">
            <form action="{{ route('roles.update', $role->id) }}" method="POST" class="flex gap-2">
              @csrf
              <input name="name" value="{{ $role->name }}" class="border rounded px-2 py-1 w-32">
          </td>
          <td class="border px-4 py-2">
              <input name="access_level" type="number" value="{{ $role->access_level }}" class="border rounded px-2 py-1 w-24">
          </td>
          <td class="border px-4 py-2">
              <button type="submit" class="bg-green-600 text-white px-3 py-1 rounded">Save</button>
            </form>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
</x-layout>
