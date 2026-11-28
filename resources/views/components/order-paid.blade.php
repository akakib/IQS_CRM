{{-- Money the customer already paid on an order (advance, gateway or TrxID), one line each,
     so the person calling sees the TrxID without opening the order page.
     Verified: grey. Waiting for the check: amber. Rejected ones are left out.
     <x-order-paid :payments="$paid" /> --}}
@props(['payments'])
@foreach ($payments as $p)
    @php $waiting = $p->status !== 'verified'; @endphp
    <div x-data="{ copied: false }" {{ $attributes->merge(['class' => 'mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg px-3 py-2 text-xs '.($waiting ? 'bg-amber-50 text-amber-900' : 'bg-gray-50 text-gray-700')]) }}>
        <span>{{ __('Paid') }} <b class="tabular-nums">৳{{ number_format((float) $p->amount) }}</b> · {{ $p->method }}</span>
        @if ($p->transaction_id)
            <span>· {{ __('TrxID') }} <b class="font-mono">{{ $p->transaction_id }}</b></span>
            <button type="button" @click="navigator.clipboard?.writeText(@js($p->transaction_id)).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                class="font-medium text-primary hover:underline" x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</button>
        @endif
        <span @class(['text-green-700' => ! $waiting, 'font-medium' => $waiting])>· {{ $waiting ? __('waiting for check') : __('verified').' ✓' }}</span>
    </div>
@endforeach
