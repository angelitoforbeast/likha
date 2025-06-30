<!doctype html>
<html lang="en" class="h-full bg-gray-100">
<head>
  <meta charset="UTF-8">
  <meta name="viewport"
        content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="ie=edge">
  <title>Home Page</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full">
<div class="min-h-full">
  <nav class="bg-gray-800 fixed top-0 inset-x-0 z-50">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <div class="flex h-16 items-center justify-between">
        <div class="flex items-center">
          <div class="shrink-0">
            <img class="size-8" src="https://tailwindcss.com/plus-assets/img/logos/mark.svg?color=indigo&shade=500" alt="Logo" />
          </div>
          <div class="hidden md:block">
            <div class="ml-10 flex items-baseline space-x-4">
              <x-navlink href="/" :active="request()->is('/')">Home</x-navlink>
              <x-navlink href="/botcake" :active="request()->is('botcake')">Botcake</x-navlink>
              <x-navlink href="/from_jnt_view" :active="request()->is('from_jnt_view')">JNT VIEW</x-navlink>
              <x-navlink href="/jnt_update" :active="request()->is('jnt_update')">JNT UPDATE</x-navlink>
              <x-navlink href="/jnt_rts" :active="request()->is('jnt_rts')">JNT RTS</x-navlink>
              <x-navlink href="/ads_manager/index" :active="request()->is('ads_manager/index')">ADS</x-navlink>
              <x-navlink href="/likha_order_import" :active="request()->is('likha_order_import')">LIKHA IMPORT</x-navlink>
              <x-navlink href="/cpp" :active="request()->is('cpp')">CPP</x-navlink>
              <x-navlink href="/encoded_vs_upload" :active="request()->is('encoded_vs_upload')">TALLY STICKER</x-navlink>
            </div>
          </div>
        </div>

        <div class="hidden md:flex items-center space-x-4">
          <span class="text-gray-300 text-sm">
            {{ Auth::user()->name ?? 'User' }}
          </span>

          <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit"
              class="bg-red-600 hover:bg-red-700 text-white text-sm px-4 py-2 rounded transition">
              Logout
            </button>
          </form>
        </div>
      </div>
    </div>
  </nav>

  <header class="bg-white shadow-sm mt-16">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
      <h1 class="text-3xl font-bold tracking-tight text-gray-900">
        {{ $heading ?? 'Dashboard' }}
      </h1>
    </div>
  </header>

  <main>
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
      {{ $slot }}
    </div>
  </main>
</div>
</body>
</html>