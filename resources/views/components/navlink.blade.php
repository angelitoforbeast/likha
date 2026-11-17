@props([
  'active' => false,
  'href',
  'label',
  // Font Awesome class of the link's icon.
  'icon' => 'fa-solid fa-link',
  // Optional notification badge — int. Shown at the right of the row when > 0 (a dot on the icon in the rail).
  'badge' => 0,
  // Para sa Checklist badge: 'red' o 'neutral' (abo), ang hover text, at isang pangalawang maliit
  // na bilang (late sa team ng CEO / OIC). Ang default ay ang dating pulang badge.
  'badgeTone' => 'red',
  'badgeTitle' => null,
  'badgeKey' => null,
  'badgeTeam' => 0,
  'badgeTeamTitle' => null,
])

{{-- One row of the sidebar (spec 295): icon and label; in the 64 px rail only the icon shows, with the label as tooltip. --}}
<a href="{{ $href }}"
   data-nav-link="{{ $href }}"
   data-nav-tip="{{ $label }}"
   @if($active) aria-current="page" @endif
   {{ $attributes->merge(['class' => 'lk-link' . ($active ? ' is-active' : '')]) }}>
  <span class="lk-icon" aria-hidden="true">
    <i class="{{ $icon }}"></i>
    @if((int) $badge > 0 || (int) $badgeTeam > 0)
      <span class="lk-dot {{ (int) $badge > 0 && $badgeTone !== 'neutral' ? 'is-red' : ((int) $badge > 0 ? 'is-neutral' : 'is-team') }}"></span>
    @endif
  </span>
  <span class="lk-label">{{ $label }}</span>
  @if((int) $badge > 0)
    <span class="lk-badge {{ $badgeTone === 'neutral' ? 'is-neutral' : 'is-red' }}"
          @if($badgeKey) data-badge="{{ $badgeKey }}" data-count="{{ (int) $badge }}" data-tone="{{ $badgeTone }}" @endif
          title="{{ $badgeTitle ?? ((int) $badge . ' item(s) need attention') }}">{{ (int) $badge > 99 ? '99+' : (int) $badge }}</span>
  @endif
  @if((int) $badgeTeam > 0)
    <span class="lk-badge is-team"
          @if($badgeKey) data-badge="{{ $badgeKey }}-team" data-count="{{ (int) $badgeTeam }}" @endif
          title="{{ $badgeTeamTitle }}">{{ (int) $badgeTeam > 99 ? '99+' : (int) $badgeTeam }}</span>
  @endif
</a>
