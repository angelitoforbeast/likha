<x-layout>
  <x-slot name="title">New Problem · Playbook</x-slot>
  <x-slot name="heading">New Problem &amp; Solution</x-slot>

  <div class="max-w-3xl mx-auto p-4">
    <div class="mb-3"><a href="{{ route('playbook.index') }}" class="text-sm text-indigo-600 hover:underline">← Back to Playbook</a></div>

    @if ($errors->any())
      <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-800 text-sm">
        <ul class="list-disc list-inside">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
      </div>
    @endif

    <form method="POST" action="{{ route('playbook.store') }}" enctype="multipart/form-data"
          x-data="pbForm([])" @paste.window="paste($event)"
          class="rounded-xl border border-slate-200 p-4">
      @csrf
      @include('playbook._form', ['p' => null, 'attachments' => []])
      <div class="flex justify-end gap-2 mt-2">
        <a href="{{ route('playbook.index') }}" class="rounded-lg px-4 py-2 text-sm text-slate-600 hover:bg-slate-100">Cancel</a>
        <button class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Save problem</button>
      </div>
    </form>
  </div>

  @include('playbook._script')
</x-layout>
