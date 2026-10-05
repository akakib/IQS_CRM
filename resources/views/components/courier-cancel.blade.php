{{-- The order was cancelled after the courier was booked: the booking still
     stands at the courier. Someone deletes it in the courier's panel by hand,
     then presses the button. A packed box also has to be opened and put back.
     <x-courier-cancel :order="$order" /> --}}
@props(['order'])

@php
    $pending = app(\App\Services\Orders\OrderService::class)->pendingCourierCancel($order);
@endphp

@if ($pending)
    <div {{ $attributes->merge(['class' => 'mb-3 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900']) }}>
        <p class="font-semibold">{{ __('Delete this parcel at :c by hand', ['c' => $pending['courier']]) }}</p>
        <p class="mt-0.5">{{ __('The order was cancelled after booking. Open the :c panel, find CN :cn and delete or cancel it, so no rider picks it up. Then press the button.', ['c' => $pending['courier'], 'cn' => $pending['cn'] ?? '-']) }}</p>
        @if ($pending['packed'])
            <p class="mt-1 font-medium">{{ __('It was already packed: open the box and put the items back on the shelf.') }}</p>
        @endif
        @can('orders.edit')
            <form method="POST" action="{{ route('orders.courier-cancelled', $order) }}" class="mt-2" id="courier-cancelled-{{ $order->id }}">
                @csrf
                <button type="button" class="lockable rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white hover:bg-red-700"
                    @click="$dispatch('open-confirm', { id: 'courier-cancelled-confirm-{{ $order->id }}', form: 'courier-cancelled-{{ $order->id }}', label: @js($order->order_no),
                        verb: @js(__('Yes, deleted')), message: @js(__('You deleted CN :cn in the :c panel?', ['cn' => $pending['cn'] ?? '-', 'c' => $pending['courier']])), danger: false })">
                    {{ __('Deleted at :c', ['c' => $pending['courier']]) }}
                </button>
            </form>
            <x-confirm-modal :id="'courier-cancelled-confirm-'.$order->id" :verb="__('Yes, deleted')" :danger="false" />
        @endcan
    </div>
@endif
