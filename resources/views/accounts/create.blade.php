<x-layout>
  <x-slot name="title">New Account</x-slot>
  <x-slot name="heading">New Account</x-slot>

  {{-- Spec 298: accounts are made here, by a CEO or a Marketing - OIC, with the role set at creation. --}}
  <div class="max-w-2xl mx-auto bg-white shadow-sm rounded-lg p-6">
    @if (session('created'))
      @php($created = session('created'))
      <div class="mb-6 rounded border border-green-200 bg-green-50 text-green-800 px-4 py-3" role="status" data-account-created>
        <p class="font-semibold">Account created.</p>
        <dl class="mt-2 grid grid-cols-[auto,1fr] gap-x-4 gap-y-1 text-sm">
          <dt class="text-green-700">Name</dt><dd>{{ $created['name'] }}</dd>
          <dt class="text-green-700">Email</dt><dd>{{ $created['email'] }}</dd>
          <dt class="text-green-700">Role</dt><dd>{{ $created['role'] }}</dd>
          @if (!empty($created['employee_code']))
            <dt class="text-green-700">Employee code</dt><dd>{{ $created['employee_code'] }}</dd>
          @endif
        </dl>
        <p class="mt-2 text-sm">They can sign in at <a href="{{ route('login') }}" class="underline">/login</a> with this email and the password you set.</p>
      </div>
    @endif

    <p class="text-sm text-gray-600 mb-6">
      Create a staff account. You set the role and the first password; give both to the person.
    </p>

    <form method="POST" action="{{ route('accounts.store') }}" class="space-y-6">
      @csrf

      <div>
        <label for="name" class="block text-sm font-medium text-gray-700">Name</label>
        <input id="name" name="name" type="text" value="{{ old('name') }}" maxlength="255" required autocomplete="off"
               class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
        @error('name')
          <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
        @enderror
      </div>

      <div>
        <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" maxlength="255" required autocomplete="off"
               class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
        @error('email')
          <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
        @enderror
      </div>

      <div>
        <label for="role" class="block text-sm font-medium text-gray-700">Role</label>
        <select id="role" name="role" required
                class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
          <option value="" @selected(old('role') === null || old('role') === '')>Choose a role</option>
          @foreach ($roles as $role)
            <option value="{{ $role }}" @selected(old('role') === $role)>{{ $role }}</option>
          @endforeach
        </select>
        @error('role')
          <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
        @enderror
      </div>

      <div>
        <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
        <input id="password" name="password" type="password" minlength="{{ $passwordMin }}" maxlength="255" required autocomplete="new-password"
               class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
        <p class="text-xs text-gray-500 mt-1">At least {{ $passwordMin }} characters.</p>
        @error('password')
          <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
        @enderror
      </div>

      <div>
        <label for="password_confirmation" class="block text-sm font-medium text-gray-700">Confirm password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" maxlength="255" required autocomplete="new-password"
               class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:ring-blue-500">
      </div>

      <div class="flex justify-end">
        <button type="submit"
                class="inline-flex items-center px-4 py-2 rounded-md bg-blue-600 text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
          Create account
        </button>
      </div>
    </form>
  </div>
</x-layout>
