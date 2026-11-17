{{-- Facebook (Meta Ads API): connect, Sync now, write mode. Walang token dito, kahit kailan.
     Compare with uploads is hidden: the API sync is the main source now. Its route, job and data stay so it can come back. --}}
<div class="border rounded p-4 space-y-4" id="meta-ads-card">
  <div class="flex items-center justify-between flex-wrap gap-2">
    <h2 class="font-semibold">Facebook (Meta Ads API)</h2>
    <a href="{{ route('ads_manager.meta.ad_accounts') }}" id="ad-accounts-link" class="text-sm text-blue-700 underline">Ad Accounts: balance, spend cap, payment</a>
    <span class="text-xs px-2 py-0.5 rounded {{ $meta['write_mode'] ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700' }}">
      Write mode: {{ $meta['write_mode'] ? 'ON' : 'OFF (no writes)' }}
    </span>
  </div>

  @error('meta')
    <div class="rounded bg-red-100 text-red-800 px-3 py-2 text-sm">{{ $message }}</div>
  @enderror

  @if (!$meta['set_up'])
    <p class="text-sm text-gray-600">Meta app not set up yet. Kapag naka-set na ang app, dito magko-connect ng Facebook.@if ($meta['app']) Ilagay ang Meta app sa ibaba.@endif</p>
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

  {{-- Ang Meta app ng site (spec 281), CEO lang. Ang App Secret ay hindi kailanman ipinapakita: Set / Not set at Replace lang. --}}
  @if ($meta['app'])
    @php
      $app = $meta['app'];
      $appErrors = ['app_id', 'app_secret', 'config_id', 'meta_app'];
      $appOpen = !$meta['set_up'] || $errors->hasAny($appErrors) || session('meta_app_warning');
      $appSource = fn ($f) => ['saved' => 'saved here', 'env' => 'from the server file (.env)'][$app['sources'][$f] ?? ''] ?? 'none';
    @endphp
    <details id="meta-app-settings" class="border-t pt-3"{{ $appOpen ? ' open' : '' }}>
      <summary class="font-semibold text-sm cursor-pointer select-none">
        Meta app (CEO): {{ $app['app_id'] ? 'App ID ' . $app['app_id'] : 'not set' }}
        <span class="meta-app-caret inline-block transition-transform">▸</span>
      </summary>
      <style>#meta-app-settings[open] .meta-app-caret { transform: rotate(90deg); }</style>
      <div class="pt-2 space-y-3 text-sm">
        @if (session('meta_app_warning'))
          <div class="rounded bg-yellow-100 text-yellow-800 px-3 py-2">{{ session('meta_app_warning') }}</div>
        @endif
        @if ($errors->hasAny($appErrors))
          <div class="rounded bg-red-100 text-red-800 px-3 py-2">
            @foreach ($appErrors as $f)
              @foreach ($errors->get($f) as $m)<div>{{ $m }}</div>@endforeach
            @endforeach
          </div>
        @endif

        <p class="text-xs text-gray-500">Ilagay ang dalawang ito sa Meta app (developers.facebook.com): Facebook Login for Business → Settings, at App settings → Basic.</p>
        <div class="grid gap-2 sm:grid-cols-2">
          @foreach (['Redirect URI' => $app['redirect_uri'], 'App Domain' => $app['app_domain']] as $label => $val)
            <div class="text-xs min-w-0">{{ $label }}
              <div class="flex gap-2 items-center">
                <code class="font-mono border rounded p-1 bg-gray-50 break-all">{{ $val }}</code>
                <button type="button" data-copy="{{ $val }}" class="meta-app-copy bg-gray-200 hover:bg-gray-300 text-gray-800 text-xs px-2 py-1 rounded shrink-0">Copy</button>
              </div>
            </div>
          @endforeach
        </div>

        <form method="POST" action="{{ route('ads_manager.meta.app') }}" class="flex gap-3 flex-wrap items-end" autocomplete="off">
          @csrf
          <label class="text-xs">App ID
            <input type="text" name="app_id" value="{{ old('app_id', $app['app_id']) }}" inputmode="numeric" class="border rounded p-1 block w-48 font-mono">
            <span class="text-gray-500">{{ $appSource('app_id') }}</span>
          </label>
          <label class="text-xs">App Secret:
            @if ($app['secret_set'])
              <span class="px-1.5 rounded bg-green-100 text-green-800">Set</span>
            @else
              <span class="px-1.5 rounded bg-red-100 text-red-800">Not set</span>
            @endif
            <input type="password" name="app_secret" value="" placeholder="Replace: 32 hex characters" autocomplete="new-password" class="border rounded p-1 block w-64 font-mono">
            <span class="text-gray-500">{{ $app['secret_set'] ? 'Replace: iwanang blangko para hindi palitan' : 'Required' }} · {{ $appSource('app_secret') }}</span>
          </label>
          <label class="text-xs">Configuration ID (optional)
            <input type="text" name="config_id" value="{{ old('config_id', $app['config_id']) }}" inputmode="numeric" class="border rounded p-1 block w-48 font-mono">
            <span class="text-gray-500">{{ $appSource('config_id') }}</span>
          </label>
          <button class="bg-gray-800 hover:bg-gray-900 text-white text-sm px-3 py-1.5 rounded">Save Meta app</button>
        </form>
        <form method="POST" action="{{ route('ads_manager.meta.app.test') }}" class="flex gap-2 items-center flex-wrap">
          @csrf
          <button class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm px-3 py-1.5 rounded" onclick="this.disabled=true;this.form.submit();">Test</button>
          <span class="text-xs text-gray-500">Sinusubok ang naka-save na App ID at App Secret (humihingi ng app token sa Facebook).</span>
        </form>
        @if ($app['connections'] > 0)
          <p class="text-xs text-yellow-800">Kapag pinalitan ang App ID, ang {{ $app['connections'] }} naka-connect na Facebook ay ginawa sa lumang app: kailangan nilang mag-Connect ulit. Hindi sila binubura.</p>
        @endif
      </div>
    </details>
    <script>
      document.querySelectorAll('#meta-app-settings .meta-app-copy').forEach(function (b) {
        b.addEventListener('click', function () {
          navigator.clipboard.writeText(b.dataset.copy).then(function () {
            b.textContent = 'Copied';
            setTimeout(function () { b.textContent = 'Copy'; }, 1500);
          });
        });
      });
    </script>
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

  {{-- Mga ad account na binabasa ng Sync (CEO, Marketing, Marketing - OIC ang pumipili) --}}
  @if ($meta['accounts'])
    {{-- Collapsed to one line (spec 246); open again after a save that came back with an error, so the error is seen. --}}
    @php
      $accountsChecked = count(array_filter($meta['accounts'], fn ($a) => !empty($a['selected'])));
      $accountsOpen = session('meta_accounts_error') || $errors->has('accounts') || $errors->has('accounts.*');
    @endphp
    <details id="meta-accounts-section" class="border-t pt-3"{{ $accountsOpen ? ' open' : '' }}>
    <summary class="font-semibold text-sm cursor-pointer select-none">
      Ad accounts to sync ({{ $accountsChecked }} of {{ count($meta['accounts']) }} checked)
      <span class="meta-accounts-caret inline-block transition-transform">▸</span>
    </summary>
    <style>#meta-accounts-section[open] .meta-accounts-caret { transform: rotate(90deg); }</style>
    <form method="POST" action="{{ route('ads_manager.meta.accounts') }}" class="pt-2 space-y-2" id="meta-accounts">
      @csrf
      @if ($errors->has('accounts') || $errors->has('accounts.*'))
        <div class="rounded bg-red-100 text-red-800 px-3 py-2 text-sm">{{ $errors->first('accounts') ?: $errors->first('accounts.*') }}</div>
      @endif
      <p class="text-xs text-gray-500">
        Sync now at ang schedule: ang may check lang.
        Ang bagong account ay naka-check na; ang inalisan ng check ay hindi na babalik.
      </p>
      <div class="divide-y border rounded">
        @foreach ($meta['accounts'] as $a)
          <label class="flex items-start gap-3 p-2 text-sm {{ $meta['can_choose_accounts'] ? 'cursor-pointer' : '' }}">
            <input type="checkbox" name="accounts[]" value="{{ $a['id'] }}" @checked($a['selected']) @disabled(!$meta['can_choose_accounts']) class="mt-1 h-5 w-5 shrink-0">
            <span class="min-w-0">
              <span class="font-medium break-words">{{ $a['name'] !== '' ? $a['name'] : 'Ad account' }}</span>
              <span class="font-mono text-xs text-gray-500 break-all">{{ $a['id'] }}</span>
              <span class="block text-xs text-gray-500 break-words">Facebook: {{ implode(', ', $a['who']) }}</span>
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
    </details>
  @endif

  {{-- Sync now --}}
  <div class="flex gap-3 flex-wrap items-end">
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
          <option value="off" @selected(!$meta['write_mode'])>Off: no writes</option>
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
