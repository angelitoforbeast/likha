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
      // Notification badges per nav-link key (count of "needs attention" items).
      $navBadges = \App\Services\NavBadgeService::counts();

      // Card catalog. `nav_key` is the matching nav_links.key — used to look up
      // its badge count. `icon` uses Font Awesome classes loaded by layout.
      $cards = [
        [
          'href'    => '/jnt/accounts',
          'nav_key' => 'jnt_accounts',
          'icon'    => 'fa-solid fa-building',
          'title'   => 'JNT Accounts',
          'desc'    => 'Manage J&T API credentials (customer ID, eccompany ID, secret) — one record per shop / account.',
          'color'   => 'indigo',
        ],
        [
          'href'    => '/jnt/accounts/mapping',
          'nav_key' => 'jnt_acct_mapping',
          'icon'    => 'fa-solid fa-diagram-project',
          'title'   => 'Page → Account Mapping',
          'desc'    => 'Assign each page (from macro_output) to a JNT account. Order creation uses this mapping; unmapped pages fall back to .env.',
          'color'   => 'blue',
        ],
        [
          'href'    => '/jnt/sender-name',
          'nav_key' => 'jnt_sender_name',
          'icon'    => 'fa-solid fa-signature',
          'title'   => 'Sender Name (Per Page)',
          'desc'    => 'Set the sender name that appears on waybills for each page.',
          'color'   => 'purple',
        ],
        [
          'href'    => '/jnt/item-sender-name',
          'nav_key' => 'jnt_item_sender',
          'icon'    => 'fa-solid fa-tag',
          'title'   => 'Sender Name (Per Item)',
          'desc'    => 'Per (page + item) sender name override. More granular than per-page only.',
          'color'   => 'fuchsia',
        ],
        [
          'href'    => '/jnt/item-types',
          'nav_key' => 'jnt_item_types',
          'icon'    => 'fa-solid fa-boxes-packing',
          'title'   => 'Item Type Mapping',
          'desc'    => 'Map item names → JNT item type (Document, Goods, etc.) for waybill classification.',
          'color'   => 'emerald',
        ],
        [
          'href'    => '/jnt/orders',
          'nav_key' => 'jnt_orders',
          'icon'    => 'fa-solid fa-truck-fast',
          'title'   => 'JNT Orders',
          'desc'    => 'Bulk-create JNT orders from macro_output and monitor per-run progress.',
          'color'   => 'amber',
        ],
        [
          'href'    => '/jnt/waybills/print',
          'nav_key' => 'jnt_waybills_print',
          'icon'    => 'fa-solid fa-print',
          'title'   => 'Waybills Print',
          'desc'    => 'Generate printable PDFs for one or more waybills. Picks the right J&T account per source page automatically.',
          'color'   => 'rose',
        ],
        [
          'href'    => '/jnt/waybills/files',
          'nav_key' => 'jnt_waybills_files',
          'icon'    => 'fa-solid fa-folder-open',
          'title'   => 'Waybills Files',
          'desc'    => 'Browse, download, or delete the PDF outputs from previous bulk-print runs.',
          'color'   => 'cyan',
        ],
        [
          'href'    => '/jnt/fee-settings',
          'nav_key' => 'jnt_fee_settings',
          'icon'    => 'fa-solid fa-coins',
          'title'   => 'Fee Settings',
          'desc'    => 'Shipping fee, COD fee rate, VAT rate — used by all profit / margin calculations across the app.',
          'color'   => 'teal',
        ],
        [
          'href'    => '/jnt/supply/excluded-pages',
          'nav_key' => 'jnt_supply_excluded',
          'icon'    => 'fa-solid fa-ban',
          'title'   => 'Supply Excluded Pages',
          'desc'    => 'Pages to skip in /jnt/supply, /owner/private summaries, and related views.',
          'color'   => 'slate',
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
        'cyan'    => ['bg' => 'bg-cyan-50',    'text' => 'text-cyan-700',    'border' => 'border-cyan-200',    'hover' => 'hover:bg-cyan-100',    'icon' => 'text-cyan-600'],
        'slate'   => ['bg' => 'bg-slate-50',   'text' => 'text-slate-700',   'border' => 'border-slate-200',   'hover' => 'hover:bg-slate-100',   'icon' => 'text-slate-600'],
      ];
    @endphp

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
      @foreach($cards as $c)
        @php
          $cls = $tw[$c['color']] ?? $tw['slate'];
          $badge = (int) ($navBadges[$c['nav_key']] ?? 0);
        @endphp
        <a href="{{ $c['href'] }}"
           class="relative block border-2 {{ $cls['border'] }} {{ $cls['bg'] }} {{ $cls['hover'] }} rounded-xl p-4 transition shadow-sm hover:shadow-md">
          @if($badge > 0)
            {{-- Superscript notification badge — small red circle, top-right corner.
                 Hover to see exact tooltip explaining what needs attention. --}}
            <span class="absolute -top-2 -right-2 min-w-[24px] h-[24px] px-1.5 rounded-full
                         bg-red-600 text-white text-xs font-bold leading-[24px] text-center
                         ring-2 ring-white shadow-md"
                  title="{{ $badge }} item(s) need attention — click to review">
              {{ $badge > 99 ? '99+' : $badge }}
            </span>
          @endif
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

    @php
      $totalBadge = array_sum(array_intersect_key(
        $navBadges,
        array_flip(['jnt_acct_mapping', 'jnt_sender_name', 'jnt_item_types', 'jnt_fee_settings', 'jnt_accounts'])
      ));
    @endphp
    @if($totalBadge > 0)
      <div class="mt-4 bg-amber-50 border border-amber-200 text-amber-900 rounded-lg p-3 text-sm">
        ⚠ <strong>{{ $totalBadge }} item(s) across these screens need attention.</strong>
        Tackle the red-badge cards first (unmapped pages, missing fees, etc.) before relying on JNT order creation / waybill printing.
      </div>
    @endif

    <div class="mt-6 text-xs text-gray-400 text-center">
      Manage which roles see this hub at <a href="{{ route('owner.nav-settings') }}" class="text-blue-600 hover:underline">/owner/nav-settings</a>.
    </div>

  </div>
</x-layout>
