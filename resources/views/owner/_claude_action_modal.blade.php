{{-- Claude / CEO Action / Reason modal — CEO lang (naka-@include sa loob ng @if($isCEO)).
     Isang partial para sa dalawang note: $note = 'claude' | 'ceo', $noteLabel = 'Claude' | 'CEO'.
     Gaya ng Action note modal: floating, drag tab lang ang draggable. Plain text lang (x-model / x-text). --}}
@php
  $m = $note.'Modal';
  $N = ucfirst($note);
  $px = '$'.'{'.$m.'.x}'; // JS template literal: ${xModal.x}
  $py = '$'.'{'.$m.'.y}';
  $ph = $note === 'ceo'
    ? ['Anong aksyon ang gagawin ko sa page na ito…', 'Bakit ito ang aksyon ko…']
    : ['Anong aksyon ang ginawa / irerekomenda sa page na ito…', 'Bakit ito ang aksyon…'];
@endphp
<!-- {{ $note }}-modal-start -->
<template x-if="{{ $m }}.open">
  <div style="position:fixed;inset:0;z-index:9999;background:transparent;" @click.self="{{ $m }}.open = false">
    <div class="ow-modal-card"
         :style="`position:fixed;left:{{ $px }}px;top:{{ $py }}px;width:min(92vw,440px);max-width:440px;margin:0;overflow-x:hidden;overflow-y:auto;max-height:90vh;`">

      <div @mousedown.prevent="start{{ $N }}Drag($event)" title="Drag to move"
           style="cursor:move;user-select:none;background:#0f172a;height:24px;display:flex;align-items:center;justify-content:space-between;padding:0 8px;">
        <span style="color:#64748b;font-size:12px;letter-spacing:3px;line-height:1;">⠿⠿⠿</span>
        <span style="color:#94a3b8;font-size:9px;">drag</span>
        <button type="button" @click="{{ $m }}.open=false" @mousedown.stop
                style="background:none;border:none;color:#94a3b8;font-size:15px;line-height:1;cursor:pointer;padding:0 2px;" title="Close" aria-label="Close">✕</button>
      </div>

      <div class="ow-modal-section" style="border-bottom:1px solid #e2e8f0;">
        <div style="font-size:10.5px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">{{ $noteLabel }} Action / Reason</div>
        <div style="font-size:16px;font-weight:700;color:#0f172a;margin-top:4px;word-break:break-word;" x-text="{{ $m }}.page_name"></div>
        <div style="font-size:12px;color:#475569;margin-top:2px;word-break:break-word;">
          <span style="font-family:ui-monospace,monospace;" x-text="{{ $m }}.ts_date"></span>
        </div>
      </div>

      <div class="ow-modal-section">
        <label for="{{ $note }}-modal-action">{{ $noteLabel }} Action <span style="color:#94a3b8;font-weight:500;text-transform:none;letter-spacing:0;" x-text="'('+({{ $m }}.action||'').length+'/2000)'"></span></label>
        <textarea id="{{ $note }}-modal-action" x-model="{{ $m }}.action" maxlength="2000"
                  x-init="$nextTick(() => { $el.style.height='auto'; $el.style.height=Math.min($el.scrollHeight,220)+'px'; if ({{ $m }}.focus === 'action') $el.focus(); })"
                  @input="$el.style.height='auto'; $el.style.height=Math.min($el.scrollHeight,220)+'px'"
                  placeholder="{{ $ph[0] }}"
                  style="width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:6px;padding:8px;font-size:13px;resize:none;outline:none;min-height:58px;white-space:pre-wrap;overflow-wrap:break-word;overflow-y:auto;"></textarea>

        <label for="{{ $note }}-modal-reason" style="display:block;margin-top:10px;">{{ $noteLabel }} Reason <span style="color:#94a3b8;font-weight:500;text-transform:none;letter-spacing:0;" x-text="'('+({{ $m }}.reason||'').length+'/4000)'"></span></label>
        <textarea id="{{ $note }}-modal-reason" x-model="{{ $m }}.reason" maxlength="4000"
                  x-init="$nextTick(() => { $el.style.height='auto'; $el.style.height=Math.min($el.scrollHeight,220)+'px'; if ({{ $m }}.focus === 'reason') $el.focus(); })"
                  @input="$el.style.height='auto'; $el.style.height=Math.min($el.scrollHeight,220)+'px'"
                  placeholder="{{ $ph[1] }}"
                  style="width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:6px;padding:8px;font-size:13px;resize:none;outline:none;min-height:58px;white-space:pre-wrap;overflow-wrap:break-word;overflow-y:auto;"></textarea>

        <template x-if="{{ $m }}.source || {{ $m }}.at">
          <div style="margin-top:8px;font-size:10px;color:#94a3b8;word-break:break-word;"
               x-text="'last: '+[{{ $m }}.source, {{ $m }}.at].filter(Boolean).join(' · ')"></div>
        </template>
      </div>

      <div class="ow-modal-section" style="border-top:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;gap:8px;">
        <div>
          <template x-if="{{ $m }}.error"><span style="color:#dc2626;font-size:12px;" x-text="{{ $m }}.error"></span></template>
          <template x-if="{{ $m }}.saved"><span style="color:#16a34a;font-size:12px;font-weight:700;" x-text="{{ $m }}.saved"></span></template>
        </div>
        <div style="display:flex;gap:8px;">
          <button type="button" @click="{{ $m }}.open=false"
                  style="background:#fff;border:1px solid #cbd5e1;color:#475569;border-radius:6px;padding:6px 14px;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>
          <button type="button" @click="save{{ $N }}Note()" :disabled="{{ $m }}.saving"
                  style="background:#2563eb;border:1px solid #2563eb;color:#fff;border-radius:6px;padding:6px 16px;font-size:13px;font-weight:700;cursor:pointer;">
            <span x-text="{{ $m }}.saving ? 'Saving…' : 'Save'"></span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
<!-- {{ $note }}-modal-end -->
