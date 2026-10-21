{{-- Kung paano sumasagot ang bot: ang On/Off ng page at ang flow nito. Ang CHATBOT_REPLIES_ENABLED ay emergency stop lang. --}}
@if($repliesOn)
    <p class="mb-4 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700" role="status">On + a flow = the bot answers. On without a flow = chats are only received. Off = chats are not kept.@unless(request()->is('chatbot/pages')) Switch pages and pick flows in <a href="{{ route('chatbot.pages.index') }}" class="text-blue-700 hover:underline">Page settings</a>.@endunless</p>
@else
    <p class="mb-4 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="status">Emergency stop is on: flows still run, but nothing is sent to customers or written to sheets (logged as skipped). The server setting CHATBOT_REPLIES_ENABLED turns replies back on.</p>
@endif
