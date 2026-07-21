@php $stop2 = $pctRts + $pctDelivered; @endphp
<div style="display:flex; flex-wrap:wrap; align-items:center; gap:24px;">
    <div style="position:relative; width:130px; height:130px; flex-shrink:0;">
        {{-- ANG CHART: pure CSS conic-gradient. RTS(red) → Delivered(green) → In Transit(blue) --}}
        <div style="width:130px; height:130px; border-radius:9999px; background:conic-gradient(#dc2626 0 {{ $pctRts }}%, #16a34a {{ $pctRts }}% {{ $stop2 }}%, #2563eb {{ $stop2 }}% 100%);"></div>
        <div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center;">
            <div style="width:74px; height:74px; background:#fff; border-radius:9999px; display:flex; flex-direction:column; align-items:center; justify-content:center; box-shadow:inset 0 0 6px rgba(0,0,0,.08);">
                <span style="font-size:16px; font-weight:700; color:#111827;">{{ number_format($total) }}</span>
                <span style="font-size:9px; color:#9ca3af; text-transform:uppercase; letter-spacing:.05em;">Total</span>
            </div>
        </div>
    </div>
    <div style="font-size:13px; display:flex; flex-direction:column; gap:8px;">
        <div style="display:flex; align-items:center; gap:8px;">
            <span style="width:12px; height:12px; border-radius:3px; background:#dc2626;"></span>
            <span style="color:#374151; width:74px;">RTS</span>
            <span style="font-weight:700; color:#dc2626;">{{ number_format($pctRts, 1) }}%</span>
            <span style="font-size:11px; color:#9ca3af;">({{ number_format($totalRts) }})</span>
        </div>
        <div style="display:flex; align-items:center; gap:8px;">
            <span style="width:12px; height:12px; border-radius:3px; background:#16a34a;"></span>
            <span style="color:#374151; width:74px;">Delivered</span>
            <span style="font-weight:700; color:#16a34a;">{{ number_format($pctDelivered, 1) }}%</span>
            <span style="font-size:11px; color:#9ca3af;">({{ number_format($totalDelivered) }})</span>
        </div>
        <div style="display:flex; align-items:center; gap:8px;">
            <span style="width:12px; height:12px; border-radius:3px; background:#2563eb;"></span>
            <span style="color:#374151; width:74px;">In Transit</span>
            <span style="font-weight:700; color:#2563eb;">{{ number_format($pctTransit, 1) }}%</span>
            <span style="font-size:11px; color:#9ca3af;">({{ number_format($totalTransit) }})</span>
        </div>
    </div>
</div>
