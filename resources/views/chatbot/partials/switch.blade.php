{{-- Ang lagay ng global switch (CHATBOT_REPLIES_ENABLED): sa Flow, Replies at Test. --}}
@if($repliesOn)
    <p class="mb-4 rounded-md border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-900" role="status">Replies are on: flows answer customers through Botcake and write sheet rows.</p>
@else
    <p class="mb-4 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="status">Replies are switched off: flows still run, but nothing is sent to customers or written to sheets (logged as skipped). The server setting CHATBOT_REPLIES_ENABLED turns them on.</p>
@endif
