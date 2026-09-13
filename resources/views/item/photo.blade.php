<x-layout>
  <x-slot name="title">Item Photo</x-slot>
  <x-slot name="heading"><div class="text-xl font-bold">🖼 Item Photo</div></x-slot>

  <div style="max-width:600px;margin:24px auto;padding:0 16px;">
    <a href="{{ route('item.index') }}"
       style="font-size:13px;color:#4f46e5;text-decoration:none;font-weight:600;">← Bumalik sa /item</a>

    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-top:12px;">
      <div style="font-size:12px;color:#64748b;font-weight:600;">ITEM</div>
      <div style="font-size:18px;font-weight:800;color:#0f172a;margin-bottom:16px;word-break:break-word;">{{ $itemName }}</div>

      @if(session('photo_ok'))
        <div style="background:#dcfce7;border:1px solid #86efac;color:#166534;border-radius:8px;padding:8px 12px;font-size:13px;margin-bottom:14px;">✓ {{ session('photo_ok') }}</div>
      @endif
      @if(session('photo_error'))
        <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;border-radius:8px;padding:8px 12px;font-size:13px;margin-bottom:14px;">{{ session('photo_error') }}</div>
      @endif
      @error('image')
        <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;border-radius:8px;padding:8px 12px;font-size:13px;margin-bottom:14px;">{{ $message }}</div>
      @enderror

      <div style="display:flex;gap:18px;align-items:flex-start;flex-wrap:wrap;">
        <div>
          <div style="font-size:11px;color:#94a3b8;margin-bottom:5px;">Kasalukuyang photo</div>
          @if($currentUrl)
            <img src="{{ $currentUrl }}" alt="{{ $itemName }}"
                 style="width:170px;height:170px;object-fit:cover;border-radius:10px;border:1px solid #e2e8f0;">
            <a href="{{ $currentUrl }}" target="_blank" rel="noopener"
               style="display:block;text-align:center;font-size:12px;color:#4f46e5;margin-top:6px;text-decoration:none;">View full</a>
          @else
            <div style="width:170px;height:170px;border-radius:10px;border:1px dashed #cbd5e1;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:44px;">🖼</div>
          @endif
        </div>

        <form method="POST" action="{{ route('item.photo.store') }}" enctype="multipart/form-data"
              style="flex:1;min-width:230px;">
          @csrf
          <input type="hidden" name="item_name" value="{{ $itemName }}">
          <label style="font-size:12px;font-weight:600;color:#475569;">Pumili ng {{ $currentUrl ? 'panibagong' : '' }} larawan</label>
          <input type="file" name="image" accept="image/*" required
                 style="display:block;margin:8px 0 10px;font-size:13px;width:100%;">
          <div style="font-size:11px;color:#94a3b8;margin-bottom:14px;">JPG / PNG / WEBP, hanggang 10MB.</div>
          <button type="submit"
                  style="background:#4f46e5;color:#fff;border:0;border-radius:8px;padding:10px 18px;font-weight:700;font-size:13px;cursor:pointer;">
            {{ $currentUrl ? 'Palitan ang photo' : 'I-upload ang photo' }}
          </button>
        </form>
      </div>
    </div>
  </div>
</x-layout>
