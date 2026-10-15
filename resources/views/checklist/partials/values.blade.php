{{-- Mga value na nakuha ng AI sa submission ("fields to extract" ng task), bilang label: value chips --}}
@if($sub->values->isNotEmpty())
  @php $defs = collect($task->extract_fields ?? [])->keyBy('key'); @endphp
  <div class="flex flex-wrap gap-1 mt-1.5">
    @foreach($sub->values as $v)
      @php $def = $defs->get($v->field_key); @endphp
      <span class="text-[11px] leading-tight px-1.5 py-0.5 rounded border border-sky-100 bg-sky-50 text-sky-900"
            title="Nabasa ng AI: {{ $v->raw ?? 'wala' }}">
        <span class="text-sky-600">{{ $def['label'] ?? $v->field_key }}:</span> {{ $v->display($def['type'] ?? null) }}
      </span>
    @endforeach
  </div>
@endif
