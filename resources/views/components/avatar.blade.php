{{-- A person's small round picture: their photo, else the first letter of the name.
     <x-avatar :name="$u->name" :photo="$u->photo_path" />   size: xs (20px) sm (24px) md (36px) --}}
@props(['name' => null, 'photo' => null, 'size' => 'sm'])

@php
    $box = ['xs' => 'h-5 w-5 text-[10px]', 'sm' => 'h-6 w-6 text-[11px]', 'md' => 'h-9 w-9 text-sm', 'lg' => 'h-16 w-16 text-xl'][$size] ?? 'h-6 w-6 text-[11px]';
@endphp

@if ($photo)
    <img src="{{ asset($photo) }}" alt="{{ $name }}" {{ $attributes->merge(['class' => "$box shrink-0 rounded-full object-cover"]) }}>
@else
    <span {{ $attributes->merge(['class' => "$box flex shrink-0 items-center justify-center rounded-full bg-primary font-semibold text-white"]) }} aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $name, 0, 1)) ?: '?' }}</span>
@endif
