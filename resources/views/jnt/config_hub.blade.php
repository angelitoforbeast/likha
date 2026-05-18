<x-layout>
  <x-slot name="heading">JNT Configuration</x-slot>

  <div class="max-w-6xl mx-auto py-4">

    <div class="bg-white rounded-xl shadow p-4 mb-4">
      <div class="font-semibold text-lg mb-1">⚙ JNT Config Hub</div>
      <p class="text-sm text-gray-500">
        Quick links to every JNT configuration screen. Each opens sa same tab. Use the back button to return.
      </p>
    </div>

    @php
      // Card catalog. Add/remove entries here as new JNT config pages appear.
      // `icon` uses Font Awesome classes already loaded by layout.blade.php.
      $cards = [
        [
          'href'  => '/jnt/accounts',
          'icon'  => 'fa-solid fa-building',
          'title' => 'JNT Accounts',
          'desc'  => 'Manage J&T API credentials (customer ID, eccompany ID, secret) — one record per shop / account.',
          'color' => 'indigo',
        ],
        [
          'href'  => '/jnt/accounts/mapping',
          'icon'  => 'fa-solid fa-diagram-project',
          'title' => 'Page → Account Mapping',
          'desc'  => 'Assign each page (from macro_output) to a JNT account. Order creation uses this mapping; unmapped pages fall back to .env.',
          'color' => 'blue',
        ],
        [
          'href'  => '/jnt/sender-name',
          'icon'  => 'fa-solid fa-signature',
          'title' => 'Sender Name (Per Page)',
          'desc'  => 'Set the sender name that appears on waybills for each page.',
          'color' => 'purple',
        ],
        [
          'href'  => '/jnt/item-sender-name',
          'icon'  => 'fa-solid fa-tag',
          'title' => 'Sender Name (Per Item)',
          'desc'  => 'Per (page + item) sender name override. More granular than per-page only.',
          'color' => 'fuchsia',
        ],
        [
          'href'  => '/jnt/item-types',
          'icon'  => 'fa-solid fa-boxes-packing',
          'title' => 'Item Type Mapping',
          'desc'  => 'Map item names → JNT item type (Document, Goods, etc.) for waybill classification.',
          'color' => 'emerald',
        ],
        [
          'href'  => '/jnt/orders',
          'icon'  => 'fa-solid fa-truck-fast',
          'title' => 'JNT Orders',
          'desc'  => 'Bulk-create JNT orders from macro_output and monitor per-run progress.',
          'color' => 'amber',
        ],
        [
          'href'  => '/jnt/waybills/print',
          'icon'  => 'fa-solid fa-print',
          'title' => 'Waybills Print',
          'desc'  => 'Generate printable PDFs for one or more waybills. Uses .env account (not per-page).',
          'color' => 'rose',
        ],
        [
          'href'  => '/jnt/fee-settings',
          'icon'  => 'fa-solid fa-coins',
          'title' => 'Fee Settings',
          'desc'  => 'Shipping fee, COD fee rate, VAT rate — used by all profit / margin calculations across the app.',
          'color' => 'teal',
        ],
        [
          'href'  => '/jnt/supply/excluded-pages',
          'icon'  => 'fa-solid fa-ban',
          'title' => 'Supply Excluded Pages',
          'desc'  => 'Pages to skip in /jnt/supply, /owner/private summaries, and related views.',
          'color' => 'slate',
        ],
      ];
      // Color → tailwind classes lookup so the cards render consistently.
      $tw = [
        'indigo'  => ['bg' => 'bg-indigo-50',  'text' => 'text-indigo-700',  'border' => 'border-indigo-200',  'hover' => 'hover:bg-indigo-100',  'icon' => 'text-indigo-600'],
        'blue'    => ['bg' => 'bg-blue-50',    'text' => 'text-blue-700',    'border' => 'border-blue-200',    'hover' => 'hover:bg-blue-100',    'icon' => 'text-blue-600'],
        'purple'  => ['bg' => 'bg-purple-50',  'text' => 'text-purple-700',  'border' => 'border-purple-200',  'hover' => 'hover:bg-purple-100',  'icon' => 'text-purple-600'],
        'fuchsia' => ['bg' => 'bg-fuchsia-50', 'text' => 'text-fuchsia-700', 'border' => 'border-fuchsia-200', 'hover' => 'hover:bg-fuchsia-100', 'icon' => 'text-fuchsia-600'],
        'emerald' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-700', 'border' => 'border-emerald-200', 'hover' => 'hover:bg-emerald-100', 'icon' => 'text-emerald-600'],
        'amber'   => ['bg' => 'bg-amber-50',   'text' => 'text-amber-700',   'border' => 'border-amber-200',   'hover' => 'hover:bg-amber-100',   'icon' => 'text-amber-600'],
        'rose'    => ['bg' => 'bg-rose-50',    'text' => 'text-rose-700',    'border' => 'border-rose-200',    'hover' => 'hover:bg-rose-100',    'icon' => 'text-rose-600'],
        'teal'    => ['bg' => 'bg-teal-50',    'text' => 'text-teal-700',    'border' => 'border-teal-200',    'hover' => 'hover:bg-teal-100',    'icon' => 'text-teal-600'],
        'slate'   => ['bg' => 'bg-slate-50',   'text' => 'text-slate-700',   'border' => 'border-slate-200',   'hover' => 'hover:bg-slate-100',   'icon' => 'text-slate-600'],
      ];
    @endphp

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
      @foreach($cards as $c)
        @php $cls = $tw[$c['color']] ?? $tw['slate']; @endphp
        <a href="{{ $c['href'] }}"
           class="block border-2 {{ $cls['border'] }} {{ $cls['bg'] }} {{ $cls['hover'] }} rounded-xl p-4 transition shadow-sm hover:shadow-md">
          <div class="flex items-center gap-3 mb-2">
            <div class="{{ $cls['icon'] }} text-2xl w-8 text-center">
              <i class="{{ $c['icon'] }}"></i>
            </div>
            <div class="font-bold {{ $cls['text'] }} text-base">{{ $c['title'] }}</div>
          </div>
          <p class="text-xs text-gray-600 leading-relaxed">{{ $c['desc'] }}</p>
          <div class="mt-3 text-[11px] font-mono text-gray-400">{{ $c['href'] }}</div>
        </a>
      @endforeach
    </div>

    <div class="mt-6 text-xs text-gray-400 text-center">
      Manage which roles see this hub at <a href="{{ route('owner.nav-settings') }}" class="text-blue-600 hover:underline">/owner/nav-settings</a>.
    </div>

  </div>
</x-layout>
