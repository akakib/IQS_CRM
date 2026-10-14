{{-- The courier has a different COD than the order (changed in the courier's panel, not in IQS).
     The handover stays blocked until a manager checks it and presses the button.
     <x-courier-mismatch :order="$order" /> --}}
@props(['order'])

@if ($order->courier_mismatch_at)
    <div {{ $attributes->merge(['class' => 'mb-3 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900']) }}>
        <p class="font-semibold">{{ __('The courier has a different COD') }}</p>
        <p class="mt-0.5">{{ __('Steadfast says ৳:c, the order says ৳:o (:t). Someone changed the parcel outside IQS. Edit the order to match what the customer wants, then press Checked. Until then the parcel cannot be handed over.', [
            'c' => number_format((float) $order->courier_mismatch_cod), 'o' => number_format((float) $order->cod_amount), 't' => $order->courier_mismatch_at->format('d M, g:i A'),
        ]) }}</p>
        @can('orders.approve')
            <form method="POST" action="{{ route('orders.courier-mismatch.clear', $order) }}" class="mt-2" id="mismatch-{{ $order->id }}">
                @csrf
                <button type="button" class="lockable rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white hover:bg-red-700"
                    @click="$dispatch('open-confirm', { id: 'mismatch-confirm-{{ $order->id }}', form: 'mismatch-{{ $order->id }}', label: @js($order->order_no), danger: false })">{{ __('Checked') }}</button>
            </form>
            <x-confirm-modal :id="'mismatch-confirm-'.$order->id" :verb="__('Checked')" :danger="false" :message="__('The order and the courier now agree on what goes to the customer and the COD?')" />
        @else
            <p class="mt-1 text-xs">{{ __('A manager checks it.') }}</p>
        @endcan
    </div>
@endif
