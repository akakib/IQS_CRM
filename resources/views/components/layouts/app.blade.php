@php
    // A page that is not in the menu (an order, a customer, an Edit page...): on a phone and tablet the menu button
    // becomes Back, to the page you came from, or to its list when the page was opened from a link.
    // Tabs beside a menu page (Payments > COD, Products > Categories...) count as menu pages: they keep the menu button.
    $menuRoutes = [...collect(config('menu'))->flatMap(fn ($g) => array_column($g[1], 1))->all(),
        'cod.index', 'refunds.index', 'calls-report.index', 'riders-report.index', 'categories.index', 'products.import', 'settings.couriers', 'settings.google-drive', 'settings.tracking'];
    $routeName = request()->route()?->getName();
    $backUrl = null;
    if ($routeName && ! in_array($routeName, $menuRoutes, true)) {
        $parent = ['calls.work' => 'calls.mine', 'journey.show' => 'customers.index', 'journey.index' => 'customers.index', 'breaks.reasons' => 'desk.control', 'hold-limits.index' => 'desk.control'][$routeName] ?? null;
        for ($parts = explode('.', $routeName); ! $parent && count($parts) > 1; ) {
            array_pop($parts);
            $base = implode('.', $parts);
            $parent = collect([$base, $base.'.index'])->first(fn ($r) => in_array($r, $menuRoutes, true));
        }
        $backUrl = route($parent ?? 'dashboard');
    }
    // Phone and tablet: the bottom bar shows on the menu pages; a page with Back, or one that asks for none
    // (:nav="false": an order open on Order management, with its own buttons at the bottom), has no bar.
    $bottomNav = ! $backUrl && ($nav ?? true);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>
    <x-theme-head />
    {{-- Phone and tablet: the menu rests off the right edge and is not drawn at all until it is opened. Written here,
         in the page itself, so it holds from the first moment, before the stylesheet and the scripts arrive. --}}
    <style>
        @media (max-width: 1023.98px) {
            .app-sidebar { translate: 100% 0; visibility: hidden; transition: translate .2s ease, visibility 0s linear .2s; }
            .app-sidebar.is-open { translate: 0 0; visibility: visible; transition: translate .2s ease; }
        }
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 font-sans text-gray-900 antialiased" x-data="{
        sidebarOpen: false, more: false,
        {{-- Menu button: on a computer it folds the sidebar to icons (remembered); on a phone it opens the drawer. --}}
        menu() {
            if (window.innerWidth < 1024) { this.sidebarOpen = true; return }
            const mini = document.documentElement.classList.toggle('sidebar-mini');
            try { localStorage.setItem('iqs_sidebar_mini', mini ? '1' : '0') } catch (e) {}
        },
    }">

<div class="flex min-h-screen">
    <div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false" class="fixed inset-0 z-40 bg-black/50 lg:hidden"></div>

    {{-- A computer: the sidebar on the left. A phone and tablet: the menu slides in from the right (More in the bottom bar). --}}
    <aside :class="{ 'is-open': sidebarOpen }"
        class="app-sidebar fixed top-0 z-50 flex h-dvh w-[260px] flex-col border-gray-200 bg-white max-lg:right-0 max-lg:border-l lg:left-0 lg:border-r lg:transition-[width] lg:duration-200">
        <div class="mini-center flex items-center justify-between border-b border-gray-200 px-6 py-4">
            <a href="{{ route('dashboard') }}" class="text-xl font-bold text-gray-800"><span class="mini-hide">{{ config('app.name') }}</span><span class="mini-only">{{ \Illuminate\Support\Str::of(config('app.name'))->before(' ') }}</span></a>
            <button type="button" @click="sidebarOpen = false" class="text-gray-500 hover:text-gray-700 lg:hidden" aria-label="{{ __('Close menu') }}">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <x-sidebar-nav />
    </aside>

    <div class="app-main flex min-w-0 flex-1 flex-col transition-[margin] duration-200 lg:ml-[260px]">
        <header class="sticky top-0 z-30 flex items-center justify-between gap-2 border-b border-gray-200 bg-white px-3 py-3 sm:px-4">
            <div class="flex min-w-0 items-center gap-2 sm:gap-3">
                @if ($backUrl)
                    <a href="{{ $backUrl }}" @click="if (document.referrer.startsWith(location.origin + '/') && history.length > 1) { $event.preventDefault(); history.back() }"
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-100 lg:hidden" aria-label="{{ __('Back') }}" title="{{ __('Back') }}">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    </a>
                @endif
                <button type="button" @click="menu()"
                    class="max-lg:hidden flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-100" aria-label="{{ __('Menu') }}" title="{{ __('Show or hide the menu') }}">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <h1 class="truncate text-base font-semibold text-gray-800">{{ $heading ?? '' }}</h1>
            </div>

            <div class="flex shrink-0 items-center gap-1.5 sm:gap-3">
            {{-- The one search, from every page: order no, phone, CN or name. --}}
            <form method="GET" action="{{ route('orders.search') }}" class="hidden md:block" role="search">
                <label class="relative block">
                    <svg class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/></svg>
                    <input type="search" name="q" value="{{ request()->routeIs('orders.search') ? request('q') : '' }}" placeholder="{{ __('Search order, phone, CN, name') }}" autocomplete="off"
                        class="w-56 rounded-lg border border-gray-200 bg-gray-50 py-2 pl-8 pr-3 text-sm focus:w-72 focus:border-primary focus:bg-white focus:outline-none lg:w-64">
                </label>
            </form>
            <a href="{{ route('orders.search') }}" class="hidden h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-100" aria-label="{{ __('Search') }}" title="{{ __('Search') }}">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/></svg>
            </a>
            <x-chat-control />
            <x-new-order-popup />
            <x-break-control part="button" />
            <x-voice-alerts />
            <div class="hidden sm:block"><x-theme-toggle /></div>
            <x-notification-bell />
            {{-- Phone and tablet: everything but the bell lives behind three dots. --}}
            <button type="button" @click="more = true" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-100 lg:hidden" aria-label="{{ __('More') }}">
                <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
            </button>
            <div class="relative max-lg:hidden" x-data="{ open: false }" @click.outside="open = false">
                <button type="button" @click="open = !open" class="flex shrink-0 items-center gap-2 text-gray-700" aria-label="{{ __('My profile') }}">
                    <x-avatar :name="auth()->user()->name" :photo="auth()->user()->photo_path" size="md" />
                    <span class="hidden text-sm font-medium sm:block">{{ auth()->user()->name }}</span>
                </button>
                <div x-show="open" x-cloak class="absolute right-0 mt-2 w-56 rounded-xl border border-gray-200 bg-white p-2 shadow-lg">
                    <div class="mb-2 border-b border-gray-100 px-3 py-2">
                        <p class="text-sm font-medium text-gray-800">{{ auth()->user()->name }}</p>
                        <p class="truncate text-xs text-gray-500">{{ auth()->user()->email }}</p>
                    </div>
                    <a href="{{ route('profile.edit') }}" class="block rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-100">{{ __('My profile') }}</a>
                    {{-- Phone: dark mode lives here (the top bar keeps only what is used all day). --}}
                    <button type="button" x-data="{ dark: document.documentElement.classList.contains('dark') }"
                        @click="dark = !dark; document.documentElement.classList.toggle('dark', dark); try { localStorage.setItem('iqs_theme', dark ? 'dark' : 'light') } catch (e) {}"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 sm:hidden">
                        <span>{{ __('Dark mode') }}</span><span class="text-xs text-gray-500" x-text="dark ? @js(__('On')) : @js(__('Off'))"></span>
                    </button>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full rounded-lg px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50">{{ __('Log out') }}</button>
                    </form>
                </div>
            </div>
            </div>
        </header>

        <main @class(['flex-1 p-4 md:p-6', 'max-lg:pb-24' => $bottomNav])>
            <x-toaster />
            <x-call-player />
            <x-call-live-bar />
            {{ $slot }}
        </main>
    </div>
