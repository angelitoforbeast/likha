<x-layout>
  <x-slot name="title">Item Photo</x-slot>
  <x-slot name="heading"><div class="text-xl font-bold">🖼 Item Photo</div></x-slot>

  <div style="max-width:640px;margin:24px auto;padding:0 16px;">
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

      <form method="POST" action="{{ route('item.photo.store') }}" enctype="multipart/form-data" id="photoForm">
        @csrf
        <input type="hidden" name="item_name" value="{{ $itemName }}">

        {{-- PASTE zone — mag-click tapos Ctrl+V, o mag-paste kahit saan sa page --}}
        <div id="pasteZone" tabindex="0"
             style="border:2px dashed #c7d2fe;background:#eef2ff;border-radius:10px;
                    padding:18px;text-align:center;cursor:pointer;outline:none;margin-bottom:14px;">
          <div style="font-size:26px;line-height:1;">📋</div>
          <div style="font-size:13px;font-weight:700;color:#3730a3;margin-top:6px;">Mag-paste ng larawan dito</div>
          <div id="pasteHint" style="font-size:11.5px;color:#6366f1;margin-top:3px;">
            I-click ito tapos pindutin ang <b>Ctrl+V</b> (o Cmd+V) — o mag-paste kahit saan sa page.
          </div>
        </div>

        <div style="display:flex;gap:18px;align-items:flex-start;flex-wrap:wrap;">
          <div>
            <div style="font-size:11px;color:#94a3b8;margin-bottom:5px;">Preview</div>
            {{-- Live preview: pasted image kung meron, else kasalukuyang photo, else placeholder --}}
            <img id="pastePreview" alt="preview"
                 src="{{ $currentUrl ?? '' }}"
                 style="width:180px;height:180px;object-fit:cover;border-radius:10px;border:1px solid #e2e8f0;{{ $currentUrl ? '' : 'display:none;' }}">
            <div id="noPreview"
                 style="width:180px;height:180px;border-radius:10px;border:1px dashed #cbd5e1;display:{{ $currentUrl ? 'none' : 'flex' }};align-items:center;justify-content:center;color:#cbd5e1;font-size:44px;">🖼</div>
            @if($currentUrl)
              <a href="{{ $currentUrl }}" target="_blank" rel="noopener"
                 style="display:block;text-align:center;font-size:12px;color:#4f46e5;margin-top:6px;text-decoration:none;">View full</a>
            @endif
          </div>

          <div style="flex:1;min-width:230px;">
            <label style="font-size:12px;font-weight:600;color:#475569;">…o pumili ng file</label>
            <input type="file" name="image" id="itemImageInput" accept="image/*"
                   style="display:block;margin:8px 0 10px;font-size:13px;width:100%;">
            <div style="font-size:11px;color:#94a3b8;margin-bottom:14px;">JPG / PNG / WEBP, hanggang 10MB.</div>
            <button type="submit" id="uploadBtn"
                    style="background:#4f46e5;color:#fff;border:0;border-radius:8px;padding:10px 18px;font-weight:700;font-size:13px;cursor:pointer;">
              {{ $currentUrl ? 'Palitan ang photo' : 'I-upload ang photo' }}
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <script>
    (function(){
      const fileInput  = document.getElementById('itemImageInput');
      const preview    = document.getElementById('pastePreview');
      const noPreview  = document.getElementById('noPreview');
      const hint       = document.getElementById('pasteHint');
      const zone       = document.getElementById('pasteZone');
      const uploadBtn  = document.getElementById('uploadBtn');

      function applyBlob(blob){
        if (!blob || !blob.type || !blob.type.startsWith('image/')) return false;
        // Ilagay sa file input via DataTransfer para kasama sa form submit.
        try {
          const ext  = (blob.type.split('/')[1] || 'png').replace('jpeg','jpg');
          const file = new File([blob], 'pasted-' + Date.now() + '.' + ext, { type: blob.type });
          const dt = new DataTransfer();
          dt.items.add(file);
          fileInput.files = dt.files;
        } catch (e) {
          // Fallback: kung di supported ang DataTransfer, sabihan mag-Choose File.
          hint.innerHTML = '⚠ Hindi suportado ang paste sa browser na ito — gamitin ang "pumili ng file".';
          hint.style.color = '#b91c1c';
          return false;
        }
        // Preview
        const url = URL.createObjectURL(blob);
        preview.src = url; preview.style.display = 'block';
        if (noPreview) noPreview.style.display = 'none';
        hint.innerHTML = '✓ Na-paste! Pindutin ang <b>Upload</b> button para i-save.';
        hint.style.color = '#166534';
        zone.style.borderColor = '#86efac';
        zone.style.background = '#f0fdf4';
        uploadBtn.style.boxShadow = '0 0 0 3px rgba(79,70,229,.25)';
        return true;
      }

      function handlePaste(e){
        const cd = e.clipboardData || window.clipboardData;
        if (!cd) return;
        const items = cd.items || [];
        for (const it of items){
          if (it.kind === 'file' && it.type && it.type.startsWith('image/')){
            const blob = it.getAsFile();
            if (applyBlob(blob)) { e.preventDefault(); return; }
          }
        }
      }

      // Mag-paste kahit saan sa page, at focus ang zone pag-click para malinaw.
      document.addEventListener('paste', handlePaste);
      zone.addEventListener('click', () => zone.focus());

      // Preview din pag manu-manong pumili ng file.
      fileInput.addEventListener('change', function(){
        const f = fileInput.files && fileInput.files[0];
        if (!f) return;
        const url = URL.createObjectURL(f);
        preview.src = url; preview.style.display = 'block';
        if (noPreview) noPreview.style.display = 'none';
      });
    })();
  </script>
</x-layout>
