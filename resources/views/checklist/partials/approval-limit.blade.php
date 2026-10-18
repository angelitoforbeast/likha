{{-- Limit ng approval check at ang override ng CEO / OIC. Kailangan sa x-data: approvalCount,
     canBypassChecks, overrideUrl, overrideOpen, overrideVerdict, overrideReason, overrideSaving, saveOverride().
     $lockedNow = naka-limit na noong binuksan ang page (ang Alpine ang nagpapakita kapag naabot habang bukas). --}}
<p x-show="!canBypassChecks && approvalCount >= 2" class="mt-1 text-xs text-amber-700" @if($lockedNow ?? false) data-check-locked @endif>
  {{ \App\Http\Controllers\ChecklistController::CHECK_LIMIT_MESSAGE }}
</p>
@if($canBypass)
  <div class="mt-1" data-url="{{ $overrideUrl }}">
    <button type="button" @click="overrideOpen = !overrideOpen" class="text-xs text-emerald-700 underline"
            x-text="overrideOpen ? 'Isara ang override' : 'Override'"></button>
    <form x-show="overrideOpen" x-cloak @submit.prevent="saveOverride()" class="mt-1 flex flex-col gap-1">
      <select x-model="overrideVerdict" aria-label="Verdict" class="rounded border border-gray-300 px-1.5 py-1 text-xs">
        <option value="approved">APPROVED</option>
        <option value="not_approved">NOT APPROVED</option>
      </select>
      <input type="text" x-model="overrideReason" maxlength="500" required placeholder="Dahilan (kailangan)"
             aria-label="Dahilan ng override" class="rounded border border-gray-300 px-1.5 py-1 text-xs">
      <button type="submit" :disabled="overrideSaving || !overrideReason.trim()"
              class="rounded bg-emerald-600 px-2 py-1 text-xs text-white disabled:opacity-60">I-save ang override</button>
    </form>
  </div>
@endif
