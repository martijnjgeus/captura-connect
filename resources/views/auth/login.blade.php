@extends('layouts.app', [
    'title' => 'Login',
    'heading' => 'Login',
])

@section('content')
    <div class="mx-auto max-w-md rounded-lg bg-white p-6 shadow dark:bg-gray-900 dark:ring-1 dark:ring-gray-800">
        @if ($errors->any())
            <div class="mb-4 rounded-md bg-red-50 p-4 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300 dark:ring-1 dark:ring-red-400/30">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="post" action="{{ route('login') }}" class="space-y-5">
            @csrf

            <div>
                <label for="email">Email</label>

                <input
                    id="email"
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    required
                    autofocus
                    class="mt-1"
                >
            </div>

            <div>
                <label for="password">Password</label>

                <input
                    id="password"
                    type="password"
                    name="password"
                    required
                    class="mt-1"
                >
            </div>

            <button
                type="submit"
                class="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 dark:bg-blue-600 dark:hover:bg-blue-500"
            >
                Log in
            </button>
        </form>
    </div>
@endsection
