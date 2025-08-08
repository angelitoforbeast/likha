<!doctype html>
<html lang="en" class="h-full bg-gray-100">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="ie=edge">
  <title>Home Page</title>
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
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <div class="flex h-16 items-center justify-between">
        <div class="flex items-center">
          <div class="shrink-0">
            <img class="size-8" src="https://static.vecteezy.com/system/resources/previews/018/930/698/original/facebook-logo-facebook-icon-transparent-free-png.png" alt="Logo" />
          </div>
          <div class="hidden md:block">
            <div class="ml-10 flex items-baseline space-x-4">
              @if(in_array($role, ['Marketing', 'Marketing - OIC']))

              {{-- 📂 Tasks Dropdown --}}
              <div x-data="{ open: false }" class="relative">
                <button @click="open = !open"
                        class="text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium relative">
                  <span class="relative">
                    TASKS
                    @if(($pendingTaskCount ?? 0) > 0)
                      <span class="absolute -top-1.5 -right-4 w-4 h-4 bg-red-600 text-white text-[9px] rounded-full flex items-center justify-center font-bold leading-none">
                        {{ $pendingTaskCount }}
                      </span>
                    @elseif(($inProgressTaskCount ?? 0) > 0)
                      <span class="absolute -top-1.5 -right-4 w-4 h-4 bg-blue-600 text-white text-[9px] rounded-full flex items-center justify-center font-bold leading-none">
                        {{ $inProgressTaskCount }}
                      </span>
                    @endif
                  </span>
                </button>

                <div x-show="open" @click.outside="open = false"
                     class="absolute left-0 mt-2 w-56 bg-white shadow-lg rounded-md z-50 py-1 text-sm">
                  <a href="{{ route('task.my-tasks') }}"
                     class="block px-4 py-2 text-gray-700 hover:bg-gray-100 {{ request()->is('task/my-tasks') ? 'font-semibold text-blue-700' : '' }}">
                    📋 My Tasks
                  </a>
                  <a href="{{ route('everyday-tasks.index') }}"
                     class="block px-4 py-2 text-gray-700 hover:bg-gray-100 {{ request()->is('task/my-everyday-task') ? 'font-semibold text-blue-700' : '' }}">
                    🗓 Everyday Tasks
                  </a>
                  <a href="{{ route('task.team-tasks') }}"
                     class="block px-4 py-2 text-gray-700 hover:bg-gray-100 {{ request()->is('task/create-everyday-task') ? 'font-semibold text-blue-700' : '' }}">
                    🧑‍🤝‍🧑 Team Tasks
                  </a>
                </div>
              </div>

              <x-navlink href="/ads_manager/index" :active="request()->is('ads_manager/index')">ADS</x-navlink>
              <x-navlink href="/likha_order_import" :active="request()->is('likha_order_import')">LIKHA</x-navlink>
              <x-navlink href="/macro/gsheet/import" :active="request()->is('macro/gsheet/import')">MACRO</x-navlink>
              <x-navlink href="/cpp" :active="request()->is('cpp')">CPP</x-navlink>
              <x-navlink href="/jnt/checker" :active="request()->is('jnt/checker')">JNT CHECKER</x-navlink>
              <x-navlink href="/encoded_vs_upload" :active="request()->is('encoded_vs_upload')">TALLY STICKER</x-navlink>
              <x-navlink href="/encoder/summary" :active="request()->is('macro_output/index')">ORDER SUMMARY</x-navlink>
              @endif

              @if(in_array($role, ['Data Encoder','Data Encoder - OIC']))
              <x-navlink href="/data_encoder/mes-segregator" :active="request()->is('data_encoder/mes-segregator')">MES SEG</x-navlink>
            
              <x-navlink href="/encoder/checker_1" :active="request()->is('macro_output/index')">CHECKER 1</x-navlink>
              @endif

              @if(in_array($role, ['CEO']))
              <x-navlink href="/cpp" :active="request()->is('cpp')">CPP</x-navlink>
              <x-navlink href="/ads-manager/import-form" :active="request()->is('import-form')">ADS IMPORT</x-navlink>
              <x-navlink href="/ads-manager/edit-messaging-template" :active="request()->is('edit-messaging-template')">CREATIVES</x-navlink>
              <x-navlink href="/gpt-ad-generator" :active="request()->is('gpt-ad-generator')">AD COPY</x-navlink>
              <x-navlink href="/jnt/checker" :active="request()->is('jnt/checker')">JNT CHECKER</x-navlink>
              @endif

              @if(in_array($role, ['Data Encoder - OIC']))
              <x-navlink href="/macro/gsheet/import" :active="request()->is('macro/gsheet/import')">IMPORT MACRO</x-navlink>
              @endif
              @if(in_array($role, ['Data Encoder']))
              <x-navlink href="/encoder/checker_1" :active="request()->is('macro_output/index')">CHECKER 1</x-navlink>
              @endif
            </div>
          </div>
        </div>

        <div class="hidden md:flex items-center space-x-4">
          @if(Auth::check())
            <div class="text-gray-300 text-sm text-right leading-tight">
              <div>{{ Auth::user()->name }}</div>
              <div class="text-xs text-gray-400">
                {{ Auth::user()?->employeeProfile?->role ?? 'No Role' }}
              </div>
            </div>

            <img src="{{ Auth::user()->profile_picture ?? 'https://ui-avatars.com/api/?name=' . urlencode(Auth::user()->name) }}"
                 class="w-10 h-10 rounded-full object-cover border border-gray-500"
                 alt="Profile Picture">
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
    'encoder/checker_1'
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
