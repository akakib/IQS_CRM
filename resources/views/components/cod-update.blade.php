{{-- The order's COD changed after the courier was booked: the courier still has
     the old amount. Someone updates it by hand in the courier's panel, then
     presses the button (the handover stays blocked until then).
     <x-cod-update :order="$order" /> --}}
@props(['order'])

@php
    $pending = app(\App\Services\Orders\OrderService::class)->pendingCodUpdate($order);
@endphp

@if ($pending)
    <div {{ $attributes->merge(['class' => 'mb-3 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900']) }}>
        <p class="font-semibold">{{ __('Update the COD at :c by hand', ['c' => $pending['courier']]) }}</p>
        <p class="mt-0.5">
            {{ __('COD changed ৳:a → ৳:b after booking. Open the :c panel, find CN :cn and change the COD to ৳:b. Then press the button. Until then the parcel cannot be handed over.', [
                'a' => number_format($pending['booked']), 'b' => number_format($pending['now']), 'c' => $pending['courier'], 'cn' => $pending['cn'] ?? '-',
            ]) }}
        </p>
        @can('orders.edit')
            <form method="POST" action="{{ route('orders.cod-updated', $order) }}" class="mt-2" id="cod-updated-{{ $order->id }}">
                @csrf
                <button type="button" class="lockable rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white hover:bg-red-700"
                    @click="$dispatch('open-confirm', { id: 'cod-updated-confirm-{{ $order->id }}', form: 'cod-updated-{{ $order->id }}', label: @js($order->order_no),
                        verb: @js(__('Yes, updated')), message: @js(__('You changed the COD to ৳:b in the :c panel?', ['b' => number_format($pending['now']), 'c' => $pending['courier']])), danger: false })">
                    {{ __('COD updated at :c', ['c' => $pending['courier']]) }}
                </button>
            </form>
            <x-confirm-modal :id="'cod-updated-confirm-'.$order->id" :verb="__('Yes, updated')" :danger="false" />
        @endcan
    </div>
@endif
