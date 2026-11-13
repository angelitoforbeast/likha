{{-- "Needs setup": ang mga page na hindi napapadalhan dahil sa setup (ChatblastNeedsSetup). Wala kapag walang naharang. --}}
@if(!empty($needsSetup))
    @php($pages = \App\Services\ChatblastNeedsSetup::pageCount($needsSetup))
    <section id="needs-setup" class="mb-3 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert" aria-labelledby="needs-setup-title">
        {{-- Spec 271 amendment 1: laging nakatago muna (isang linya na may bilang), sa /chatblast at sa page settings; bumubukas sa click. --}}
        <h2 id="needs-setup-title" class="font-semibold"><button id="needs-setup-toggle" type="button" aria-expanded="false" aria-controls="needs-setup-body" class="flex w-full items-center gap-1 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-red-400 rounded"><span id="needs-setup-chevron" aria-hidden="true" class="inline-block transition-transform">&#9656;</span>Needs setup ({{ $pages }} {{ $pages === 1 ? 'page' : 'pages' }})</button></h2>
        <div id="needs-setup-body" hidden class="mt-1">
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
    <script>
        (() => {
            const btn = document.getElementById('needs-setup-toggle'), body = document.getElementById('needs-setup-body'), chev = document.getElementById('needs-setup-chevron');
            btn.addEventListener('click', () => {
                const open = body.hidden;
                body.hidden = !open;
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                chev.style.transform = open ? 'rotate(90deg)' : '';
            });
        })();
    </script>
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
