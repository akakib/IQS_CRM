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
    @if ($order->is_duplicate_flag)<x-badge color="amber">{{ __('Possible duplicate') }}</x-badge>@endif
</span>
