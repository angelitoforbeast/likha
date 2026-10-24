{{-- "Needs setup": ang mga page na hindi napapadalhan dahil sa setup (ChatblastNeedsSetup). Wala kapag walang naharang. --}}
@if(!empty($needsSetup))
    @php($pages = \App\Services\ChatblastNeedsSetup::pageCount($needsSetup))
    <section id="needs-setup" class="mb-3 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert" aria-labelledby="needs-setup-title">
        @if(!empty($collapsible))
        {{-- Mahaba ang listahan: nakatago muna; tanda ng browser ang pinili. Bubukas ulit nang isang beses kapag dumami ang page mula sa huling nakita. --}}
        <h2 id="needs-setup-title" class="font-semibold"><button id="needs-setup-toggle" type="button" aria-expanded="false" aria-controls="needs-setup-body" data-pages="{{ $pages }}" class="flex w-full items-center gap-1 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-red-400 rounded"><span id="needs-setup-chevron" aria-hidden="true" class="inline-block transition-transform">&#9656;</span>Needs setup ({{ $pages }} {{ $pages === 1 ? 'page' : 'pages' }})</button></h2>
        <div id="needs-setup-body" hidden class="mt-1">
        @else
        <h2 id="needs-setup-title" class="mb-1 font-semibold">Needs setup ({{ $pages }} {{ $pages === 1 ? 'page' : 'pages' }})</h2>
        <div>
        @endif
        <p class="mb-2 text-xs text-red-800">Chatblast messages of the last {{ \App\Services\ChatblastNeedsSetup::DAYS }} days that were not sent because of the page's setup. A line goes away by itself once the page sends again.</p>
        <ul class="space-y-1">
            @foreach($needsSetup as $row)
                <li class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                    <span class="font-medium break-words">{{ $row['page'] }}</span>
                    <span class="break-words">· {{ $row['group'] }}</span>
                    <span class="text-xs text-red-800">· {{ number_format($row['count']) }} unsent, latest {{ $row['latest'] }}</span>
                    <a href="{{ route('page-settings.index', ['q' => $row['page']]) }}" class="text-xs font-medium text-red-900 underline hover:text-red-700">Fix</a>
                </li>
            @endforeach
        </ul>
        </div>
    </section>
    @if(!empty($collapsible))
    <script>
        (() => {
            const btn = document.getElementById('needs-setup-toggle'), body = document.getElementById('needs-setup-body'), chev = document.getElementById('needs-setup-chevron');
            const KEY = 'chatblast.needsSetup', pages = Number(btn.dataset.pages);
            let saved = null;
            try { saved = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) {}
            const choice = !!(saved && saved.open);
            const save = (open) => { try { localStorage.setItem(KEY, JSON.stringify({open, seen: pages})); } catch (e) {} };
            const show = (open) => { body.hidden = !open; btn.setAttribute('aria-expanded', open ? 'true' : 'false'); chev.style.transform = open ? 'rotate(90deg)' : ''; };
            // Dumami ang page mula sa huling nakita: buksan ngayong beses lang; nananatili ang dating pinili.
            show(choice || (saved !== null && pages > Number(saved.seen || 0)));
            save(choice);
            btn.addEventListener('click', () => { const open = body.hidden; show(open); save(open); });
        })();
    </script>
    @endif
@endif
{{-- Hindi maabot ang customer (Facebook #551/#100: na-block, deactivated): impormasyon lang, hindi setup, kaya walang Fix. --}}
@if(!empty($unreachable))
    @php($unreachTotal = array_sum(array_column($unreachable, 'count')))
    <p id="unreachable-info" class="mb-3 text-xs text-gray-500">
        Hindi na maabot ang customer (na-block / deactivated), {{ number_format($unreachTotal) }} sa huling {{ \App\Services\ChatblastNeedsSetup::DAYS }} araw:
        @foreach(array_slice($unreachable, 0, 5) as $u){{ $u['page'] }} {{ number_format($u['count']) }}{{ !$loop->last ? ',' : '' }} @endforeach
        @if(count($unreachable) > 5)
            <details class="inline"><summary class="inline cursor-pointer underline">+{{ count($unreachable) - 5 }} pa</summary>
                @foreach(array_slice($unreachable, 5) as $u){{ $u['page'] }} {{ number_format($u['count']) }}{{ !$loop->last ? ',' : '' }} @endforeach
            </details>
        @endif
    </p>
@endif
