{{-- Public Privacy Policy para sa Meta app (spec 282). Walang login. Ang Facebook connection lang ang inilalarawan. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy Policy - {{ $company }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-100 to-blue-100 min-h-screen py-10 px-4">
    <main class="bg-white p-8 rounded-lg shadow-lg w-full max-w-3xl mx-auto space-y-5 text-gray-800">
        <h1 class="text-3xl font-bold text-blue-600">Privacy Policy</h1>
        <p><strong>{{ $company }}</strong> uses this site for its internal work. This policy covers what happens when a staff
            member connects their Facebook account to the site ("Connect Facebook") so the company can read its own Facebook ads reports.</p>

        <section class="space-y-2">
            <h2 class="text-xl font-semibold">What we read and keep</h2>
            <ul class="list-disc list-inside space-y-1">
                <li>Your Facebook name and your app-scoped Facebook ID.</li>
                <li>The list of ad accounts you can access (their IDs, names and status).</li>
                <li>Ads reports and insights of those ad accounts (spend, reach, impressions, results and similar numbers).</li>
                <li>An access token from Facebook, stored encrypted, so the site can keep reading those reports.</li>
            </ul>
            <p>We do not read your posts, messages, friends, photos or any other part of your Facebook profile.</p>
        </section>

        <section class="space-y-2">
            <h2 class="text-xl font-semibold">Why</h2>
            <p>Only for internal ads reporting: comparing ad spend and results with the company's orders. We do not sell or share this data, and we do not use it for advertising.</p>
        </section>

        <section class="space-y-2">
            <h2 class="text-xl font-semibold">Who sees it</h2>
            <p>Only the staff of {{ $company }} who use this site. The access token is never shown to anyone.</p>
        </section>

        <section class="space-y-2">
            <h2 class="text-xl font-semibold">How long we keep it</h2>
            <p>Until you disconnect or ask for deletion. Then your Facebook name, ID, ad account list and token are deleted.
                Ads reports already pulled stay as company records; they hold ad numbers only, no personal data.</p>
        </section>

        <section class="space-y-2">
            <h2 class="text-xl font-semibold">How to delete your data</h2>
            <ul class="list-disc list-inside space-y-1">
                <li>Click <strong>Disconnect my Facebook</strong> on the site, or</li>
                <li>Remove the app in your Facebook settings (Settings &amp; privacy &rarr; Settings &rarr; Business integrations / Apps and websites). Facebook tells us and we delete your data automatically, or</li>
                @if ($email)
                    <li>Email us at <a class="text-blue-600 underline" href="mailto:{{ $email }}">{{ $email }}</a>.</li>
                @else
                    <li>Ask the staff of {{ $company }}.</li>
                @endif
            </ul>
        </section>

        <section class="space-y-2">
            <h2 class="text-xl font-semibold">Contact</h2>
            <p>{{ $company }}</p>
            @if ($email)
                <p>Email: <a class="text-blue-600 underline" href="mailto:{{ $email }}">{{ $email }}</a></p>
            @endif
        </section>
    </main>
</body>
</html>