</div>
@if ($bottomNav)
    <x-bottom-nav />
@endif

{{-- Phone and tablet: the three-dot menu, a sheet from the bottom like the app's other popups. --}}
<div x-show="more" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center lg:hidden" @keydown.escape.window="more = false">
    <div class="absolute inset-0 bg-black/40" @click="more = false"></div>
    <div class="relative max-h-[90dvh] w-full max-w-md overflow-y-auto rounded-t-2xl bg-white p-3 pb-[max(1rem,env(safe-area-inset-bottom))] shadow-xl">
        <div class="mx-auto mb-2 h-1 w-10 rounded-full bg-gray-300"></div>
        <a href="{{ route('profile.edit') }}" class="flex items-center gap-3 rounded-xl px-3 py-2.5 hover:bg-gray-50">
            <x-avatar :name="auth()->user()->name" :photo="auth()->user()->photo_path" size="md" />
            <span class="min-w-0"><span class="block truncate font-semibold text-gray-900">{{ auth()->user()->name }}</span><span class="block truncate text-xs text-gray-500">{{ __('My profile') }}</span></span>
        </a>
        <div class="my-1.5 border-t border-gray-100"></div>
        <a href="{{ route('orders.search') }}" class="flex items-center gap-3.5 rounded-xl px-3 py-3 text-[15px] text-gray-900 hover:bg-gray-50">
            <svg class="h-5 w-5 shrink-0 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/></svg>
            <span>{{ __('Search an order') }}<span class="block text-xs text-gray-500">{{ __('By phone, CN or name, in every order') }}</span></span>
        </a>
        @unless (auth()->user()->current_break_id)
            <button type="button" @click="more = false; window.dispatchEvent(new CustomEvent('break-open'))" class="flex w-full items-center gap-3.5 rounded-xl px-3 py-3 text-left text-[15px] text-gray-900 hover:bg-gray-50">
                <svg class="h-5 w-5 shrink-0 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span>{{ __('Take a break') }}</span>
            </button>
        @endunless
        <button type="button" x-data="{ dark: document.documentElement.classList.contains('dark') }"
            @click="dark = !dark; document.documentElement.classList.toggle('dark', dark); try { localStorage.setItem('iqs_theme', dark ? 'dark' : 'light') } catch (e) {}"
            class="flex w-full items-center gap-3.5 rounded-xl px-3 py-3 text-left text-[15px] text-gray-900 hover:bg-gray-50">
            <svg class="h-5 w-5 shrink-0 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg>
            <span class="flex-1">{{ __('Dark mode') }}</span><span class="text-xs text-gray-500" x-text="dark ? @js(__('On')) : @js(__('Off'))"></span>
        </button>
        <div class="my-1.5 border-t border-gray-100"></div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="flex w-full items-center gap-3.5 rounded-xl px-3 py-3 text-left text-[15px] text-red-600 hover:bg-red-50">
                <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17l5-5-5-5M20 12H9M12 20H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h6"/></svg>
                {{ __('Log out') }}
            </button>
        </form>
    </div>
</div>
<x-break-control part="screen" />
</body>
</html>
