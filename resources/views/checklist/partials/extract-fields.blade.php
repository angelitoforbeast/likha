{{-- Fields to extract: mga value na kukunin ng AI sa bawat submission, mula sa notes at screenshots --}}
<div x-data="{ rows: @js($fields ?? []) }" class="space-y-1.5">
  <label class="text-xs text-gray-500 block">
    Fields to extract <span class="text-gray-400">(optional — the AI reads these from the notes and screenshots on every save)</span>
  </label>
  <template x-for="(f, i) in rows" :key="i">
    <div class="grid grid-cols-12 gap-1.5 items-center">
      <input type="text" x-model="f.label" :name="`extract_fields[${i}][label]`" required maxlength="100"
             placeholder="Label, e.g. Total sticker"
             @blur="if (!f.key) f.key = (f.label || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^[^a-z]+|_+$/g, '')"
             class="col-span-12 sm:col-span-3 min-w-0 border border-gray-300 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-sky-400">
      <input type="text" x-model="f.key" :name="`extract_fields[${i}][key]`" required maxlength="40"
             pattern="[a-z][a-z0-9_]*" title="snake_case lang: a-z, 0-9, _ (hal. total_sticker)" placeholder="key: total_sticker"
             class="col-span-6 sm:col-span-2 min-w-0 border border-gray-300 rounded-lg px-2 py-1.5 text-xs font-mono focus:outline-none focus:ring-1 focus:ring-sky-400">
      <select x-model="f.type" :name="`extract_fields[${i}][type]`"
              class="col-span-5 sm:col-span-2 min-w-0 border border-gray-300 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-sky-400">
        <option value="number">Number</option>
        <option value="money">Money (₱)</option>
        <option value="percent">Percent</option>
        <option value="text">Text</option>
        <option value="date">Date</option>
        <option value="yes_no">Yes / No</option>
      </select>
      <input type="text" x-model="f.hint" :name="`extract_fields[${i}][hint]`" maxlength="300"
             placeholder="Hint for the AI, e.g. Bank balance in the 2nd screenshot"
             class="col-span-11 sm:col-span-4 min-w-0 border border-gray-300 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-sky-400">
      <button type="button" @click="rows.splice(i, 1)" title="Remove field"
              class="col-span-1 text-red-400 hover:text-red-600 text-sm">✕</button>
    </div>
  </template>
  {{-- Walang row: blangkong extract_fields para malaman ng server na inalis ang lahat --}}
  <template x-if="rows.length === 0"><input type="hidden" name="extract_fields" value=""></template>
  <button type="button" @click="rows.push({ key: '', label: '', type: 'number', hint: '' })"
          class="text-xs px-2 py-1 rounded border border-dashed border-gray-300 text-gray-600 hover:bg-gray-50">+ Add field</button>
</div>
