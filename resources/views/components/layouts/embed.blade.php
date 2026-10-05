{{-- A page shown inside a popup on another page (no sidebar, no header). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $heading ?? config('app.name') }}</title>
    <x-theme-head />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- x-data: popups teleported to the body (e.g. Edit order) need an Alpine root here too. --}}
<body class="bg-gray-50 font-sans text-gray-900 antialiased" x-data>
    <main class="p-4 sm:p-6">{{ $slot }}</main>
    <x-toaster />
</body>
</html>
