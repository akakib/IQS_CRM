{{-- Steadfast history in one short line: "D 97% · C 2% · 25+ parcels".
     D = delivered (green), C = cancelled (red), parcels = Steadfast's volume range.
     <x-steadfast-line :provider="$p" /> --}}
@props(['provider'])

@php
    $d = $provider['detail'] ?? [];
    $rate = $provider['success_rate'];
    $parcels = ! empty($d['volume_range']) ? $d['volume_range'] : $provider['total_parcels'];
@endphp

<span {{ $attributes->merge(['class' => 'tabular-nums']) }}>
    @if ($rate === null && ! $provider['total_parcels'])
        {{ __('no history') }}
    @else
        <b class="text-green-700">D {{ $rate + 0 }}%</b>
        @isset($d['cancellation_ratio']) · <b class="text-red-600">C {{ $d['cancellation_ratio'] + 0 }}%</b>@endisset
        · {{ __(':n parcels', ['n' => $parcels]) }}
    @endif
</span>
