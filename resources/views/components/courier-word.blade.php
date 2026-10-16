{{-- What the courier last said about a booked parcel, in the courier's own words, beside IQS's own status:
     "Steadfast: Unknown". Grey while normal, amber or red when stuck, green / red at the end. Nothing before booking.
     <x-courier-word :status="$o->courier_status" :courier="$o->courier" /> --}}
@props(['status' => null, 'courier' => null])

@if ($status)
    @php
        $color = match ($status) {
            'delivered' => 'bg-green-50 text-green-800',
            'partial_delivered', 'hold', 'delivered_approval_pending', 'partial_delivered_approval_pending', 'cancelled_approval_pending' => 'bg-amber-50 text-amber-800',
            'cancelled', 'unknown', 'unknown_approval_pending' => 'bg-red-50 text-red-700',
            default => 'bg-gray-100 text-gray-600',
        };
    @endphp
    <span {{ $attributes->merge(['class' => 'inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium '.$color]) }} title="{{ __('What the courier says now') }}">{{ ucfirst((string) ($courier ?: __('Courier'))) }}: {{ ucfirst(str_replace('_', ' ', $status)) }}</span>
@endif
