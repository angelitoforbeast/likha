<x-layout>
  <x-slot name="title">J&T Stickers</x-slot>

  <x-slot name="heading">
    <div class="text-lg font-semibold">J&T Stickers</div>
  </x-slot>

  @php
    $filter_date = $filter_date ?? '';
    $table = $table ?? ['mode'=>'idle','rows'=>[],'summary'=>[]];
    $rows = $table['rows'] ?? [];
    $sum = $table['summary'] ?? [];

    $mode = $table['mode'] ?? 'idle';

    $pdfFilesSelected  = (int)($sum['pdf_files_selected'] ?? 0);
    $pdfFilesReceived  = (int)($sum['pdf_files_received'] ?? 0);
    $pdfFilesTruncated = (int)($sum['pdf_files_truncated'] ?? 0);

    $pdfPagesTotal = (int)($sum['pdf_pages_total'] ?? 0);
    $pdfOkPages    = (int)($sum['pdf_pages_ok'] ?? 0);
    $pdfMissPages  = (int)($sum['pdf_missing_pages'] ?? 0);
    $pdfAmbPages   = (int)($sum['pdf_ambiguous_pages'] ?? 0);

    $dbDate     = (string)($sum['db_filter_date'] ?? $filter_date);
    $dbWaybills = (int)($sum['db_waybills'] ?? 0);

    $cmpOk      = (int)($sum['compare_ok'] ?? 0);
    $dupPdf     = (int)($sum['duplicate_pdf'] ?? 0);
    $dupDb      = (int)($sum['duplicate_db'] ?? 0);
    $missInDb   = (int)($sum['missing_in_db'] ?? 0);
    $missInPdf  = (int)($sum['missing_in_pdf'] ?? 0);

    $hasPdfErr = ($pdfMissPages > 0) || ($pdfAmbPages > 0);
    $hasCompareErr = ($dupPdf > 0) || ($dupDb > 0) || ($missInDb > 0) || ($missInPdf > 0);
    $hasUploadTrunc = ($pdfFilesTruncated > 0);

    $summaryBad = $hasPdfErr || $hasCompareErr || $hasUploadTrunc;
  @endphp

  <style>
    .card { border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; background: #fff; }
    .btn { border: 1px solid #d1d5db; border-radius: 8px; padding: 8px 12px; font-size: 14px; background: #fff; }
    .btn-primary { border-color: #111827; background: #111827; color: #fff; }
    .btn-warn { border-color: #f59e0b; color: #92400e; background:#fffbeb; }
    .btn-danger { border-color: #ef4444; color: #ef4444; background:#fff; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .muted { color: #6b7280; font-size: 13px; }
    .input { width: 220px; border:1px solid #d1d5db; border-radius:10px; padding:8px 10px; }

    table { width: 100%; border-collapse: collapse; }
    th, td { border-bottom: 1px solid #eee; padding: 10px 10px; text-align: left; vertical-align: top; }
    th { font-size: 12px; color: #6b7280; font-weight: 600; }
    .nowrap { white-space: nowrap; }

    .badge { padding:2px 10px; border-radius:999px; font-size:12px; display:inline-block; border:1px solid transparent; }
    .b-ok { background:#ecfdf5; border-color:#a7f3d0; color:#065f46; }
    .b-dup { background:#fff1f2; border-color:#fecaca; color:#9f1239; }
    .b-miss { background:#eff6ff; border-color:#bfdbfe; color:#1e40af; }
    .b-warn { background:#fffbeb; border-color:#fde68a; color:#92400e; }

    .row-bad td { background:#fff7f7; }
    .row-mid td { background:#fffbeb; }

    /* EMPHASIZED SUMMARY */
    .sum-banner{
      border: 1px solid #e5e7eb;
      border-radius: 14px;
      padding: 14px 14px 10px;
      background: #fff;
    }
    .sum-good{
      box-shadow: 0 0 0 2px rgba(16,185,129,0.10);
    }
    .sum-bad{
      border-color: #fecaca;
      box-shadow: 0 0 0 3px rgba(239,68,68,0.12);
      background: linear-gradient(0deg, #fff, #fff), linear-gradient(90deg, rgba(239,68,68,0.06), rgba(239,68,68,0));
    }
    .sum-title{ font-weight: 800; font-size: 15px; margin-bottom: 10px; }
    .sum-row{ display:flex; flex-wrap:wrap; gap:8px; margin-bottom:10px; }

    .sum-chip{
      display:inline-flex; gap:6px; align-items:center;
      border-radius:999px; padding:6px 10px;
      font-size:12px; border:1px solid #e5e7eb; background:#f9fafb;
    }
    .sum-chip .sum-k{ color:#374151; font-weight:600; }
    .sum-chip .sum-v{ color:#111827; font-weight:800; }
    .chip-good{ border-color:#bbf7d0; background:#f0fdf4; }
    .chip-bad{ border-color:#fecaca; background:#fef2f2; color:#7f1d1d; }

    .sum-alert{
      border-radius:12px; padding:10px 12px; font-size:13px;
      border:1px solid #fecaca; background:#fef2f2; color:#7f1d1d;
    }
    .sum-alert.ok{
      border-color:#bbf7d0; background:#f0fdf4; color:#14532d;
    }
    .sum-alert-item{
      display:inline-block; margin-left:8px; padding:2px 8px;
      border-radius:999px; background: rgba(127,29,29,0.08);
      border: 1px solid rgba(127,29,29,0.15);
    }
  </style>

  <div class="p-4 space-y-4">

    {{-- top controls --}}
    <div class="card space-y-3">
      <div class="flex flex-wrap items-end gap-3">

        <div>
          <div class="text-sm font-medium mb-1">Filter Date (DB reference)</div>
          <input id="filter_date" type="date" value="{{ $filter_date }}" class="input" />
          <div class="muted mt-1">
            DB uses: <span class="mono">DATE(STR_TO_DATE(TIMESTAMP, '%H:%i %d-%m-%Y')) = selected date</span>
          </div>
        </div>

        {{-- UPLOAD FORM --}}
        <form id="uploadForm" method="POST" action="{{ route('jnt.stickers.upload') }}" enctype="multipart/form-data" class="min-w-[320px]">
          @csrf
          <input type="hidden" name="filter_date" id="filter_date_upload" value="{{ $filter_date }}">
          <input type="hidden" name="selected_files_count" id="selected_files_count" value="0">

          <div class="text-sm font-medium mb-1">Upload PDF file(s)</div>
          <input id="pdf_files_input" type="file" name="pdf_files[]" accept="application/pdf" multiple required
                 class="block w-full border border-gray-300 rounded-lg p-2" />

          @error('pdf_files') <div class="mono" style="color:#b91c1c;">{{ $message }}</div> @enderror
          @error('pdf_files.*') <div class="mono" style="color:#b91c1c;">{{ $message }}</div> @enderror
        </form>

        {{-- DB FORM --}}
        <form id="dbForm" method="POST" action="{{ route('jnt.stickers.db') }}">
          @csrf
          <input type="hidden" name="filter_date" id="filter_date_db" value="{{ $filter_date }}">
        </form>

        {{-- COMPARE FORM --}}
        <form id="compareForm" method="POST" action="{{ route('jnt.stickers.compare') }}">
          @csrf
          <input type="hidden" name="filter_date" id="filter_date_compare" value="{{ $filter_date }}">
        </form>

        {{-- RESET FORM --}}
        <form id="resetForm" method="POST" action="{{ route('jnt.stickers.reset') }}">
          @csrf
          <input type="hidden" name="filter_date" id="filter_date_reset" value="{{ $filter_date }}">
        </form>

        <div class="flex flex-wrap gap-2">
          <button type="submit" form="uploadForm" class="btn btn-primary">1) Upload &amp; Extract (PDF)</button>
          <button type="submit" form="dbForm" class="btn btn-warn">2) Show Waybills from DB</button>
          <button type="submit" form="compareForm" class="btn btn-primary">3) Compare / Check</button>
          <button type="submit" form="resetForm" class="btn btn-danger">Reset</button>
        </div>
      </div>

      <div class="muted">
        Extraction rule: <b>1 page = 1 waybill</b>. Only pages with exactly 1 candidate are accepted.
      </div>

      @if(session('success'))
        <div class="mono" style="color:#065f46;">{{ session('success') }}</div>
      @endif
    </div>

    {{-- EMPHASIZED SUMMARY --}}
    @php
      $chip = function(string $label, $value, bool $bad=false) {
        $cls = $bad ? 'sum-chip chip-bad' : 'sum-chip chip-good';
        return '<span class="'.$cls.'"><span class="sum-k">'.$label.':</span> <span class="sum-v">'.$value.'</span></span>';
      };
      $bannerClass = $summaryBad ? 'sum-banner sum-bad' : 'sum-banner sum-good';
    @endphp

    <div class="{{ $bannerClass }}">
      <div class="sum-title">Summary</div>

      <div class="sum-row">
        {!! $chip('PDF Files (Selected)', $pdfFilesSelected ?: 0, $pdfFilesSelected > 0 && $pdfFilesTruncated > 0) !!}
        {!! $chip('PDF Files (Received)', $pdfFilesReceived ?: 0, $pdfFilesSelected > 0 && $pdfFilesTruncated > 0) !!}
        {!! $chip('PDF Files (Truncated)', $pdfFilesTruncated ?: 0, $pdfFilesTruncated > 0) !!}
      </div>

      <div class="sum-row">
        {!! $chip('PDF Pages Total', $pdfPagesTotal, $pdfPagesTotal === 0) !!}
        {!! $chip('PDF OK Pages', $pdfOkPages, $pdfOkPages === 0) !!}
        {!! $chip('PDF Missing Pages', $pdfMissPages, $pdfMissPages > 0) !!}
        {!! $chip('PDF Ambiguous Pages', $pdfAmbPages, $pdfAmbPages > 0) !!}
      </div>

      <div class="sum-row">
        {!! $chip('DB Date', $dbDate ?: '-', false) !!}
        {!! $chip('DB Waybills', $dbWaybills, $dbWaybills === 0) !!}
        {!! $chip('Mode', strtoupper($mode), false) !!}
      </div>

      @if($mode === 'compare')
        <div class="sum-row">
          {!! $chip('Compare OK', $cmpOk, ($hasCompareErr || $hasPdfErr) && $cmpOk === 0) !!}
          {!! $chip('Duplicate (PDF)', $dupPdf, $dupPdf > 0) !!}
          {!! $chip('Duplicate (DB)', $dupDb, $dupDb > 0) !!}
          {!! $chip('Missing in DB', $missInDb, $missInDb > 0) !!}
          {!! $chip('Missing in PDF', $missInPdf, $missInPdf > 0) !!}
        </div>
      @endif

      @if($summaryBad)
        <div class="sum-alert">
          Action needed:
          @if($pdfFilesTruncated > 0)
            <span class="sum-alert-item">UPLOAD LIMIT: Selected {{ $pdfFilesSelected }}, Received {{ $pdfFilesReceived }} (missing {{ $pdfFilesTruncated }})</span>
          @endif
          @if($pdfMissPages > 0) <span class="sum-alert-item">PDF Missing Pages: {{ $pdfMissPages }}</span> @endif
          @if($pdfAmbPages > 0) <span class="sum-alert-item">PDF Ambiguous Pages: {{ $pdfAmbPages }}</span> @endif

          @if($mode === 'compare')
            @if($dupPdf > 0) <span class="sum-alert-item">Duplicate (PDF): {{ $dupPdf }}</span> @endif
            @if($dupDb > 0) <span class="sum-alert-item">Duplicate (DB): {{ $dupDb }}</span> @endif
            @if($missInDb > 0) <span class="sum-alert-item">Missing in DB: {{ $missInDb }}</span> @endif
            @if($missInPdf > 0) <span class="sum-alert-item">Missing in PDF: {{ $missInPdf }}</span> @endif
          @endif
        </div>
      @else
        <div class="sum-alert ok">
          ✅ All checks passed. No duplicates, no missing, no PDF errors.
        </div>
      @endif

      @if($pdfFilesTruncated > 0)
        <div class="muted mt-2">
          Reason: PHP <span class="mono">max_file_uploads</span> is limiting how many files reach Laravel (you currently have 20).
        </div>
      @endif
    </div>

    {{-- ONE TABLE --}}
    <div class="card">
      <div class="flex items-center justify-between gap-3 mb-3">
        <div class="font-semibold">One Table Output</div>
        <button class="btn" type="button" onclick="copyAllWaybills()" {{ count($rows) ? '' : 'disabled' }}>
          Copy ALL (Waybills)
        </button>
      </div>

      <div class="overflow-auto">
        <table id="wbTable">
          <thead>
            <tr>
              <th class="nowrap">WAYBILL</th>
              <th class="nowrap">STATUS</th>
              <th class="nowrap">PDF COUNT</th>
              <th class="nowrap">DB COUNT</th>
              <th class="nowrap">DUPLICATE COUNT</th>
              <th>FILE NAME(S)</th>
            </tr>
          </thead>
          <tbody>
            @forelse($rows as $r)
              @php
                $status = $r['status'] ?? 'OK';
                $rowClass = '';
                if ($status === 'DUPLICATE_PDF') $rowClass = 'row-bad';
                elseif ($status !== 'OK') $rowClass = 'row-mid';

                $badgeClass = 'b-ok';
                if ($status === 'PDF' || $status === 'DB') $badgeClass = 'b-warn';
                elseif ($status === 'DUPLICATE_PDF') $badgeClass = 'b-dup';
                elseif ($status === 'MISSING_IN_DB' || $status === 'MISSING_IN_PDF') $badgeClass = 'b-miss';
                elseif ($status === 'DUPLICATE_DB') $badgeClass = 'b-warn';
              @endphp

              <tr class="{{ $rowClass }}">
                <td class="mono nowrap">{{ $r['waybill'] ?? '' }}</td>
                <td class="nowrap"><span class="badge {{ $badgeClass }}">{{ $status }}</span></td>
                <td class="mono nowrap">{{ $r['pdf_count'] ?? 0 }}</td>
                <td class="mono nowrap">{{ $r['db_count'] ?? 0 }}</td>
                <td class="mono nowrap">{{ $r['duplicate_count'] ?? 0 }}</td>
                <td class="mono">{{ $r['files'] ?? '' }}</td>
              </tr>
            @empty
              <tr>
                <td colspan="6" class="muted">No rows yet. Use the buttons above (1 → 2 → 3).</td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>

      <div class="muted mt-2">
        Sorting: problems first (Duplicate PDF → Missing → OK). For PDF-only/DB-only, it lists raw rows.
      </div>
    </div>
  </div>

  <script>
    function syncFilterDate() {
      const v = document.getElementById('filter_date')?.value || '';
      const map = {
        upload: 'filter_date_upload',
        db: 'filter_date_db',
        compare: 'filter_date_compare',
        reset: 'filter_date_reset',
      };
      Object.values(map).forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = v;
      });
    }

    function updateSelectedFilesCount() {
      const inp = document.getElementById('pdf_files_input');
      const out = document.getElementById('selected_files_count');
      if (!inp || !out) return;
      out.value = (inp.files && inp.files.length) ? inp.files.length : 0;
    }

    function attachPreSubmitSync(formId) {
      const f = document.getElementById(formId);
      if (!f) return;
      f.addEventListener('submit', () => {
        // critical: ensures date is not lost even if user didn't blur the input
        syncFilterDate();
        updateSelectedFilesCount();
      });
    }

    document.addEventListener('DOMContentLoaded', () => {
      const fd = document.getElementById('filter_date');
      if (fd) {
        syncFilterDate();
        fd.addEventListener('change', syncFilterDate);
        fd.addEventListener('input', syncFilterDate);
      }

      const inp = document.getElementById('pdf_files_input');
      if (inp) {
        updateSelectedFilesCount();
        inp.addEventListener('change', updateSelectedFilesCount);
      }

      attachPreSubmitSync('uploadForm');
      attachPreSubmitSync('dbForm');
      attachPreSubmitSync('compareForm');
      attachPreSubmitSync('resetForm');
    });

    function getAllWaybillsText() {
      const trs = Array.from(document.querySelectorAll('#wbTable tbody tr'));
      const vals = [];
      for (const tr of trs) {
        const td = tr.querySelector('td');
        if (!td) continue;
        const wb = td.textContent.trim();
        if (wb) vals.push(wb);
      }
      return Array.from(new Set(vals)).join("\n");
    }

    function copyAllWaybills() {
      const text = getAllWaybillsText();
      if (!text) return;

      navigator.clipboard.writeText(text).catch(() => {
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
      });
    }
  </script>
</x-layout>
