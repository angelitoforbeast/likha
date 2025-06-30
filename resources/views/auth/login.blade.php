<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-100 to-blue-100 min-h-screen flex items-center justify-center">
    <div class="bg-white p-8 rounded-lg shadow-lg w-full max-w-md">
        <h2 class="text-3xl font-bold text-center mb-6 text-blue-600">Welcome Back</h2>

        @if (session('success'))
            <div class="mb-4 text-green-600 text-sm text-center">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 text-red-600 text-sm">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="space-y-4">
            @csrf

            <div>
                <label class="block text-sm font-medium">Email</label>
                <input type="email" name="email" class="w-full border rounded px-3 py-2 mt-1" required autofocus>
            </div>

            <div>
                <label class="block text-sm font-medium">Password</label>
                <input type="password" name="password" class="w-full border rounded px-3 py-2 mt-1" required>
            </div>

            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 rounded transition">
                Login
            </button>
        </form>

        <p class="mt-4 text-center text-sm">
            Don't have an account?
            <a href="{{ route('login') }}" class="text-blue-600 hover:underline">Register here</a>
        </p>
    </div>
</body>
</html>