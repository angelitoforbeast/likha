<!doctype html>
<html lang="en" class="h-full bg-gray-100">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="ie=edge">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ $title ?? ($heading ?? 'Likha') }}</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/alpinejs" defer></script>

  {{-- Icons (Font Awesome) --}}
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <style>
    [x-cloak] { display: none !important; }
    .no-scrollbar::-webkit-scrollbar { display: none; }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    /* Prevent horizontal page scroll — wide content uses its own internal scroll container */
    html, body { overflow-x: hidden; }
  </style>
</head>
<body class="h-full">

@php
  $role = Auth::user()?->employeeProfile?->role ?? null;
  // DB-driven nav — manage at /owner/nav-settings (CEO only).
  // Falls back to empty list if user has no role or DB query fails (defensive).
  try {
    $navLinks = $role
      ? \App\Models\NavLink::visibleFor($role)
      : collect();
  } catch (\Throwable $e) {
    $navLinks = collect();
  }
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

                {{-- DB-driven nav — config at /owner/nav-settings (CEO only).
                     Default visibility seeded from previous hardcoded layout via
                     migration 2026_05_18_140000. New links go thru that route. --}}
                @foreach($navLinks as $link)
                  <x-navlink
                    href="{{ $link->route_url }}"
                    :active="$link->active_pattern ? request()->is($link->active_pattern) : false"
                    :label="$link->label">
                    @if($link->icon)
                      <i class="{{ $link->icon }}"></i>
                    @else
                      <i class="fa-solid fa-link"></i>
                    @endif
                  </x-navlink>
                @endforeach

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

  {{-- Page heading (hidden for checklist pages and full-viewport pages) --}}
  @unless(request()->is('checklist') || request()->is('checklist/*') || request()->is('jnt_rts') || request()->is('jnt_rts/*'))
  <header class="bg-white shadow-sm mt-16">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
      <h1 class="text-3xl font-bold tracking-tight text-gray-900">
        {{ $heading ?? 'Dashboard' }}
      </h1>
    </div>
  </header>
  @endunless

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
      'jnt/order-management',
      'pancake/index',
      'checklist',
      'checklist/*',
      'jnt_rts',
      'jnt_rts/*',
      'jnt/supply',
      'jnt/supply/*',
      'jnt_upload_v2',
      'jnt_upload_v2/*',
      'queue-manager',
      'queue-manager/*',
    ]))
      <div class="w-full px-0">
        {{ $slot }}
      </div>

    @elseif (request()->is('cpp') || request()->is('cpp/*') || request()->is('gpt-ad-generator') || request()->is('gpt-ad-generator/*') || request()->is('conversation/tracker') || request()->is('conversation/tracker/*'))
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
