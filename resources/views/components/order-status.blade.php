{{-- Status chip in the status's own colour (from the database), plus the
     repack / edited mark after packaging. --}}
@props(['order', 'statuses' => null])

@php
    $map = $statuses ?? \App\Models\OrderStatus::map();
    $s = $map[$order->status_id] ?? null;
    $mark = $order->packMark();
@endphp

<span class="inline-flex flex-wrap items-center gap-1">
    @if ($s)<x-badge :color="$s['color']">{{ __($s['name']) }}</x-badge>@endif
    @if ($mark === 'repack')<x-badge color="red">{{ __('Repack') }}</x-badge>@endif
    @if ($mark === 'edited')<x-badge color="purple">{{ __('Edited') }}</x-badge>@endif
    @if ($order->is_duplicate_flag)
        {{-- Same items to the same address = most likely a real duplicate (red); anything else is worth a look (amber). --}}
        @php $twinSame = ($order->duplicate_items ?? null) === 'same' && ($order->duplicate_address ?? null) === 'same'; @endphp
        <x-badge :color="$twinSame ? 'red' : 'amber'">{{ __('Possible duplicate') }}</x-badge>
        @if ($order->duplicate_of_no ?? null)
            <span class="text-xs text-gray-600">{{ __('of :no: :m', ['no' => $order->duplicate_of_no, 'm' => \App\Services\Orders\DuplicateCheck::label($order->duplicate_items, $order->duplicate_address)]) }}</span>
        @endif
    @endif
</span>
