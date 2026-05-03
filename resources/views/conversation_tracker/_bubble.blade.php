@php
  $type    = $bubble['type'] ?? 'text';
  $text    = $bubble['text'] ?? '';
  $url     = $bubble['url']  ?? '';
  $caption = $bubble['caption'] ?? '';
  $accept  = $type === 'image' ? 'image/*' : 'video/*';
@endphp
<div class="bubble" data-type="{{ $type }}" @if($type !== 'text') data-url="{{ $url }}" @endif>
  <div class="bubble-head">
    <span class="bubble-type">
      @if ($type === 'text') 📝 Text
      @elseif ($type === 'image') 🖼️ Image
      @else 🎥 Video
      @endif
    </span>
    <span class="bubble-controls">
      <button type="button" class="bubble-arrow" title="Move up"
        onclick="window.flowEd.moveBubble(this.closest('.bubble'), -1, this.closest('.flow-card'))">↑</button>
      <button type="button" class="bubble-arrow" title="Move down"
        onclick="window.flowEd.moveBubble(this.closest('.bubble'), 1, this.closest('.flow-card'))">↓</button>
      <button type="button" class="bubble-arrow" title="Delete"
        onclick="window.flowEd.deleteBubble(this.closest('.bubble'), this.closest('.flow-card'))">✕</button>
    </span>
  </div>

  @if ($type === 'text')
    <textarea placeholder="Type the message...">{{ $text }}</textarea>
  @else
    <div class="media-drop" tabindex="0">
      <input type="file" accept="{{ $accept }}" hidden>
      @if ($type === 'image')
        <p>1️⃣ <strong>Click here</strong> first to activate this slot</p>
        <p class="hint">2️⃣ Then <strong>Ctrl+V</strong> to paste, drag-drop, or <span class="pick-btn">📂 choose file</span></p>
        <p class="hint">jpg/png/gif/webp · max 5MB</p>
      @else
        <p><strong>Drag-drop</strong> a video, or <span class="pick-btn">📂 choose file</span></p>
        <p class="hint">mp4/webm/mov · max 50MB</p>
      @endif
    </div>
    <div class="media-preview">
      @if ($url)
        @if ($type === 'image')
          <img src="{{ $url }}" alt="">
        @else
          <video controls src="{{ $url }}"></video>
        @endif
        <div class="url">{{ $url }}</div>
      @endif
    </div>
    <input type="text" class="caption-input ct-input mt-2" placeholder="Optional caption" value="{{ $caption }}">
  @endif
</div>
