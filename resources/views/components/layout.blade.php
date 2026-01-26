<!doctype html>
<html lang="en" class="h-full bg-gray-100">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="ie=edge">
  <title>{{ $title ?? 'Home Page' }}</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/alpinejs" defer></script>

  {{-- Icons (Font Awesome) --}}
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <style>
    [x-cloak] { display: none !important; }
    .no-scrollbar::-webkit-scrollbar { display: none; }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
  </style>
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

        {{-- LEFT: LOGO + NAV --}}
        <div class="flex items-center flex-1 min-w-0">
          <div class="shrink-0">
            <img class="size-8"
                 src="https://static.vecteezy.com/system/resources/previews/018/930/698/original/facebook-logo-facebook-icon-transparent-free-png.png"
                 alt="Logo" />
          </div>

          {{-- DESKTOP NAV --}}
          <div class="hidden md:flex md:flex-1 overflow-visible">
            <div class="ml-6 flex-1 overflow-visible">
              {{-- no-scrollbar hides the visible scrollbar but still allows horizontal scroll --}}
              <div class="no-scrollbar flex items-center gap-2 whitespace-nowrap overflow-x-auto">

                @if(in_array($role, ['Marketing', 'Marketing - OIC', 'CEO']))

                  <x-navlink href="/ads_manager/payment/upload" :active="request()->is('ads_manager/payment*')" label="Ad Payment">
                    <i class="fa-solid fa-credit-card"></i>
                  </x-navlink>

                  <x-navlink href="/ads_manager/report" :active="request()->is('ads_manager/report*')" label="Ads">
                    <i class="fa-solid fa-bullhorn"></i>
                  </x-navlink>

                  <x-navlink href="/likha_order_import" :active="request()->is('likha_order_import*')" label="Likha">
                    <i class="fa-solid fa-store"></i>
                  </x-navlink>

                  <x-navlink href="/macro/gsheet/import" :active="request()->is('macro/gsheet/*')" label="Macro">
                    <i class="fa-solid fa-table"></i>
                  </x-navlink>

                  <x-navlink href="/jnt_upload" :active="request()->is('jnt_upload*')" label="Waybill">
                    <i class="fa-solid fa-receipt"></i>
                  </x-navlink>

                  <x-navlink href="/ads_manager/cpp" :active="request()->is('ads_manager/cpp*')" label="CPP">
                    <i class="fa-solid fa-chart-line"></i>
                  </x-navlink>

                  <x-navlink href="/jnt_rts" :active="request()->is('jnt_rts*')" label="RTS">
                    <i class="fa-solid fa-rotate-left"></i>
                  </x-navlink>

                  <x-navlink href="/jnt/checker" :active="request()->is('jnt/checker*')" label="JNT Checker">
                    <i class="fa-solid fa-circle-check"></i>
                  </x-navlink>

                  <x-navlink href="/encoded_vs_upload" :active="request()->is('encoded_vs_upload*')" label="Tally Sticker">
                    <i class="fa-solid fa-layer-group"></i>
                  </x-navlink>

                  <x-navlink href="/jnt/stickers" :active="request()->is('jnt/stickers*')" label="Tally Sticker 2">
                    <i class="fa-solid fa-tags"></i>
                  </x-navlink>

                  <x-navlink href="/encoder/checker_1" :active="request()->is('encoder/checker_1*')" label="Checker 1">
                    <i class="fa-solid fa-magnifying-glass"></i>
                  </x-navlink>

                  <x-navlink href="/encoder/summary" :active="request()->is('encoder/summary*')" label="Order Summary">
                    <i class="fa-solid fa-clipboard-list"></i>
                  </x-navlink>

                  <x-navlink href="/ads_manager/pancake-subscription-checker" :active="request()->is('ads_manager/pancake-subscription-checker*')" label="Purchases">
                    <i class="fa-solid fa-bag-shopping"></i>
                  </x-navlink>

                  <x-navlink href="/jnt/hold" :active="request()->is('jnt/hold*')" label="Hold">
                    <i class="fa-solid fa-pause"></i>
                  </x-navlink>

                  <x-navlink href="/pancake/retrieve2" :active="request()->is('pancake/retrieve2*')" label="Retrieve">
                    <i class="fa-solid fa-download"></i>
                  </x-navlink>

                  <x-navlink href="/pancake/page-id-mapping" :active="request()->is('pancake/page-id-mapping*')" label="Pancake ID">
                    <i class="fa-solid fa-id-badge"></i>
                  </x-navlink>

                @endif

                @if(in_array($role, ['Data Encoder','Data Encoder - OIC']))
                  <x-navlink href="/data_encoder/mes-segregator" :active="request()->is('data_encoder/mes-segregator*')" label="MES SEG">
                    <i class="fa-solid fa-scissors"></i>
                  </x-navlink>

                  <x-navlink href="/encoder/checker_1" :active="request()->is('encoder/checker_1*')" label="Checker 1">
                    <i class="fa-solid fa-magnifying-glass"></i>
                  </x-navlink>

                  <x-navlink href="/jnt/address" :active="request()->is('jnt/address*')" label="Address Search">
                    <i class="fa-solid fa-location-dot"></i>
                  </x-navlink>
                @endif

                @if(in_array($role, ['CEO']))
                  <x-navlink href="/assign-roles" :active="request()->is('assign-roles*')" label="Roles">
                    <i class="fa-solid fa-user-gear"></i>
                  </x-navlink>

                  <x-navlink href="/allowed-ips" :active="request()->is('allowed-ips*')" label="IP">
                    <i class="fa-solid fa-network-wired"></i>
                  </x-navlink>
                @endif

                @if(in_array($role, ['Data Encoder - OIC']))
                  <x-navlink href="/macro/gsheet/import" :active="request()->is('macro/gsheet/*')" label="Import Macro">
                    <i class="fa-solid fa-file-import"></i>
                  </x-navlink>

                  <x-navlink href="/encoder/pending-rate" :active="request()->is('encoder/pending-rate*')" label="Pending Rate">
                    <i class="fa-solid fa-hourglass-half"></i>
                  </x-navlink>
                @endif

                @if(in_array($role, ['Data Encoder']))
                  <x-navlink href="/encoder/checker_1" :active="request()->is('encoder/checker_1*')" label="Checker 1">
                    <i class="fa-solid fa-magnifying-glass"></i>
                  </x-navlink>
                @endif

              </div>
            </div>
          </div>
        </div>

        {{-- RIGHT: PROFILE + LOGOUT --}}
        <div class="hidden md:flex items-center space-x-4">
          @if(Auth::check())
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
            <button type="submit"
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
      <div class="w-full px-0">
        {{ $slot }}
      </div>

    @elseif (request()->is('cpp') || request()->is('cpp/*'))
      <div class="w-full px-4 md:px-6 lg:px-8">
        {{ $slot }}
      </div>

    @else
      <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        {{ $slot }}
      </div>
    @endif
  </main>
</div>
</body>
</html>
