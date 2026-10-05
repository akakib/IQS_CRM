{{-- <x-button>Save</x-button>  <x-button variant="secondary" href="…">Cancel</x-button>
     variant = primary (dark green) | secondary | danger | danger-outline | ghost; size = md | sm --}}
@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'type' => 'submit'])

@php
    $classes = 'inline-flex items-center justify-center gap-1.5 rounded-lg font-medium transition disabled:cursor-not-allowed disabled:opacity-60 '
        .($size === 'sm' ? 'px-3 py-1.5 text-xs ' : 'px-4 py-2 text-sm ')
        .match ($variant) {
            'secondary' => 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50',
            'danger' => 'bg-red-600 text-white hover:bg-red-700',
            'danger-outline' => 'border border-red-200 bg-white text-red-600 hover:bg-red-50',
            'ghost' => 'text-primary hover:bg-primary-soft',
            default => 'bg-primary text-white hover:bg-primary-dark',
        };
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
