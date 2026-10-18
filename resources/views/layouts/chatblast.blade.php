{{--
  Ang Chatblast bilang sariling app: sariling top bar (brand, To send, History, Messages, Sequences, Page settings),
  walang main nav ng Likha. Top bar at hindi sidebar, para buo ang lapad ng page settings grid (walang horizontal
  scroll). Ang bawat page: @section('title'), @section('heading'), @section('description') (isang linya sa ilalim ng
  title), @section('actions') (sariling links), @section('content'), at @push('styles') / @push('scripts').
  Nakapirmi ang top bar, ang header ng page (title, filter, chip, button) at ang header row ng table; ang data lang ang
  nag-i-scroll. Ang laman ng page ay isang .cb-page na column; ang isang .cb-scroll sa loob nito ang tanging may scroll.
--}}
@php
  $cbItems = \App\Support\ChatblastNav::items(Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Chatblast')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    {{-- Iisang itsura sa lahat ng page: isang primary na button (asul), ang iba ay neutral; chip na pill; card na may
         manipis na border; table na may magaan na hati ng row at walang mabigat na band sa header. --}}
    <style type="text/tailwindcss">
        /* Sa components layer: ang mga utility (hal. hidden) ay nananaig pa rin. */
        @layer components {
        .cb-btn { @apply inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50; }
        .cb-btn-primary { @apply border-blue-600 bg-blue-600 text-white hover:bg-blue-700; }
        .cb-btn-sm { @apply px-2 py-0.5 text-xs; }
        .cb-card { @apply rounded-lg border border-slate-200 bg-white; }
        .cb-table { @apply w-full text-sm; }
        .cb-table thead th { @apply border-b border-slate-200 bg-transparent px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500; }
        .cb-table tbody td { @apply border-b border-slate-100 px-3 py-2.5 align-top; }
        .cb-table tbody tr:last-child td { @apply border-b-0; }
        .cb-table tbody tr:hover td { @apply bg-slate-50; }
        .cb-filters label { @apply text-slate-500; }
        .cb-filters input, .cb-filters select { @apply rounded-md border-slate-300; }
        /* Buong taas na column: ang .cb-scroll lang ang nag-i-scroll (min 16rem; kapag maliit ang screen, ang main ang
           nag-i-scroll). Ang header row ng table ay nakadikit sa taas nito: puti, isang manipis na linya, at magaan na
           anino lang kapag na-scroll na ang mga row. Ang linya ay box-shadow kasi hindi sumasama ang border ng th sa sticky. */
        .cb-page { @apply flex min-h-0 flex-1 flex-col; }
        .cb-page > * { @apply shrink-0; }
        .cb-page > .cb-scroll { @apply min-h-[16rem] flex-1 basis-0 overflow-auto; }
        .cb-scroll thead th { @apply sticky top-0 z-10 border-b-0 bg-white; box-shadow: inset 0 -1px 0 #e2e8f0; }
        .cb-scroll.is-scrolled thead th { box-shadow: inset 0 -1px 0 #e2e8f0, 0 2px 4px -2px rgb(15 23 42 / 0.08); }
        }
    </style>
    @stack('styles')
</head>
<body class="flex h-screen flex-col overflow-hidden bg-slate-50 text-gray-900" data-chatblast-app>

<header class="shrink-0 border-b border-slate-200 bg-white">
    <div class="flex h-14 items-center gap-6 px-4 sm:px-6">
        <a href="{{ url($cbItems[0]['url'] ?? '/') }}" class="flex shrink-0 items-center gap-2">
            <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-violet-600 to-indigo-600 text-white">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7z"/>
                </svg>
            </span>
            <span class="text-base font-semibold tracking-tight text-slate-900">Chatblast</span>
        </a>

        <nav class="flex min-w-0 items-center gap-1 overflow-x-auto text-sm" aria-label="Chatblast">
            @foreach($cbItems as $item)
                <a href="{{ url($item['url']) }}" data-cb-nav="{{ $item['label'] }}" @if($item['active']) aria-current="page" @endif
                   class="whitespace-nowrap rounded-md px-3 py-1.5 font-medium {{ $item['active'] ? 'bg-violet-50 text-violet-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">{{ $item['label'] }}</a>
            @endforeach
        </nav>

        <div class="ml-auto flex shrink-0 items-center gap-4 text-sm">
            <a href="{{ url('/') }}" class="text-slate-500 hover:text-slate-900">← Likha</a>
            @auth
                <span class="hidden text-slate-700 md:inline">{{ Auth::user()->name }}</span>
            @endauth
        </div>
    </div>
</header>

<main class="flex min-h-0 flex-1 flex-col overflow-y-auto px-4 py-4 sm:px-6">
    <div class="mb-3 flex shrink-0 flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">@yield('heading')</h1>
            @hasSection('description')
                <p class="mt-0.5 max-w-5xl text-sm text-slate-500">@yield('description')</p>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-4 pt-1 text-sm">@yield('actions')</div>
    </div>

    @yield('content')
</main>

<script>
    // Ang anino sa ilalim ng header row: lalabas lang kapag na-scroll na ang mga row.
    for (const s of document.querySelectorAll('.cb-scroll')) {
        s.addEventListener('scroll', () => s.classList.toggle('is-scrolled', s.scrollTop > 0), {passive: true});
    }
</script>
@stack('scripts')
</body>
</html>
