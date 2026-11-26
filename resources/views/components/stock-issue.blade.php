{{-- An item of this order is out of stock or pre-order here (the website still took it). Orange, not red: the
     order goes through Verify and Call as usual, but it cannot be confirmed (go to packaging) until the item is
     swapped (Edit order), or an admin allows it. The moderator may also hold it (no date needed for stock).
     <x-stock-issue :order="$order" /> --}}
@props(['order'])

@php
    $key = \App\Models\OrderStatus::map()[$order->status_id]['key'] ?? '';
    $short = in_array($key, ['new', 'record_verified', 'no_answer', 'hold'], true) ? app(\App\Services\Orders\OrderService::class)->stockIssues($order) : [];
    $isAdmin = auth()->user()?->can('orders.approve');
@endphp

@if ($short)
    <div x-data="{ ask: false }" {{ $attributes->merge(['class' => 'mb-3 rounded-xl border border-orange-300 bg-orange-50 px-4 py-3 text-sm text-orange-900']) }}>
        <p class="font-semibold">{{ in_array('out_of_stock', $short, true) ? __('Out of stock in T5 CRM') : __('Pre-order item') }}</p>
        <p class="mt-0.5">{{ __('The website took this order, but T5 CRM has none of: :i.', ['i' => collect($short)->map(fn ($s, $n) => $n.($s === 'backorder' ? ' ('.__('pre-order').')' : ''))->join(', ')]) }}</p>
        @if ($order->stock_waived_at)
            <p class="mt-1 font-medium">{{ __('An admin allowed it without stock: it may be confirmed.') }}</p>
        @else
            <p class="mt-0.5">{{ __('Swap it with Edit order, hold the order, or cancel. To send it as it is, an admin has to allow it.') }}</p>
            @if ($order->stock_waiver_requested_at)
                <p class="mt-2 rounded-lg bg-white px-3 py-2">{{ __('Asked an admin to send it without stock: ":w"', ['w' => $order->stock_waiver_note]) }} · {{ $order->stock_waiver_requested_at->diffForHumans() }}</p>
            @endif
            <div class="mt-2 flex flex-wrap gap-2">
                @if ($isAdmin)
                    <form method="POST" action="{{ route('orders.stock-waiver.decide', $order) }}">@csrf<input type="hidden" name="decision" value="allow">
                        <button class="rounded-lg border border-orange-400 bg-white px-3 py-2 text-xs font-semibold text-orange-900 hover:bg-orange-100">{{ __('Allow without stock') }}</button></form>
                    @if ($order->stock_waiver_requested_at)
                        <form method="POST" action="{{ route('orders.stock-waiver.decide', $order) }}">@csrf<input type="hidden" name="decision" value="refuse">
                            <button class="rounded-lg border border-orange-400 bg-white px-3 py-2 text-xs font-semibold text-orange-900 hover:bg-orange-100">{{ __('Refuse, stock needed') }}</button></form>
                    @endif
                @elseif (! $order->stock_waiver_requested_at)
                    <button type="button" @click="ask = ! ask" class="rounded-lg border border-orange-400 bg-white px-3 py-2 text-xs font-semibold text-orange-900 hover:bg-orange-100">{{ __('Ask admin: send without stock') }}</button>
                @endif
            </div>
            @unless ($isAdmin)
                <form x-show="ask" x-cloak method="POST" action="{{ route('orders.stock-waiver', $order) }}" class="mt-2 space-y-2">
                    @csrf
                    <textarea name="why" required minlength="5" maxlength="300" rows="2" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-primary focus:outline-none"
                        placeholder="{{ __('Why send it without stock? e.g. the item is on its way today') }}"></textarea>
                    <x-button size="sm">{{ __('Send to admin') }}</x-button>
                </form>
            @endunless
        @endif
    </div>
@endif
