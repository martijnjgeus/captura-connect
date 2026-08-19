<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Integration logs' }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-100 text-gray-900 antialiased dark:bg-gray-950 dark:text-gray-100">
<div class="min-h-screen">
    <header class="border-b border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
            <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">
                {{ $heading ?? 'Integration logs' }}
            </h1>

            @auth
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button
                        type="submit"
                        class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:ring-1 dark:ring-gray-700 dark:hover:bg-gray-700"
                    >
                        Log out
                    </button>
                </form>
            @endauth
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        @yield('content')
    </main>
</div>
</body>
</html>
