{{-- Claude Action / Reason modal — CEO lang (naka-@include sa loob ng @if($isCEO)).
     Gaya ng Action note modal: floating, drag tab lang ang draggable. Plain text lang (x-model / x-text). --}}
<!-- claude-modal-start -->
<template x-if="claudeModal.open">
  <div style="position:fixed;inset:0;z-index:9999;background:transparent;" @click.self="claudeModal.open = false">
    <div class="ow-modal-card"
         :style="`position:fixed;left:${claudeModal.x}px;top:${claudeModal.y}px;width:min(92vw,440px);max-width:440px;margin:0;overflow-x:hidden;overflow-y:auto;max-height:90vh;`">

      <div @mousedown.prevent="startClaudeDrag($event)" title="Drag to move"
           style="cursor:move;user-select:none;background:#0f172a;height:24px;display:flex;align-items:center;justify-content:space-between;padding:0 8px;">
        <span style="color:#64748b;font-size:12px;letter-spacing:3px;line-height:1;">⠿⠿⠿</span>
        <span style="color:#94a3b8;font-size:9px;">drag</span>
        <button type="button" @click="claudeModal.open=false" @mousedown.stop
                style="background:none;border:none;color:#94a3b8;font-size:15px;line-height:1;cursor:pointer;padding:0 2px;" title="Close" aria-label="Close">✕</button>
      </div>

      <div class="ow-modal-section" style="border-bottom:1px solid #e2e8f0;">
        <div style="font-size:10.5px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">Claude Action / Reason</div>
        <div style="font-size:16px;font-weight:700;color:#0f172a;margin-top:4px;word-break:break-word;" x-text="claudeModal.page_name"></div>
        <div style="font-size:12px;color:#475569;margin-top:2px;word-break:break-word;">
          <span style="font-family:ui-monospace,monospace;" x-text="claudeModal.ts_date"></span>
        </div>
      </div>

      <div class="ow-modal-section">
        <label for="claude-modal-action">Claude Action <span style="color:#94a3b8;font-weight:500;text-transform:none;letter-spacing:0;" x-text="'('+(claudeModal.action||'').length+'/2000)'"></span></label>
        <textarea id="claude-modal-action" x-model="claudeModal.action" maxlength="2000"
                  x-init="$nextTick(() => { $el.style.height='auto'; $el.style.height=Math.min($el.scrollHeight,220)+'px'; if (claudeModal.focus === 'action') $el.focus(); })"
                  @input="$el.style.height='auto'; $el.style.height=Math.min($el.scrollHeight,220)+'px'"
                  placeholder="Anong aksyon ang ginawa / irerekomenda sa page na ito…"
                  style="width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:6px;padding:8px;font-size:13px;resize:none;outline:none;min-height:58px;white-space:pre-wrap;overflow-wrap:break-word;overflow-y:auto;"></textarea>

        <label for="claude-modal-reason" style="display:block;margin-top:10px;">Claude Reason <span style="color:#94a3b8;font-weight:500;text-transform:none;letter-spacing:0;" x-text="'('+(claudeModal.reason||'').length+'/4000)'"></span></label>
        <textarea id="claude-modal-reason" x-model="claudeModal.reason" maxlength="4000"
                  x-init="$nextTick(() => { $el.style.height='auto'; $el.style.height=Math.min($el.scrollHeight,220)+'px'; if (claudeModal.focus === 'reason') $el.focus(); })"
                  @input="$el.style.height='auto'; $el.style.height=Math.min($el.scrollHeight,220)+'px'"
                  placeholder="Bakit ito ang aksyon…"
                  style="width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:6px;padding:8px;font-size:13px;resize:none;outline:none;min-height:58px;white-space:pre-wrap;overflow-wrap:break-word;overflow-y:auto;"></textarea>

        <template x-if="claudeModal.source || claudeModal.at">
          <div style="margin-top:8px;font-size:10px;color:#94a3b8;word-break:break-word;"
               x-text="'last: '+[claudeModal.source, claudeModal.at].filter(Boolean).join(' · ')"></div>
        </template>
      </div>

      <div class="ow-modal-section" style="border-top:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;gap:8px;">
        <div>
          <template x-if="claudeModal.error"><span style="color:#dc2626;font-size:12px;" x-text="claudeModal.error"></span></template>
          <template x-if="claudeModal.saved"><span style="color:#16a34a;font-size:12px;font-weight:700;" x-text="claudeModal.saved"></span></template>
        </div>
        <div style="display:flex;gap:8px;">
          <button type="button" @click="claudeModal.open=false"
                  style="background:#fff;border:1px solid #cbd5e1;color:#475569;border-radius:6px;padding:6px 14px;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>
          <button type="button" @click="saveClaudeNote()" :disabled="claudeModal.saving"
                  style="background:#2563eb;border:1px solid #2563eb;color:#fff;border-radius:6px;padding:6px 16px;font-size:13px;font-weight:700;cursor:pointer;">
            <span x-text="claudeModal.saving ? 'Saving…' : 'Save'"></span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
<!-- claude-modal-end -->
