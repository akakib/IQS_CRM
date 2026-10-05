<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>
    <x-theme-head />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 font-sans text-gray-900 antialiased" x-data="{ sidebarOpen: false }">

<div class="flex min-h-screen">
    <div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false" class="fixed inset-0 z-40 bg-black/50 lg:hidden"></div>

    <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        class="fixed left-0 top-0 z-50 flex h-screen w-[260px] flex-col border-r border-gray-200 bg-white transition-transform duration-200 lg:translate-x-0">
        <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
            <a href="{{ route('dashboard') }}" class="text-xl font-bold text-gray-800">{{ config('app.name') }}</a>
            <button type="button" @click="sidebarOpen = false" class="text-gray-500 hover:text-gray-700 lg:hidden" aria-label="{{ __('Close menu') }}">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <x-sidebar-nav />
    </aside>

    <div class="flex min-w-0 flex-1 flex-col lg:ml-[260px]">
        <header class="sticky top-0 z-30 flex items-center justify-between border-b border-gray-200 bg-white px-4 py-3">
            <div class="flex min-w-0 items-center gap-3">
                <button type="button" @click="sidebarOpen = true"
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-100 lg:hidden" aria-label="{{ __('Open menu') }}">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <h1 class="truncate text-base font-semibold text-gray-800">{{ $heading ?? '' }}</h1>
            </div>

            <div class="flex shrink-0 items-center gap-2 sm:gap-3">
            <x-break-control part="button" />
            <x-voice-alerts />
            <x-theme-toggle />
            <x-notification-bell />
            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                <button type="button" @click="open = !open" class="flex items-center gap-2 text-gray-700">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-primary text-sm font-semibold text-white">
                        {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
                    </span>
                    <span class="hidden text-sm font-medium sm:block">{{ auth()->user()->name }}</span>
                </button>
                <div x-show="open" x-cloak class="absolute right-0 mt-2 w-56 rounded-xl border border-gray-200 bg-white p-2 shadow-lg">
                    <div class="mb-2 border-b border-gray-100 px-3 py-2">
                        <p class="text-sm font-medium text-gray-800">{{ auth()->user()->name }}</p>
                        <p class="truncate text-xs text-gray-500">{{ auth()->user()->email }}</p>
                    </div>
                    <a href="{{ route('profile.edit') }}" class="block rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-100">{{ __('My profile') }}</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full rounded-lg px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50">{{ __('Log out') }}</button>
                    </form>
                </div>
            </div>
            </div>
        </header>

        <main class="flex-1 p-4 md:p-6">
            <x-toaster />
            {{ $slot }}
        </main>
    </div>
</div>
<x-break-control part="screen" />
</body>
</html>
