{{-- "Needs setup": ang mga page na hindi napapadalhan dahil sa setup (ChatblastNeedsSetup). Wala kapag walang naharang. --}}
@if(!empty($needsSetup))
    @php($pages = \App\Services\ChatblastNeedsSetup::pageCount($needsSetup))
    <section id="needs-setup" class="mb-3 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert" aria-labelledby="needs-setup-title">
        <h2 id="needs-setup-title" class="mb-1 font-semibold">Needs setup ({{ $pages }} {{ $pages === 1 ? 'page' : 'pages' }})</h2>
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
    </section>
@endif
