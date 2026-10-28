{{-- Isang chart ng /chatbot/speed sa ilalim ng table nito: ang canvas ay sumusunod sa lapad ng screen (ang data ay sa sp-data). --}}
<div class="cb-card mt-3 p-3">
    <h3 class="mb-1 text-sm font-medium">{{ $title }} <span class="text-xs font-normal text-slate-500">(seconds; dotted = {{ $charts['prev_date'] }})</span></h3>
    <div class="relative {{ ($tall ?? false) ? 'h-80 w-full sm:h-64' : 'h-64 w-full sm:h-56' }}">
        <canvas id="sp-chart-{{ $id }}" role="img" aria-label="{{ $label }}"></canvas>
    </div>
</div>
