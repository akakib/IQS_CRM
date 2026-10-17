{{-- A booked parcel in the courier's own words: its status as Steadfast names it (Pending, Hold, Delivered...),
     what that means, when it last changed, and the tracking link to copy for the customer or open.
     IQS moves the order itself when the word is clear (delivered, partly delivered, cancelled; hold and the
     approvals open a delivery issue): CourierUpdateProcessor. Nothing before booking.
     <x-parcel-track :order="$order" />   one query (the active parcel)   flat: no card around it --}}
@props(['order', 'flat' => false])

@php
    $s = $order->active_shipment_id
        ? \Illuminate\Support\Facades\DB::table('shipments')->where('id', $order->active_shipment_id)->first(['consignment_id', 'courier', 'courier_status', 'tracking_link', 'updated_at'])
        : null;
    $status = $s->courier_status ?? null;
    $means = match ($status) {
        'in_review' => __('Booked. The courier is checking it.'),
        'pending' => __('Accepted by the courier: waiting for pickup or on the way.'),
        'hold' => __('On hold at the courier. A delivery issue is open for it.'),
        'delivered_approval_pending' => __('Delivered, waiting for the courier to approve it.'),
        'partial_delivered_approval_pending' => __('Partly delivered, waiting for the courier to approve it.'),
        'cancelled_approval_pending' => __('Cancelled, waiting for the courier to approve it.'),
        'unknown_approval_pending', 'unknown' => __('The courier does not say where it is. A delivery issue is open for it.'),
        'delivered' => __('Delivered to the customer.'),
        'partial_delivered' => __('Partly delivered.'),
        'cancelled' => __('Cancelled: coming back to us.'),
        default => __('Not known yet.'),
    };
    $link = $s && is_string($s->tracking_link) && str_starts_with($s->tracking_link, 'https://') ? $s->tracking_link : null;
@endphp

@if ($s)
    <div {{ $attributes->class([$flat ? '' : 'rounded-xl border border-gray-200 bg-white p-4']) }}>
        <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
            <span class="font-semibold text-gray-900">{{ __('Parcel') }} <span class="font-mono font-normal text-gray-600">{{ __('CN') }} {{ $s->consignment_id ?? '-' }}</span></span>
            <span class="text-xs text-gray-500">{{ __('last change :t', ['t' => \Illuminate\Support\Carbon::parse($s->updated_at)->format('d M, g:i A')]) }}</span>
        </div>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <x-courier-word :status="$status ?: 'unknown'" :courier="$s->courier" class="!px-3 !py-1 !text-sm" />
            <span class="text-sm text-gray-600">{{ $means }}</span>
        </div>

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
