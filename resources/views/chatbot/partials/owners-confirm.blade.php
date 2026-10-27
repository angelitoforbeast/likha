{{-- Ang malakas na confirm ng take, move, ask at accept: ang sariling password ng CEO at ang phrase na nasa screen. --}}
<details class="w-full min-w-0 sm:w-auto" data-action="{{ $action }}" data-target="{{ $target }}">
    <summary class="cb-btn cb-btn-sm cursor-pointer list-none {{ $action === 'take' || $action === 'accept' ? 'cb-btn-primary' : '' }}">{{ $button }}</summary>
    <form method="POST" action="{{ url('/chatbot/owners/' . $action) }}" class="mt-2 w-full space-y-2 rounded-md border border-slate-200 bg-white p-3 text-sm sm:w-80">
        @csrf
        <input type="hidden" name="target" value="{{ $target }}">
        <p class="text-slate-600">{{ $note }}</p>
        <label class="block">
            <span class="text-slate-700">Type <code class="break-all rounded bg-slate-100 px-1 font-mono text-xs text-slate-900">{{ $phrase }}</code></span>
            <input name="phrase" type="text" required autocomplete="off" spellcheck="false" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 font-mono text-sm">
        </label>
        <label class="block">
            <span class="text-slate-700">Your password</span>
            <input name="password" type="password" required autocomplete="current-password" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm">
        </label>
        <button class="cb-btn cb-btn-primary w-full justify-center">{{ $button }}</button>
    </form>
</details>
