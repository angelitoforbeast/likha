{{-- Status ng Facebook data deletion (spec 282). Public; petsa lang, walang personal na data. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Data deletion status - {{ $company }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-100 to-blue-100 min-h-screen flex items-center justify-center px-4">
    <main class="bg-white p-8 rounded-lg shadow-lg w-full max-w-md text-center space-y-3">
        <h1 class="text-2xl font-bold text-blue-600">Data deletion status</h1>
        @if ($found)
            <p>Your Facebook data was deleted on {{ \Carbon\Carbon::parse($found['deleted_at'])->timezone(config('app.timezone'))->format('F j, Y') }}.</p>
            @if ($found['count'] === 0)
                <p class="text-sm text-gray-600">Nothing was stored for this Facebook account.</p>
            @endif
        @else
            <p>Not found</p>
        @endif
    </main>
</body>
</html>
