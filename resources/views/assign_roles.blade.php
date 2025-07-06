<x-layout>
  <x-slot name="heading">Assign Roles</x-slot>

  <div class="p-4">
    <table class="table-auto w-full border">
      <thead>
        <tr class="bg-gray-100">
          <th class="border px-4 py-2">User ID</th>
          <th class="border px-4 py-2">Name</th>
          <th class="border px-4 py-2">Email</th>
          <th class="border px-4 py-2">Role</th>
          <th class="border px-4 py-2">Access Level</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($users as $user)
          @php
            $currentRole = $user->roles->first();
            $roleName = $currentRole?->name ?? '';
            $roleLevel = $currentRole?->access_level ?? '';
          @endphp
          <tr>
            <td class="border px-4 py-2 text-center">{{ $user->id }}</td>
            <td class="border px-4 py-2 text-center">{{ $user->name }}</td>
            <td class="border px-4 py-2 text-center">{{ $user->email }}</td>
            <td class="border px-4 py-2 text-center">
              <select class="border px-2 py-1 rounded role-select" data-user-id="{{ $user->id }}">
                <option value="">— Select Role —</option>
                @foreach ($roles as $role)
                  <option value="{{ $role->name }}" {{ $role->name === $roleName ? 'selected' : '' }}>
                    {{ $role->name }} ({{ $role->access_level }})
                  </option>
                @endforeach
              </select>
            </td>
            <td class="border px-4 py-2 text-center access-level-display">
              {{ $roleLevel }}
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  <script>
    document.querySelectorAll('.role-select').forEach(select => {
      select.addEventListener('change', function () {
        const userId = this.dataset.userId;
        const roleName = this.value;

        fetch('/assign-roles/' + userId, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
          },
          body: JSON.stringify({
            user_id: userId,
            role_name: roleName
          }),
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            const row = this.closest('tr');
            row.querySelector('.access-level-display').textContent = data.access_level;
          } else {
            alert(data.error || 'Failed to update role.');
          }
        })
        .catch(() => alert('Something went wrong.'));
      });
    });
  </script>
</x-layout>
