<!doctype html>
<html lang="en" class="h-full bg-gray-100">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="ie=edge">
  <title>{{ $title ?? 'Home Page' }}</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/alpinejs" defer></script>
</head>
<body class="h-full">

@php
  $role = Auth::user()?->employeeProfile?->role ?? null;
@endphp

<div class="min-h-full">
  {{-- Top Navigation --}}
  <nav class="bg-gray-800 fixed top-0 inset-x-0 z-50">
    <div class="w-full px-4 sm:px-6 lg:px-8">
      <div class="flex h-16 items-center justify-between">
        <div class="flex items-center flex-1 min-w-0">
          <div class="shrink-0">
            <img class="size-8" src="https://static.vecteezy.com/system/resources/previews/018/930/698/original/facebook-logo-facebook-icon-transparent-free-png.png" alt="Logo" />
          </div>
          <div class="hidden md:flex md:flex-1 overflow-x-auto">
            <div class="ml-10 flex items-center gap-x-4 gap-y-2 flex-1 flex-wrap">
              @if(in_array($role, ['Marketing', 'Marketing - OIC', 'CEO']))

              {{-- (TASKS dropdown removed) --}}
              <x-navlink href="/ads_manager/payment/upload" :active="request()->is('ads_manager/payment')">Ad Payment</x-navlink>
              <x-navlink href="/ads_manager/report" :active="request()->is('ads_manager/report')">Ads</x-navlink>
              <x-navlink href="/likha_order_import" :active="request()->is('likha_order_import')">Likha</x-navlink>
              <x-navlink href="/macro/gsheet/import" :active="request()->is('macro/gsheet/import')">Macro</x-navlink>
              <x-navlink href="/jnt_upload" :active="request()->is('jnt_upload')">Waybill</x-navlink>
              <x-navlink href="/ads_manager/cpp" :active="request()->is('ads_manager/cpp')">CPP</x-navlink>
              <x-navlink href="/jnt_rts" :active="request()->is('jnt_rts')">RTS</x-navlink>
              <x-navlink href="/jnt/checker" :active="request()->is('jnt/checker')">JNT Checker</x-navlink>
              <x-navlink href="/encoded_vs_upload" :active="request()->is('encoded_vs_upload')">Tally Sticker</x-navlink>
              <x-navlink href="/jnt/stickers" :active="request()->is('jnt/stickers')">Tally Sticker 2</x-navlink>
              <x-navlink href="/encoder/checker_1" :active="request()->is('macro_output/index')">Checker 1</x-navlink>
              <x-navlink href="/encoder/summary" :active="request()->is('macro_output/index')">Order Summary</x-navlink>
              <x-navlink href="/ads_manager/pancake-subscription-checker" :active="request()->is('ads_manager/pancake-subscription-checker')">Purchases</x-navlink>
              <x-navlink href="/jnt/hold" :active="request()->is('jnt/hold')">Hold</x-navlink>
              <x-navlink href="/pancake/retrieve2" :active="request()->is('pancake/retrieve2')">Retrieve</x-navlink>
              <x-navlink href="/pancake/page-id-mapping" :active="request()->is('pancake/page-id-mapping')">Pancake ID</x-navlink>
              @endif

              @if(in_array($role, ['Data Encoder','Data Encoder - OIC']))
              <x-navlink href="/data_encoder/mes-segregator" :active="request()->is('data_encoder/mes-segregator')">MES SEG</x-navlink>
              <x-navlink href="/encoder/checker_1" :active="request()->is('macro_output/index')">CHECKER 1</x-navlink>
              <x-navlink href="/jnt/address" :active="request()->is('jnt/address')">Address Search</x-navlink>
              @endif

              @if(in_array($role, ['CEO']))
              <x-navlink href="/assign-roles" :active="request()->is('assign-roles')">Roles</x-navlink>
              <x-navlink href="/allowed-ips" :active="request()->is('allowed-ips')">IP</x-navlink>
              @endif

              @if(in_array($role, ['Data Encoder - OIC']))
              <x-navlink href="/macro/gsheet/import" :active="request()->is('macro/gsheet/import')">IMPORT MACRO</x-navlink>
              <x-navlink href="/encoder/pending-rate" :active="request()->is('encoder/pending-rate')">Pending Rate</x-navlink>
              @endif
              @if(in_array($role, ['Data Encoder']))
              <x-navlink href="/encoder/checker_1" :active="request()->is('macro_output/index')">CHECKER 1</x-navlink>
              @endif
            </div>
          </div>
        </div>

        <div class="hidden md:flex items-center space-x-4">
  @if(Auth::check())
    {{-- Make the whole profile area a link --}}
    <a href="{{ url('/profile') }}" class="flex items-center gap-3 group">
      <div class="text-gray-300 text-sm text-right leading-tight group-hover:text-white">
        <div>{{ Auth::user()->name }}</div>
        <div class="text-xs text-gray-400">
          {{ Auth::user()?->employeeProfile?->role ?? 'No Role' }}
        </div>
      </div>

      <img
        src="{{ Auth::user()->profile_picture ?? 'https://ui-avatars.com/api/?name=' . urlencode(Auth::user()->name) }}"
        class="w-10 h-10 rounded-full object-cover border border-gray-500 group-hover:ring-2 group-hover:ring-white/40 transition"
        alt="Profile Picture">
    </a>
  @endif

  <form method="POST" action="{{ route('logout') }}">
    @csrf
    <button
      type="submit"
      class="bg-red-600 hover:bg-red-700 text-white text-sm px-4 py-2 rounded transition">
      Logout
    </button>
  </form>
</div>

      </div>
    </div>
  </nav>

  {{-- Page heading --}}
  <header class="bg-white shadow-sm mt-16">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
      <h1 class="text-3xl font-bold tracking-tight text-gray-900">
        {{ $heading ?? 'Dashboard' }}
      </h1>
    </div>
  </header>

  {{-- Page content --}}
  <main>
    @if (request()->is([
    'task/my-tasks',
    'macro/gsheet/index',
    'task/team-tasks',
    'ads-manager/edit-messaging-template',
    'encoder/checker_1',
    'ads_manager/campaigns',
    'ads_manager/cpp',
    'jnt/hold',
    'pancake/retrieve-orders',
    'jnt/order-management'
]))
  {{-- 1) SAGAD: full width, no padding --}}
  <div class="w-full px-0">
    {{ $slot }}
  </div>

@elseif (request()->is('cpp') || request()->is('cpp/*'))
  {{-- 2) HINDI SAGAD: full width pero may horizontal padding --}}
  <div class="w-full px-4 md:px-6 lg:px-8">
    {{ $slot }}
  </div>

@else
  {{-- 3) DEFAULT: centered container with max width --}}
  <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    {{ $slot }}
  </div>
@endif
  </main>
</div>
</body>
</html>
