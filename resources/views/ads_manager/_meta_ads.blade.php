{{-- Facebook (Meta Ads API): connect, Compare with uploads, Sync now, write mode. Walang token dito, kahit kailan. --}}
<div class="border rounded p-4 space-y-4" id="meta-ads-card">
  <div class="flex items-center justify-between flex-wrap gap-2">
    <h2 class="font-semibold">Facebook (Meta Ads API)</h2>
    <span class="text-xs px-2 py-0.5 rounded {{ $meta['write_mode'] ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700' }}">
      Write mode: {{ $meta['write_mode'] ? 'ON' : 'OFF (compare only)' }}
    </span>
  </div>

  @error('meta')
    <div class="rounded bg-red-100 text-red-800 px-3 py-2 text-sm">{{ $message }}</div>
  @enderror

  @if (!$meta['set_up'])
    <p class="text-sm text-gray-600">Meta app not set up yet. Kapag naka-set na ang app, dito magko-connect ng Facebook.</p>
  @else
    <div class="flex gap-2 flex-wrap">
      @if ($meta['mine'])
        <form method="POST" action="{{ route('ads_manager.meta.disconnect') }}" onsubmit="return confirm('Disconnect your Facebook?');">
          @csrf
          <button class="bg-gray-200 hover:bg-gray-300 text-gray-800 text-sm px-3 py-1.5 rounded">Disconnect my Facebook</button>
        </form>
      @else
        <a href="{{ route('ads_manager.meta.connect') }}" class="bg-blue-600 hover:bg-blue-700 text-white text-sm px-3 py-1.5 rounded">Connect Facebook</a>
      @endif
    </div>
  @endif

  {{-- Mga naka-connect --}}
  <div class="overflow-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-gray-50">
        <tr class="text-left">
          <th class="p-2">Person</th>
          <th class="p-2">Ad accounts</th>
          <th class="p-2">Last sync</th>
          <th class="p-2">Expires</th>
          <th class="p-2"></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($meta['people'] as $p)
          <tr class="border-t align-top">
            <td class="p-2">{{ $p['likha_name'] }}<div class="text-xs text-gray-500">Facebook: {{ $p['fb_user_name'] ?? '—' }}</div></td>
            <td class="p-2 text-xs">
              @foreach ($p['ad_accounts'] ?? [] as $a)
                <div>{{ $a['name'] }} <span class="font-mono text-gray-500">{{ $a['id'] }}</span></div>
              @endforeach
            </td>
            <td class="p-2 text-xs font-mono">{{ !empty($p['last_sync_at']) ? \Carbon\Carbon::parse($p['last_sync_at'])->timezone('Asia/Manila')->format('M j, g:i A') : '—' }}</td>
            <td class="p-2 text-xs font-mono">{{ !empty($p['expires_at']) ? \Carbon\Carbon::createFromTimestamp($p['expires_at'])->timezone('Asia/Manila')->format('M j, Y') : '—' }}</td>
            <td class="p-2">
              @if ($p['reconnect'])
                @if ($p['mine'] && $meta['set_up'])
                  <a href="{{ route('ads_manager.meta.connect') }}" class="bg-red-600 hover:bg-red-700 text-white text-xs px-2 py-1 rounded">Reconnect</a>
                @else
                  <span class="bg-red-100 text-red-800 text-xs px-2 py-1 rounded">Reconnect</span>
                @endif
                <div class="text-xs text-red-700 mt-1">{{ ($p['status'] ?? 'ok') === 'expired' ? 'Token expired' : 'Expires within 7 days' }}</div>
              @else
                <span class="text-xs text-green-700">OK</span>
              @endif
            </td>
          </tr>
        @empty
          <tr><td class="p-2 text-gray-500" colspan="5">Wala pang naka-connect na Facebook.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  {{-- Mga ad account na binabasa ng Sync at Compare (CEO, Marketing, Marketing - OIC ang pumipili); ang numero ay mula sa huling Compare --}}
  @if ($meta['accounts'])
    <form method="POST" action="{{ route('ads_manager.meta.accounts') }}" class="border-t pt-3 space-y-2" id="meta-accounts">
      @csrf
      <h3 class="font-semibold text-sm">Ad accounts to sync</h3>
      <p class="text-xs text-gray-500">
        Sync now at ang schedule: ang may check lang. Compare: ang may check, o lahat kapag walang may check.
        Ang bagong account ay naka-check na; ang inalisan ng check ay hindi na babalik.
      </p>
      <div class="divide-y border rounded">
        @foreach ($meta['accounts'] as $a)
          @php $n = $a['compare']; @endphp
          <label class="flex items-start gap-3 p-2 text-sm {{ $meta['can_choose_accounts'] ? 'cursor-pointer' : '' }}">
            <input type="checkbox" name="accounts[]" value="{{ $a['id'] }}" @checked($a['selected']) @disabled(!$meta['can_choose_accounts']) class="mt-1 h-5 w-5 shrink-0">
            <span class="min-w-0">
              <span class="font-medium break-words">{{ $a['name'] !== '' ? $a['name'] : 'Ad account' }}</span>
              <span class="font-mono text-xs text-gray-500 break-all">{{ $a['id'] }}</span>
              <span class="block text-xs text-gray-500 break-words">Facebook: {{ implode(', ', $a['who']) }}</span>
              @if ($n)
                <span class="block text-xs">
                  upload {{ $n['upload_rows'] }} rows · ₱{{ number_format($n['upload_spend'], 2) }} ·
                  API {{ $n['api_rows'] }} rows · ₱{{ number_format($n['api_spend'], 2) }}
                  @if ((float) $n['upload_spend'] == 0.0)<span class="ml-1 px-1.5 rounded bg-yellow-100 text-yellow-800">not in uploads</span>@endif
                </span>
              @else
                <span class="block text-xs text-gray-400">Wala pa sa huling Compare.</span>
              @endif
            </span>
          </label>
        @endforeach
      </div>
      @if ($meta['can_choose_accounts'])
        <button class="bg-gray-800 hover:bg-gray-900 text-white text-sm px-4 py-2 rounded">Save accounts</button>
      @else
        <p class="text-xs text-gray-500">CEO, Marketing at Marketing - OIC lang ang nakakapagpalit ng pili.</p>
      @endif
    </form>
  @endif

  {{-- Compare / Sync now --}}
  <div class="flex gap-3 flex-wrap items-end">
    <form method="POST" action="{{ route('ads_manager.meta.compare') }}" class="flex gap-2 flex-wrap items-end">
      @csrf
      <label class="text-xs">From<input type="date" name="from" value="{{ $meta['range'][0] }}" class="border rounded p-1 block"></label>
      <label class="text-xs">To<input type="date" name="to" value="{{ $meta['range'][1] }}" class="border rounded p-1 block"></label>
      <button class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm px-3 py-1.5 rounded" onclick="this.disabled=true;this.form.submit();">Compare with uploads</button>
    </form>
    @if ($meta['write_mode'])
      <form method="POST" action="{{ route('ads_manager.meta.sync') }}">
        @csrf
        <button class="bg-green-600 hover:bg-green-700 text-white text-sm px-3 py-1.5 rounded" onclick="this.disabled=true;this.form.submit();">Sync now</button>
      </form>
    @endif
  </div>

  @if ($meta['is_ceo'])
    <form method="POST" action="{{ route('ads_manager.meta.write_mode') }}" class="flex gap-2 flex-wrap items-end text-sm border-t pt-3">
      @csrf
      <label class="text-xs">Write mode (CEO)
        <select name="write_mode" class="border rounded p-1 block">
          <option value="off" @selected(!$meta['write_mode'])>Off: compare only</option>
          <option value="on" @selected($meta['write_mode'])>On: schedule + Sync now write the report</option>
        </select>
      </label>
      <label class="text-xs">Schedule (Manila, H:i, comma)
        <input type="text" name="schedule" value="{{ implode(',', $meta['schedule']) }}" class="border rounded p-1 block w-48">
      </label>
      <button class="bg-gray-800 hover:bg-gray-900 text-white text-sm px-3 py-1.5 rounded">Save</button>
    </form>
  @else
    <p class="text-xs text-gray-500">Schedule (kapag naka-on ang write mode): {{ implode(', ', $meta['schedule']) }} Manila.</p>
  @endif

  {{-- Resulta ng Compare --}}
  @php $cmpLog = $meta['compare']; @endphp
  @if ($cmpLog && in_array($cmpLog->status, ['queued', 'processing'], true))
    <div class="border-t pt-3 text-sm text-indigo-700" id="meta-compare-running">
      Compare running… ({{ $cmpLog->original_name }}). Nagre-refresh ang page kada 5 segundo.
    </div>
    <script>setTimeout(() => location.reload(), 5000);</script>
  @elseif ($cmpLog && $cmpLog->status === 'failed')
    <div class="border-t pt-3 text-xs text-red-700">Compare #{{ $cmpLog->id }} failed: {{ $cmpLog->error_message }}</div>
  @elseif ($cmpLog && is_array($cmpLog->protected_details))
    @php $cmp = $cmpLog->protected_details; @endphp
    <div class="border-t pt-3 space-y-3" id="meta-compare">
      <h3 class="font-semibold text-sm">Compare: API vs uploads, {{ $cmp['from'] }} to {{ $cmp['to'] }} (walang isinulat)</h3>
      @foreach ($cmp['problems'] as $pr)
        <div class="text-xs text-red-700">{{ $pr }}</div>
      @endforeach
      @forelse ($cmp['accounts'] as $a)
        <div class="border rounded p-2 text-xs space-y-1">
          <div class="font-semibold">{{ $a['name'] }} <span class="font-mono text-gray-500">{{ $a['account_id'] }}</span> · {{ $a['who'] }}</div>
          <div>API rows {{ $a['api_rows'] }} · upload rows {{ $a['upload_rows'] }} · same {{ $a['matched'] }} ·
            spend API {{ number_format($a['api_spend'], 2) }} vs upload {{ number_format($a['upload_spend'], 2) }}</div>
          <div>Only in API: {{ $a['only_api_count'] }} · only in upload: {{ $a['only_upload_count'] }} · differing: {{ $a['differ_count'] }}</div>
          @if ($a['only_api'])
            <details><summary>Only in API</summary>
              @foreach ($a['only_api'] as $r)<div class="font-mono">{{ $r['day'] }} ad {{ $r['ad_id'] }} · spend {{ number_format($r['spend'], 2) }}</div>@endforeach
            </details>
          @endif
          @if ($a['only_upload'])
            <details><summary>Only in upload</summary>
              @foreach ($a['only_upload'] as $r)<div class="font-mono">{{ $r['day'] }} ad {{ $r['ad_id'] }} · spend {{ number_format($r['spend'], 2) }}</div>@endforeach
            </details>
          @endif
          @if ($a['differ'])
            <details open><summary>Differing</summary>
              @foreach ($a['differ'] as $r)
                <div class="font-mono">{{ $r['day'] }} ad {{ $r['ad_id'] }}:
                  @foreach ($r['fields'] as $f => $d)
                    {{ $f }} API {{ $d['api'] }} vs upload {{ $d['upload'] }} ({{ $d['pct'] === null ? 'n/a' : (($d['pct'] > 0 ? '+' : '') . $d['pct'] . '%') }})@if (!$loop->last); @endif
                  @endforeach
                </div>
              @endforeach
            </details>
          @endif
        </div>
      @empty
        <div class="text-xs text-gray-500">Walang ad account na nabasa.</div>
      @endforelse
    </div>
  @endif

  {{-- Mga huling API run at event --}}
  @if ($meta['logs']->isNotEmpty())
    <details class="text-xs border-t pt-3">
      <summary class="cursor-pointer">API runs and changes (latest 20)</summary>
      <div class="overflow-auto">
        <table class="min-w-full mt-2">
          <tbody>
            @foreach ($meta['logs'] as $l)
              <tr class="border-t">
                <td class="p-1 font-mono">#{{ $l->id }}</td>
                <td class="p-1">{{ $l->original_name }}</td>
                <td class="p-1">{{ $l->type === 'ads_manager_api' ? strtoupper($l->status) . ' · +' . $l->inserted . ' / ~' . $l->updated . ' / skip ' . $l->skipped : '' }}</td>
                <td class="p-1 text-red-700">{{ $l->error_message }}
                  @if (!empty($meta['refused'][$l->id]))
                    @include('ads_manager._refused', ['ref' => $meta['refused'][$l->id]])
                  @endif
                </td>
                <td class="p-1">{{ $l->who }}</td>
                <td class="p-1 font-mono">{{ $l->created_at?->timezone('Asia/Manila')->format('M j, g:i A') }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </details>
  @endif
</div>
