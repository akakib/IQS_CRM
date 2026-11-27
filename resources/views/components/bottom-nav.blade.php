{{-- Phone and tablet: the app's bottom bar (the sidebar is for a computer). Up to four places this person works in,
     by what they may open, then More, which opens the whole menu from the right. Shown on the menu pages only;
     a page with Back at the top (an order, an Edit page) has none, like the detail screens of an app.
     <x-bottom-nav /> --}}
@php
    $user = auth()->user();
    $can = fn (?string $p) => $p === null || $user->can($p);
    $icons = [
        'home' => 'M3 11.5 12 4l9 7.5M5.5 10v9a1 1 0 0 0 1 1H10v-5h4v5h3.5a1 1 0 0 0 1-1v-9',
        'orders' => 'M6 8h12l1 12H5L6 8Zm3 0V7a3 3 0 0 1 6 0v1',
        'box' => 'M4 8h16v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V8Zm-1-4h18v4H3V4Zm6 8h6',
        'chat' => 'M8 10h.01M12 10h.01M16 10h.01M21 12a8 8 0 0 1-11.6 7.2L4 20l1-4.5A8 8 0 1 1 21 12Z',
        'tag' => 'M3 12V4h8l9.5 9.5a1.5 1.5 0 0 1 0 2.1l-5.9 5.9a1.5 1.5 0 0 1-2.1 0L3 12Zm4.5-4.5h.01',
        'users' => 'M16 19v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 17.5V19m16 0v-1.5a3.5 3.5 0 0 0-2.5-3.35M14.5 4.2a3.5 3.5 0 0 1 0 6.6M13.5 7.5a3.5 3.5 0 1 1-7 0 3.5 3.5 0 0 1 7 0Z',
        'more' => 'M4 7h16M4 12h16M4 17h16',
    ];
    // In the order they are offered: the first four this person may open are shown.
    $places = collect([
        ['Home', route('dashboard'), request()->routeIs('dashboard'), null, 'home'],
        ['Orders', route('desk.index'), request()->routeIs('desk.index'), 'orders.edit', 'orders'],
        ['Packaging', \Illuminate\Support\Facades\Route::has('packaging.index') ? route('packaging.index') : null, request()->routeIs('packaging.*'), 'packaging.view', 'box'],
        ['Chats', '#chat', false, app()->bound('nav.chat') ? null : 'never', 'chat'],
        ['Products', route('products.index'), request()->routeIs('products.*', 'categories.*'), 'products.view', 'tag'],
        ['Customers', route('customers.index'), request()->routeIs('customers.*'), 'customers.view', 'users'],
    ])->filter(fn ($p) => $p[1] && $p[3] !== 'never' && $can($p[3]))->take(4)->values();
    // Orders in their hand to work now (new and record verified): one indexed count, only for who takes orders.
    // (Order management passes its own number in $navInHand.)
    $inHand = isset($navInHand) ? (int) $navInHand : ($user->can('orders.take') && ! $user->isOwner()
        ? \Illuminate\Support\Facades\DB::table('orders')->where('moderator_id', $user->id)->whereIn('status_id', \App\Models\OrderStatus::idsFor(['new', 'record_verified']))->count() : 0);
    $onPlace = $places->contains(fn ($p) => $p[2]);
@endphp

<nav class="app-bottom-nav fixed inset-x-0 bottom-0 z-30 grid border-t border-gray-200 bg-white px-1 pb-[max(0.25rem,env(safe-area-inset-bottom))] pt-1 lg:hidden"
    style="grid-template-columns: repeat({{ $places->count() + 1 }}, minmax(0, 1fr))" aria-label="{{ __('Main') }}">
    @foreach ($places as [$label, $url, $active, $perm, $icon])
        <a href="{{ $url }}" @if ($url === '#chat') @click.prevent="window.dispatchEvent(new CustomEvent('chat-open'))" @endif @if ($active) aria-current="page" @endif
            @class(['relative flex flex-col items-center gap-0.5 rounded-lg py-1.5 text-[11px]', 'font-semibold text-primary' => $active, 'text-gray-500' => ! $active])>
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons[$icon] }}"/></svg>
            {{ __($label) }}
            @if ($icon === 'orders' && $inHand)
                <span class="absolute left-1/2 top-0.5 ml-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-semibold text-white">{{ $inHand > 99 ? '99+' : $inHand }}</span>
            @endif
        </a>
    @endforeach
    <button type="button" @click="sidebarOpen = true" @class(['flex flex-col items-center gap-0.5 rounded-lg py-1.5 text-[11px]', 'font-semibold text-primary' => ! $onPlace, 'text-gray-500' => $onPlace])>
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" d="{{ $icons['more'] }}"/></svg>
        {{ __('More') }}
    </button>
</nav>
