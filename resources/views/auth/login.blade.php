<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Log in') }} - {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 font-sans text-gray-900 antialiased">
<div class="flex min-h-screen items-center justify-center px-4">
    <div class="w-full max-w-md rounded-xl border border-gray-200 bg-white p-8 shadow-sm">
        <h1 class="mb-6 text-center text-2xl font-bold text-gray-800">{{ config('app.name') }}</h1>

        <form method="POST" action="{{ route('login') }}" x-data="{ busy: false }" @submit="busy = true">
            @csrf

            <div class="mb-4">
                <label for="email" class="mb-2 block text-sm font-medium text-gray-700">{{ __('Email') }}</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                    class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-green-800 focus:outline-none"
                    placeholder="your@email.com">
                @error('email')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-4">
                <label for="password" class="mb-2 block text-sm font-medium text-gray-700">{{ __('Password') }}</label>
                <input id="password" type="password" name="password" required autocomplete="current-password"
                    class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-green-800 focus:outline-none">
                @error('password')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <label class="mb-6 flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" name="remember" class="rounded border-gray-300 text-green-900">
                {{ __('Remember me') }}
            </label>

            <button type="submit" :disabled="busy"
                class="w-full rounded-lg bg-green-900 px-6 py-2.5 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-60">
                {{ __('Log in') }}
            </button>
        </form>
    </div>
</div>
</body>
</html>
