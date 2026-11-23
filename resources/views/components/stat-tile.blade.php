{{-- Dashboard number. <x-stat-tile :label="__('Orders today')" value="128" hint="+12 vs yesterday" trend="up" /> --}}
@props(['label', 'value', 'hint' => null, 'trend' => null, 'href' => null, 'soft' => false])

@php($tag = $href ? 'a' : 'div')
<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'block rounded-xl border p-4'.($soft ? ' border-transparent bg-gray-50' : ' border-gray-200 bg-white').($href ? ' hover:border-primary' : '')]) }}>
    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $label }}</p>
    <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-800">{{ $value }}</p>
    @if ($hint)
        <p @class(['mt-1 text-xs', 'text-green-800' => $trend === 'up', 'text-red-600' => $trend === 'down', 'text-gray-500' => ! $trend])>{{ $hint }}</p>
    @endif
</{{ $tag }}>
