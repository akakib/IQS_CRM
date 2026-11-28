{{-- An item of this order is out of stock or pre-order here (the website still took it). Orange, not red: the
     order goes through Verify and Call as usual, but it cannot be confirmed (go to packaging) until the item is
     changed (Edit order), or an admin allows it. The moderator may also hold it (no date needed for stock). The box
     only explains and asks the admin: Edit order, Hold and Cancel are the order's own buttons (one job, one button).
     edit: show the Edit order button here (the Desk moves it into this box, the fix for a missing item).
     <x-stock-issue :order="$order" :edit="true" /> --}}
@props(['order', 'short' => null, 'edit' => false])

@php
    $key = \App\Models\OrderStatus::map()[$order->status_id]['key'] ?? '';
    // The page may pass what it already read (one query less); else read here.
    $short ??= in_array($key, ['new', 'record_verified', 'no_answer', 'hold'], true) ? app(\App\Services\Orders\OrderService::class)->stockIssues($order) : [];
    $isAdmin = auth()->user()?->can('orders.approve');
@endphp

@if ($short)
    <div x-data="{ ask: false }" {{ $attributes->merge(['class' => 'mb-3 rounded-xl border border-orange-300 bg-orange-50 px-4 py-3 text-sm text-orange-900']) }}>
        @php
            // The photos of the short items (one query, only when something is short).
            $shortImg = \Illuminate\Support\Facades\DB::table('order_items as i')->join('product_variants as v', 'v.id', '=', 'i.variant_id')->leftJoin('products as p', 'p.id', '=', 'v.product_id')
                ->where('i.order_id', $order->id)->whereIn('i.name_snapshot', array_keys($short))->pluck('p.image_url', 'i.name_snapshot');
        @endphp
        <p><b class="font-semibold">{{ in_array('out_of_stock', $short, true) ? __('Out of stock:') : __('Pre-order:') }}</b></p>
        <div class="mt-1 flex flex-wrap gap-1.5">
            @foreach ($short as $n => $s)
                <span class="inline-flex max-w-full items-center gap-1.5 rounded-lg border border-orange-200 bg-white py-0.5 pl-0.5 pr-2 text-xs"><x-product-thumb :src="$shortImg[$n] ?? null" size="sm" /><span class="min-w-0 truncate">{{ $n }}{{ $s === 'backorder' ? ' ('.__('pre-order').')' : '' }}</span></span>
            @endforeach
        </div>
        @if ($order->stock_waived_at)
            <p class="mt-1 font-medium">{{ __('An admin allowed it without stock: it may be confirmed.') }}</p>
        @else
            @if ($order->stock_waiver_requested_at)
                <p class="mt-2 rounded-lg bg-white px-3 py-2">{{ __('Asked an admin to send it without stock: ":w"', ['w' => $order->stock_waiver_note]) }} · {{ $order->stock_waiver_requested_at->diffForHumans() }}</p>
            @endif
            <div class="mt-2 flex flex-wrap gap-2">
                @if ($edit)
                    <button type="button" @click="$dispatch('open-order-edit')" class="lockable inline-flex items-center gap-1 rounded-lg border border-orange-400 bg-white px-3 py-2 text-xs font-semibold text-orange-900 ring-2 ring-orange-200 hover:bg-orange-100">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.86 4.49l2.65 2.65M4 20l1-4L16.5 4.5a1.87 1.87 0 012.65 2.65L7.65 18.65 4 20z"/></svg>
                        {{ __('Edit order') }}
                    </button>
                @endif
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
