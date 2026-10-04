{{-- Status chip. color = green | red | amber | blue | gray | purple, or a hex
     colour stored in the database (statuses keep their own colour). --}}
@props(['color' => 'gray'])

@php
    $named = [
        'green' => 'bg-green-50 text-green-800',
        'red' => 'bg-red-50 text-red-700',
        'amber' => 'bg-amber-50 text-amber-800',
        'blue' => 'bg-blue-50 text-blue-700',
        'purple' => 'bg-purple-50 text-purple-700',
        'gray' => 'bg-gray-100 text-gray-600',
    ];
    $isHex = (bool) preg_match('/^#[0-9a-fA-F]{6}$/', (string) $color);
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium '.($isHex ? '' : ($named[$color] ?? $named['gray']))]) }}
    @if ($isHex) style="background-color: {{ $color }}1a; color: {{ $color }};" @endif>{{ $slot }}</span>
