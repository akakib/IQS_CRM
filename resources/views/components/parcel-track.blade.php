{{-- Where a booked parcel is: Booked → With courier → Delivered (or Partly delivered / Returned), anything stuck
     in amber, and the courier's tracking link to copy for the customer or open. Nothing before booking.
     <x-parcel-track :order="$order" />   one query (the active parcel)   flat: no card around it --}}
@props(['order', 'flat' => false])

@php
    $s = $order->active_shipment_id
        ? \Illuminate\Support\Facades\DB::table('shipments')->where('id', $order->active_shipment_id)->first(['consignment_id', 'courier', 'courier_status', 'tracking_link', 'updated_at'])
        : null;
    $status = $s->courier_status ?? null;
    // Steadfast says in_review when booked, pending (or in_transit) while it has the parcel, then the end.
    $step = match ($status) {
        'delivered', 'partial_delivered', 'cancelled' => 2,
        null, '', 'in_review' => 0,
        default => 1,
    };
    $last = match ($status) {
        'partial_delivered' => __('Partly delivered'), 'cancelled' => __('Returned'), default => __('Delivered'),
    };
    $steps = [__('Booked'), __('With courier'), $last];
    $endColor = $status === 'cancelled' ? 'bg-red-600' : ($status === 'partial_delivered' ? 'bg-amber-500' : 'bg-green-600');
    $stuck = in_array($status, ['hold', 'delivered_approval_pending', 'partial_delivered_approval_pending', 'cancelled_approval_pending', 'unknown_approval_pending', 'unknown'], true);
    $link = $s && is_string($s->tracking_link) && str_starts_with($s->tracking_link, 'https://') ? $s->tracking_link : null;
@endphp

@if ($s)
    <div {{ $attributes->class([$flat ? '' : 'rounded-xl border border-gray-200 bg-white p-4']) }}>
        <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
            <span class="font-semibold text-gray-900">{{ __('Parcel') }} <span class="font-mono font-normal text-gray-600">{{ __('CN') }} {{ $s->consignment_id ?? '-' }}</span></span>
            <span class="text-xs text-gray-500">{{ ucfirst($s->courier) }} · {{ __('last change :t', ['t' => \Illuminate\Support\Carbon::parse($s->updated_at)->format('d M, g:i A')]) }}</span>
        </div>

        <ol class="mt-3 grid grid-cols-3 gap-2" aria-label="{{ __('Parcel steps') }}">
            @foreach ($steps as $i => $label)
                @php($done = $i <= $step)
                <li @if ($i === $step) aria-current="step" @endif>
                    <span @class(['block h-1.5 rounded-full', $i === 2 && $done ? $endColor : ($done ? 'bg-primary' : 'bg-gray-200')])></span>
                    <span @class(['mt-1.5 block text-xs', $done ? 'font-semibold text-gray-900' : 'text-gray-400'])>{{ $label }}</span>
                </li>
            @endforeach
        </ol>

        @if ($stuck)
            <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm font-medium text-amber-900">{{ \App\Support\Labels::courierStatus($status) }}</p>
        @endif

        @if ($link)
            <div class="mt-3 flex flex-wrap gap-2" x-data="{ copied: false }">
                <button type="button" @click="navigator.clipboard?.writeText(@js($link)).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                    class="inline-flex min-h-8 items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    x-text="copied ? @js(__('Copied')) : @js(__('Copy tracking link'))">{{ __('Copy tracking link') }}</button>
                <a href="{{ $link }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-8 items-center rounded-lg border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Open') }}</a>
            </div>
        @endif
    </div>
@endif
