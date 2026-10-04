{{-- Loading placeholder. <x-skeleton lines="3" /> --}}
@props(['lines' => 3])

<div {{ $attributes->merge(['class' => 'animate-pulse space-y-2']) }} aria-hidden="true">
    @for ($i = 0; $i < (int) $lines; $i++)
        <div class="h-3 rounded bg-gray-200" style="width: {{ [100, 85, 70, 92, 60][$i % 5] }}%"></div>
    @endfor
</div>
