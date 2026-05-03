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
      <button class="bubble-arrow" data-act="up"   title="Move up">↑</button>
      <button class="bubble-arrow" data-act="down" title="Move down">↓</button>
      <button class="bubble-arrow" data-act="del"  title="Delete">✕</button>
    </span>
  </div>

  @if ($type === 'text')
    <textarea placeholder="Type the message...">{{ $text }}</textarea>
  @else
    <div class="media-drop" tabindex="0">
      <input type="file" accept="{{ $accept }}" hidden>
      @if ($type === 'image')
        <p>📎 Click, drag-drop, or <strong>Ctrl+V paste</strong> an image</p>
        <p class="hint">jpg/png/gif/webp · max 5MB</p>
      @else
        <p>📎 Click or drag-drop a video file</p>
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
