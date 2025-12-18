<div style="font-size:13px; margin-bottom:8px;">
  <div><strong>{{ $title }}</strong></div>
  <div>Date selected (for matching): {{ $date }}</div>
  <div>Batch clicked: {{ $batchAt }}</div>
  <div>Total waybills: <strong>{{ count($items) }}</strong></div>
  <div style="margin-top:4px; color:#666;">
    *Showing <strong>Submission Time</strong> and <strong>FULL status_logs</strong> (all dates).
  </div>
</div>

<table style="border-collapse:collapse; width:100%; font-size:13px;">
  <thead>
    <tr>
      <th style="border:1px solid #ccc; padding:6px;">Waybill</th>
      <th style="border:1px solid #ccc; padding:6px;">Current Status</th>
      <th style="border:1px solid #ccc; padding:6px;">Submission Time</th>
      <th style="border:1px solid #ccc; padding:6px;">SigningTime</th>
      <th style="border:1px solid #ccc; padding:6px;">FULL Status Logs</th>
    </tr>
  </thead>
  <tbody>
    @forelse($items as $it)
      <tr>
        <td style="border:1px solid #ccc; padding:6px; white-space:nowrap;">
          {{ $it['waybill'] }}
        </td>

        <td style="border:1px solid #ccc; padding:6px;">
          {{ $it['status'] }}
        </td>

        <td style="border:1px solid #ccc; padding:6px; white-space:nowrap;">
          {{ $it['submission_time'] }}
        </td>

        <td style="border:1px solid #ccc; padding:6px; white-space:nowrap;">
          {{ $it['signingtime'] }}
        </td>

        <td style="border:1px solid #ccc; padding:6px;">
          @if(empty($it['logs']))
            <em>No logs.</em>
          @else
            <ul style="margin:0; padding-left:18px;">
              @foreach($it['logs'] as $l)
                <li style="margin-bottom:4px;">
                  <code>{{ $l['batch_at'] ?? '' }}</code>
                  —
                  <strong>{{ $l['from'] ?? '' }}</strong>
                  →
                  <strong>{{ $l['to'] ?? '' }}</strong>

                  @if(isset($l['upload_log_id']) && $l['upload_log_id'] !== null && $l['upload_log_id'] !== '')
                    <span style="color:#666;">(upload_log_id: {{ $l['upload_log_id'] }})</span>
                  @endif
                </li>
              @endforeach
            </ul>
          @endif
        </td>
      </tr>
    @empty
      <tr>
        <td colspan="5" style="border:1px solid #ccc; padding:8px;">
          No results.
        </td>
      </tr>
    @endforelse
  </tbody>
</table>
