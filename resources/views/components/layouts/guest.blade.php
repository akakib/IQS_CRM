<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? '' }} - {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 font-sans text-gray-900 antialiased">
<div class="flex min-h-screen items-center justify-center px-4">
    <div class="w-full max-w-md rounded-xl border border-gray-200 bg-white p-8 shadow-sm">
        <h1 class="mb-6 text-center text-2xl font-bold text-gray-800">{{ config('app.name') }}</h1>

        @if (session('status'))
            <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-800">{{ session('status') }}</div>
        @endif

        {{ $slot }}
    </div>
</div>
</body>
</html>
