@props([
  'active' => false,
  'href',
  'label' => null,
  'icon' => false,
  // Optional notification badge — int. Renders as red superscript circle
  // top-right of the icon when > 0. Used by NavBadgeService for "needs
  // attention" indicators (e.g., unmapped pages on JNT Config link).
  'badge' => 0,
])

@php
  $iconOnly = $icon || !is_null($label);

  if ($iconOnly) {
    $base = 'group relative inline-flex items-center justify-center w-10 h-10 rounded-md transition';
    $classes = $active
      ? 'bg-gray-900 text-white'
      : 'text-gray-300 hover:bg-gray-700 hover:text-white';
  } else {
    $base = '';
    $classes = $active
      ? 'bg-gray-900 text-white px-3 py-2 rounded-md text-sm font-medium'
      : 'text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium';
  }
@endphp

<a
  href="{{ $href }}"
  {{ $attributes->merge(['class' => trim($base.' '.$classes)]) }}

  @if($iconOnly && $label)
    x-data="{ show:false, x:0, y:0 }"
    @mouseenter="
      show=true;
      const r=$el.getBoundingClientRect();
      x=r.left + (r.width/2);
      y=r.bottom;

    "
    @mouseleave="show=false"
  @endif
>
  @if($iconOnly)
    <span class="text-base leading-none relative">
      {{ $slot }}
      @if((int) $badge > 0)
        {{-- Messenger-style superscript badge: small red circle, top-right of icon.
             Auto-shrinks to "99+" when count is large. --}}
        <span class="absolute -top-1.5 -right-2 min-w-[16px] h-[16px] px-1 rounded-full
                     bg-red-600 text-white text-[9px] font-bold leading-[16px] text-center
                     ring-1 ring-gray-800 pointer-events-none"
              title="{{ (int) $badge }} item(s) need attention">
          {{ (int) $badge > 99 ? '99+' : (int) $badge }}
        </span>
      @endif
    </span>

    @if($label)
      {{-- Teleport tooltip to body so it won't be clipped by overflow --}}
      <template x-teleport="body">
        <div
          x-cloak
          x-show="show"
          x-transition.opacity.duration.120ms
          class="fixed z-[9999] pointer-events-none whitespace-nowrap rounded-md bg-gray-900 text-white text-xs px-2 py-1 shadow"
          :style="`left:${x}px; top:${y}px; transform:translate(-50%, 10px);`"

        >
          {{ $label }}
        </div>
      </template>
    @endif
  @else
    {{ $slot }}
  @endif
</a>
